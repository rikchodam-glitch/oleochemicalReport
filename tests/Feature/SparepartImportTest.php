<?php

use App\Models\SpBatch;
use App\Models\SpIntegrasi;
use App\Services\Sparepart\SparepartImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

// ============================================================
// INTEGRASI PR/PO SPARE PART (modul sparepart, pengujian)
// ============================================================

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparepart-uji-*') ?: [] as $folder) {
        foreach (glob($folder . DIRECTORY_SEPARATOR . '*') ?: [] as $berkas) {
            @unlink($berkas);
        }

        @rmdir($folder);
    }
});

/**
 * Menulis satu berkas XLSX sederhana (baris judul + baris data).
 *
 * @param  string                       $path  Path berkas tujuan
 * @param  array<int, mixed>            $judul Baris judul
 * @param  array<int, array<int, mixed>> $baris Baris data
 * @return void
 */
function spUjiTulisXlsx(string $path, array $judul, array $baris): void
{
    $buku = new Spreadsheet();
    $sheet = $buku->getActiveSheet();
    $sheet->fromArray($judul, null, 'A1');
    $sheet->fromArray($baris, null, 'A2');
    (new Xlsx($buku))->save($path);
    $buku->disconnectWorksheets();
}

/**
 * Membuat folder periode sementara berisi lima berkas sumber.
 *
 * Empat material dipakai supaya menutup KEEMPAT nilai kolom aksi:
 * 30000001 PR terbuka tanpa PO        -> BUAT PO
 * 30000002 PR terbuka + PO terbuka    -> KEJAR KEDATANGAN (PO OPEN)
 * 30000003 PR tertutup tanpa PO open  -> CEK / BUAT PR BARU
 * 30000004 tanpa PR maupun PO         -> BUAT PR
 *
 * @param  array<string, string> $ubah Isi berkas pengganti (kunci: monitoring, g001, mrp)
 * @return string                      Path folder periode
 */
