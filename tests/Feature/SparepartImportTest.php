<?php

use App\Exceptions\SparepartFormatException;
use App\Models\SpImpor;
use App\Models\SpMaterial;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ============================================================
// MODUL SPARE PART: IMPORT KEADAAN MATERIAL (upsert per kode)
// ============================================================

/**
 * Menyiapkan daftar pantau dari berkas fixture lalu mengembalikan path berkas.
 *
 * @return array<string, string> Path berkas fixture (stok, pr, po, pantau)
 */
function spSiapkan(): array
{
    $berkas = spUjiBerkas();
    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);

    return $berkas;
}

/**
 * Nilai satu kolom keadaan material.
 *
 * @param  string $kode  Kode material
 * @param  string $kolom Nama kolom
 * @return mixed         Nilai kolom (null bila barisnya tidak ada)
 */
function spNilai(string $kode, string $kolom): mixed
{
    return SpMaterial::query()->where('kode_material', $kode)->value($kolom);
}

test('import mengisi keadaan material sesuai aturan stok dan kolom aksi', function () {
    $berkas = spSiapkan();

    $impor = app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
        SparepartImportService::SUMBER_MRP  => $berkas['mrp'],
    ], null, 'Uji lengkap');

    expect($impor->status)->toBe(SpImpor::STATUS_SELESAI)
        ->and(SpMaterial::query()->count())->toBe(5);

    // PO terbuka -> KEJAR KEDATANGAN, stok 0 -> REORDER.
    expect((float) spNilai('30000002', 'stok_tersedia'))->toBe(0.0)
        ->and((bool) spNilai('30000002', 'reorder'))->toBeTrue()
        ->and(spNilai('30000002', 'aksi'))->toBe(SpMaterial::AKSI_KEJAR)
        ->and(spNilai('30000002', 'po_no'))->toBe('4500000001')
        ->and((int) spNilai('30000002', 'po_open'))->toBe(1)
        ->and(spNilai('30000002', 'vendor'))->toBe('VENDOR UJI');

    // Stok awal 5 + barang masuk 2 = 7 -> aman; PR tertutup -> CEK.
    expect((float) spNilai('30000003', 'stok_tersedia'))->toBe(7.0)
        ->and((bool) spNilai('30000003', 'reorder'))->toBeFalse()
        ->and((float) spNilai('30000003', 'g_masuk'))->toBe(2.0)
        ->and(spNilai('30000003', 'aksi'))->toBe(SpMaterial::AKSI_CEK);

    // Tanpa catatan stok -> dihitung seperti nol -> REORDER; PR terbuka -> BUAT PO.
    expect(spNilai('30000001', 'stok_tersedia'))->toBeNull()
        ->and((bool) spNilai('30000001', 'reorder'))->toBeTrue()
        ->and(spNilai('30000001', 'aksi'))->toBe(SpMaterial::AKSI_BUAT_PO)
        ->and((int) spNilai('30000001', 'pr_terbuka'))->toBe(1)
        ->and(spNilai('30000001', 'pr_status_aksi'))->toBe('Terbuka (belum PO)')
        ->and((string) spNilai('30000001', 'penanda'))->toContain('tanpa catatan stok gudang');

    expect(spNilai('30000004', 'aksi'))->toBe(SpMaterial::AKSI_BUAT_PR)
        ->and(spNilai('30000004', 'po_prev'))->toBe('4500000003')
        ->and((int) spNilai('30000004', 'po_uniq'))->toBe(1)
        ->and(spNilai('30000005', 'aksi'))->toBe(SpMaterial::AKSI_BUAT_PR)
        ->and((float) spNilai('30000005', 'stok_tersedia'))->toBe(2.0);
});

test('import sebagian mempertahankan nilai sumber yang tidak diunggah', function () {
    $berkas  = spSiapkan();
    $layanan = app(SparepartImportService::class);

    $layanan->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
        SparepartImportService::SUMBER_MRP  => $berkas['mrp'],
    ]);

    // Berkas PO baru: sisa kirim PO 4500000001 berubah dari 5 menjadi 9.
    $poBaru = spUjiXlsx('po-uji-baru.xlsx', [
        'Sheet1' => [
            ['Document Date', 'Purchasing Document', 'Supplier/Supplying Plant', 'Material', 'Order Quantity', 'Deletion Indicator', 'Still to be delivered (qty)', 'Our Reference', 'Plant'],
            [46001, '4500000001', 'VENDOR UJI BARU', '30000002', 10, '', 9, 'DAVD', '7011'],
        ],
    ]);

    $impor = $layanan->import([SparepartImportService::SUMBER_PO => $poBaru]);

    expect((float) spNilai('30000002', 'po_sisa'))->toBe(9.0)
        ->and(spNilai('30000002', 'vendor'))->toBe('VENDOR UJI BARU')
        // Nilai stok dan PR dari import sebelumnya tetap dipakai.
        ->and((float) spNilai('30000002', 'stok_tersedia'))->toBe(0.0)
        ->and(spNilai('30000001', 'pr_no'))->toBe('1000000001')
        ->and((int) spNilai('30000005', 'po_line'))->toBe(0)
        ->and((int) ($impor->analisa['sumber'][SparepartImportService::SUMBER_STOK]['dipakai'] ?? 1))->toBe(0);
});