function spUjiFolder(array $ubah = []): string
{
    $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparepart-uji-' . bin2hex(random_bytes(4));

    if (! is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    $monitoring = $ubah['monitoring'] ?? "Kode Material,Deskripsi,Stok Minimum,Stok Tersedia,Status,Penanda\n"
        . "30000001,BRACKET UJI,1,0,REORDER,\n"
        . "30000002,SEAL UJI,1,5,REORDER,\n"
        . "30000003,BEARING UJI,2,0,REORDER,\n"
        . "30000004,V-BELT UJI,3,1,REORDER,\n";

    file_put_contents($folder . DIRECTORY_SEPARATOR . 'SparePart_Monitoring.csv', $monitoring);

    $g001 = $ubah['g001'] ?? "S.Loc,Kode,Nama Item,Stok Awal,Masuk,Keluar,Sisa Stok,UN\n"
        . "A1.1,30000003,BEARING UJI,5,2,1,6,PCS\n"
        . "A1.2,30000003,BEARING UJI,1,0,0,1,PCS\n"
        . "C1.3,30000002,AIR FILTER,1,0,0,1,PCS\n";

    file_put_contents($folder . DIRECTORY_SEPARATOR . 'G001_clean.csv', $g001);

    spUjiTulisXlsx(
        $folder . DIRECTORY_SEPARATOR . 'EXPORT PR.XLSX',
        [
            'Item', 'Processing status', 'Deletion Indicator', 'Purchase order', 'Material',
            'Quantity requested', 'Delivery Date', 'Requisitioner', 'Purchasing Group',
        ],
        [
            ['Purchase Requisition 1000000001', '', '', '', '', '', '', '', ''],
            ['10', 'N', '', '', '30000001', 5, '2026-10-01', 'PPIC', 'A01'],
            ['Purchase Requisition 1000000002', '', '', '', '', '', '', '', ''],
            ['10', 'N', '', '4500000001', '30000002', 2, '2026-10-02', 'PPIC', 'A02'],
            ['Purchase Requisition 1000000003', '', '', '', '', '', '', '', ''],
            ['10', 'B', '', '4500000002', '30000003', 3, '2026-09-02', 'STORE', 'A03'],
        ]
    );

    spUjiTulisXlsx(
        $folder . DIRECTORY_SEPARATOR . 'EXPORT PO.XLSX',
        [
            'Purchasing Document', 'Material', 'Supplier/Supplying Plant', 'Order Quantity',
            'Deletion Indicator', 'Our Reference', 'Document Date', 'Plant', 'Still to be delivered',
        ],
        [
            ['4500000001', '30000002', 'VENDOR UJI', 10, '', 'DAVD', '2026-10-02', '7011', 10],
            ['4500000002', '30000003', 'VENDOR UJI', 3, '', 'DAVD2', '2026-09-02', '7011', 0],
        ]
    );

    if (isset($ubah['mrpRusak'])) {
        file_put_contents($folder . DIRECTORY_SEPARATOR . 'EXPORT MRP.XLSX', 'bukan berkas excel');
    } else {
        spUjiTulisXlsx(
            $folder . DIRECTORY_SEPARATOR . 'EXPORT MRP.XLSX',
            ['Material', 'Plnt stock', 'Safety Stk', 'Reorder Pt'],
            [
                ['30000001', 5, 2, 1],
                ['30000002', 5, 2, 5],
                ['30000003', 6, 2, 2],
            ]
        );
    }

    return $folder;
}

test('impor menyimpan baris mentah dan ringkasan hasil hitung', function () {
    $folder = spUjiFolder();

    $batch = app(SparepartImportService::class)->import($folder, 'Uji Okt 26');

    expect($batch->status)->toBe('selesai')
        ->and($batch->jumlah_monitoring)->toBe(4)
        ->and($batch->jumlah_g001)->toBe(3)
        ->and($batch->jumlah_pr)->toBe(3)
        ->and($batch->jumlah_po)->toBe(2)
        ->and($batch->jumlah_mrp)->toBe(3)
        ->and($batch->jumlah_hasil)->toBe(4)
        ->and($batch->selesai_at)->not->toBeNull();

    expect(DB::table('sp_monitoring')->count())->toBe(4)
        ->and(DB::table('sp_g001')->count())->toBe(2)
        ->and(DB::table('sp_pr')->count())->toBe(3)
        ->and(DB::table('sp_po')->count())->toBe(2)
        ->and(DB::table('sp_mrp')->count())->toBe(3);

    expect($batch->ringkasan['aksi'])->toBe([
        SpIntegrasi::AKSI_KEJAR   => 1,
        SpIntegrasi::AKSI_BUAT_PO => 1,
        SpIntegrasi::AKSI_CEK     => 1,
        SpIntegrasi::AKSI_BUAT_PR => 1,
    ]);

    expect($batch->ringkasan['stok'])->toBe(['total' => 4, 'aman' => 0, 'reorder' => 4])
        ->and($batch->ringkasan['min'])->toBe(['sama' => 2, 'beda' => 1, 'tanpa_mrp' => 1])
        ->and($batch->ringkasan['material']['pr_irisan'])->toBe(3)
        ->and($batch->ringkasan['material']['po_irisan'])->toBe(2);
});

test('pemilihan dokumen PR/PO dan kolom aksi tiap material benar', function () {
    app(SparepartImportService::class)->import(spUjiFolder(), 'Uji Okt 26');

    $kejar = SpIntegrasi::where('kode_material', '30000002')->firstOrFail();

    expect($kejar->aksi)->toBe(SpIntegrasi::AKSI_KEJAR)
        ->and($kejar->po_no)->toBe('4500000001')
        ->and((float) $kejar->po_sisa)->toBe(10.0)
        ->and($kejar->po_open)->toBe(1)
        ->and($kejar->po_closed)->toBe(0)
        ->and($kejar->po_uniq)->toBe(1)
        ->and($kejar->po_line)->toBe(1)
        ->and((float) $kejar->po_sisa_total)->toBe(10.0)
        ->and($kejar->po_tanggal?->format('Y-m-d'))->toBe('2026-10-02')
        ->and($kejar->po_tanggal_teks)->toBe('02-OCT-2026')
        ->and($kejar->pr_no)->toBe('1000000002')
        ->and($kejar->pr_status)->toBe('Terbuka 1 / Closed 0')
        ->and($kejar->pr_status_aksi)->toBe('Terbuka (belum PO)')
        ->and((float) $kejar->pr_qty)->toBe(2.0)
        ->and($kejar->purch)->toBe('DAVD')
        ->and($kejar->vendor)->toBe('VENDOR UJI')
        ->and($kejar->purch_last)->toBe('DAVD')
        ->and($kejar->vendor_last)->toBe('VENDOR UJI')
        ->and($kejar->min_cmp)->toBe('Beda')
        ->and($kejar->ket)->toBe('Sudah ada PR/PO')
        ->and($kejar->g_baris)->toBe(1)
        ->and((float) $kejar->g_sisa)->toBe(1.0)
        ->and($kejar->g_satuan)->toBe('PCS')
        ->and($kejar->g_lokasi)->toBe('C1.3')
        ->and((float) $kejar->mrp_reorder)->toBe(5.0);

    $buatPo = SpIntegrasi::where('kode_material', '30000001')->firstOrFail();

    expect($buatPo->aksi)->toBe(SpIntegrasi::AKSI_BUAT_PO)
        ->and($buatPo->po_no)->toBeNull()
        ->and($buatPo->pr_no)->toBe('1000000001')
        ->and($buatPo->pr_status)->toBe('Terbuka 1 / Closed 0')
        ->and($buatPo->purch)->toBe('A01')
        ->and($buatPo->req_list)->toBe('PPIC')
        ->and($buatPo->min_cmp)->toBe('Sama')
        ->and($buatPo->ket)->toBe('Sudah ada PR/PO');

    $cek = SpIntegrasi::where('kode_material', '30000003')->firstOrFail();

    expect($cek->aksi)->toBe(SpIntegrasi::AKSI_CEK)
        ->and($cek->po_no)->toBe('4500000002')
        ->and($cek->po_open)->toBe(0)
        ->and($cek->po_closed)->toBe(1)
        ->and($cek->pr_status)->toBe('Terbuka 0 / Closed 1')
        ->and($cek->pr_status_aksi)->toBe('Sudah jadi PO')
        ->and($cek->purch)->toBe('DAVD2')
        ->and($cek->g_baris)->toBe(2)
        ->and((float) $cek->g_sisa)->toBe(7.0)
        ->and($cek->g_lokasi)->toBe('A1.1; A1.2')
        ->and($cek->min_cmp)->toBe('Sama');

    $buatPr = SpIntegrasi::where('kode_material', '30000004')->firstOrFail();

    expect($buatPr->aksi)->toBe(SpIntegrasi::AKSI_BUAT_PR)
        ->and($buatPr->po_no)->toBeNull()
        ->and($buatPr->pr_no)->toBeNull()
        ->and($buatPr->pr_line)->toBe(0)
        ->and($buatPr->pr_qty)->toBeNull()
        ->and($buatPr->min_cmp)->toBe('-')
        ->and($buatPr->ket)->toBe('REORDER - belum ada PR/PO')
        ->and($buatPr->g_baris)->toBe(0)
        ->and($buatPr->g_awal)->toBeNull();
});

test('impor ulang periode yang sama mengganti batch lama tanpa menggandakan baris', function () {
    $folder = spUjiFolder();
    $layanan = app(SparepartImportService::class);

    $pertama = $layanan->import($folder, 'Uji Okt 26');
    $kedua = $layanan->import($folder, 'Uji Okt 26');

    expect($kedua->id)->not->toBe($pertama->id)
        ->and(SpBatch::count())->toBe(1)
        ->and(SpBatch::where('periode', 'Uji Okt 26')->count())->toBe(1)
        ->and(DB::table('sp_integrasi')->count())->toBe(4)
        ->and(DB::table('sp_monitoring')->count())->toBe(4)
        ->and(DB::table('sp_pr')->count())->toBe(3)
        ->and(DB::table('sp_integrasi')->where('batch_id', $pertama->id)->count())->toBe(0);
});

test('folder periode yang tidak ada menolak impor tanpa membuat batch', function () {
    $folder = spUjiFolder() . '-tidak-ada';

    expect(fn () => app(SparepartImportService::class)->import($folder, 'Uji Okt 26'))
        ->toThrow(RuntimeException::class);

    expect(SpBatch::count())->toBe(0);
});

test('berkas sumber rusak membuat batch gagal dan tidak menyisakan baris mentah', function () {
    $folder = spUjiFolder(['mrpRusak' => '1']);

    $galat = null;

    try {
        app(SparepartImportService::class)->import($folder, 'Uji Okt 26');
    } catch (Throwable $e) {
        $galat = $e;
    }

    expect($galat)->not->toBeNull()
        ->and($galat->getMessage())->not->toBe('');

    $batch = SpBatch::where('periode', 'Uji Okt 26')->firstOrFail();

    expect($batch->status)->toBe('gagal')
        ->and($batch->pesan)->not->toBeNull()
        ->and(DB::table('sp_monitoring')->count())->toBe(0)
        ->and(DB::table('sp_pr')->count())->toBe(0)
        ->and(DB::table('sp_integrasi')->count())->toBe(0);
});

test('perintah sparepart:import menjalankan impor satu folder periode', function () {
    $folder = spUjiFolder();

    $this->artisan('sparepart:import', ['folder' => $folder])
        ->expectsOutputToContain('Batch #')
        ->assertExitCode(0);

    expect(SpBatch::where('periode', basename($folder))->value('status'))->toBe('selesai');
});

test('perintah sparepart:import gagal dengan kode keluar 1 bila folder tidak ada', function () {
    $this->artisan('sparepart:import', ['folder' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tidak-ada-sparepart'])
        ->assertExitCode(1);

    expect(SpBatch::count())->toBe(0);
});

test('perintah sparepart:import menolak opsi oleh yang bukan angka', function () {
    $folder = spUjiFolder();

    $this->artisan('sparepart:import', ['folder' => $folder, '--oleh' => 'bukan-angka'])
        ->assertExitCode(1);

    expect(SpBatch::count())->toBe(0);
});