test('berkas tanpa lembar stok gudang ditolak tanpa menulis data', function () {
    $berkas = spUjiBerkas();

    expect(fn () => app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['pr'],
    ]))->toThrow(SparepartFormatException::class);

    expect(SpMaterial::query()->count())->toBe(0)
        ->and(SpImpor::query()->count())->toBe(0);
});

test('berkas dengan judul kolom wajib hilang ditolak dan menyebut kolomnya', function () {
    $prRusak = spUjiXlsx('pr-rusak.xlsx', [
        'Sheet1' => [
            ['Item of requisition', 'Purchase Order', 'Deletion indicator', 'Material', 'Quantity requested', 'Delivery Date'],
            ['10', '4500000001', '', '30000002', 2, '05.10.2026'],
        ],
    ]);

    $hilang = [];

    try {
        app(SparepartImportService::class)->import([SparepartImportService::SUMBER_PR => $prRusak]);
    } catch (SparepartFormatException $e) {
        $hilang = $e->getHilang();
    }

    expect($hilang)->toContain('Processing status')
        ->and($hilang)->toContain('Purchase order')
        ->and($hilang)->toContain('Deletion Indicator');
});

test('analisa mencatat material di luar daftar pantau', function () {
    $berkas = spSiapkan();

    $impor = app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
        SparepartImportService::SUMBER_MRP  => $berkas['mrp'],
    ]);

    $analisa = (array) $impor->analisa;

    expect((int) $analisa['luar_pantau']['pr']['jumlah'])->toBe(1)
        ->and((array) $analisa['luar_pantau']['pr']['contoh'])->toContain('30009999')
        ->and((int) $analisa['luar_pantau']['po']['jumlah'])->toBe(1)
        ->and(SpMaterial::query()->where('kode_material', '30009999')->exists())->toBeFalse();
});

test('analisa mencatat material yang statusnya berubah menjadi REORDER', function () {
    $berkas  = spSiapkan();
    $layanan = app(SparepartImportService::class);

    $layanan->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
        SparepartImportService::SUMBER_MRP  => $berkas['mrp'],
    ]);

    // Berkas stok baru: BEARING UJI (30000003) habis, sebelumnya sisa 7.
    $stokBaru = spUjiXlsx('stok-uji-baru.xlsx', [
        'G001' => [
            ['S. Loc', 'Kode', 'Nama Item', 'Stok Awal', 'Masuk', 'Keluar', 'Sisa Stok', 'UN'],
            ['A1.2', '30000003', 'BEARING UJI', 0, 0, 0, 0, 'PCS'],
            ['A1.4', '30000005', 'GASKET UJI', 2, 0, 0, 2, 'PCS'],
        ],
        'MASUK 1' => [
            ['Tanggal', 'Kode', 'Nama Item', 'Qty', 'Uom', 'No. PO', 'Request by'],
        ],
        'KELUAR 1' => [
            ['Tanggal', 'Kode', 'Nama Item', 'Jumlah', 'Uom', 'User', 'MvT'],
        ],
    ]);

    $impor = $layanan->import([SparepartImportService::SUMBER_STOK => $stokBaru]);

    expect((array) $impor->analisa['status']['naik_reorder'])->toContain('30000003')
        ->and((bool) spNilai('30000003', 'reorder'))->toBeTrue()
        ->and((float) spNilai('30000003', 'stok_tersedia'))->toBe(0.0);
});

test('perintah sparepart:import menjalankan import dan mencatat riwayatnya', function () {
    $berkas = spSiapkan();

    $this->artisan('sparepart:import', [
        '--stok'  => $berkas['stok'],
        '--pr'    => $berkas['pr'],
        '--po'    => $berkas['po'],
        '--mrp'   => $berkas['mrp'],
        '--label' => 'Uji perintah',
    ])->assertExitCode(0);

    expect(SpImpor::query()->count())->toBe(1)
        ->and(SpImpor::query()->value('label'))->toBe('Uji perintah')
        ->and(DB::table('sp_material')->count())->toBe(5);
});

test('perintah sparepart:import melaporkan kegagalan bila tidak ada berkas', function () {
    $this->artisan('sparepart:import')->assertExitCode(1);

    expect(SpImpor::query()->count())->toBe(0);
});

test('import kedua memperbarui baris yang sama tanpa menambah baris baru', function () {
    $berkas  = spSiapkan();
    $layanan = app(SparepartImportService::class);

    $layanan->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
        SparepartImportService::SUMBER_MRP  => $berkas['mrp'],
    ]);

    $impor = $layanan->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
        SparepartImportService::SUMBER_MRP  => $berkas['mrp'],
    ]);

    expect(SpMaterial::query()->count())->toBe(5)
        ->and((int) ($impor->analisa['keadaan']['baru'] ?? -1))->toBe(0)
        ->and((int) ($impor->analisa['keadaan']['tetap'] ?? 0))->toBe(5);
});

