<?php

use App\Models\SpMaterial;
use App\Models\User;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

// ============================================================
// MODUL SPARE PART: SEMUA PR TERBUKA + IMPORT MRP
// ============================================================
// 19/10/2026: satu material bisa punya lebih dari satu PR baru. Dashboard
// harus menampilkan seluruh nomor PR (termasuk yang masih terbuka), bukan
// hanya satu nomor terpilih. Berkas EXPORT MRP juga harus bisa diimpor.

/**
 * Berkas PR dengan dua dokumen terbuka untuk material yang sama.
 *
 * @return string Path berkas
 */
function spMultiPrBerkas(): string
{
    return spUjiXlsx('pr-multi-uji.xlsx', [
        'Sheet1' => [
            ['Item of requisition', 'Purchase order', 'Processing status', 'Deletion Indicator', 'Material', 'Quantity requested', 'Delivery Date', 'Requisitioner', 'Purchasing Group'],
            ['Purchase Requisition 1000000011', '', '', '', '', '', '', '', ''],
            ['10', '', 'N', '', '30000001', 5, '05.10.2026', 'PPIC', 'A1'],
            ['Purchase Requisition 1000000012', '', '', '', '', '', '', '', ''],
            ['10', '', 'N', '', '30000001', 2, '06.10.2026', 'MTC', 'A1'],
            ['Purchase Requisition 1000000013', '', '', '', '', '', '', '', ''],
            ['10', '4500000002', 'B', '', '30000003', 3, '05.10.2026', 'STORE', 'A2'],
        ],
    ]);
}

/**
 * Berkas MRP (MD07) sederhana untuk dua material.
 *
 * @return string Path berkas
 */
function spMrpBerkas(): string
{
    return spUjiXlsx('mrp-uji.xlsx', [
        'Sheet1' => [
            ['Status', 'Valid from date', 'Material', 'MRP Area', 'Description', 'Accessed', 'Process.', 'Stock DS', '1st RDS', '2nd RDS', 'EM1', 'EM2', 'EM3', 'EM4', 'EM5', 'EM6', 'EM7', 'EM8', 'Plnt stock', 'Unit', 'Safety Stk', 'Reorder Pt', 'Matl type', 'Proc. Type', 'Spec.proc.', 'ABC', 'MRP Group', 'MRP Type', 'L-L code', 'Created', 'Seg.Status'],
            ['XOO', '', '30000001', '7011', 'BRACKET UJI', '', '', '', '', '', '', '', '', '', '', '', '', '', 4, 'PCS', 1, 1, 'Z5ER', 'F', '', '', '0000', 'VB', '999', '', 'X'],
            ['XOO', '', '30000002', '7011', 'SEAL UJI', '', '', '', '', '', '', '', '', '', '', '', '', '', 0, 'PCS', 1, 2, 'Z5ER', 'F', '', '', '0000', 'VB', '999', '', 'X'],
        ],
    ]);
}

test('semua nomor PR yang terbuka tersimpan dan tampil pada halaman', function () {
    $this->seed();

    $admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $berkas = spUjiBerkas();

    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);
    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => spMultiPrBerkas(),
        SparepartImportService::SUMBER_PO   => $berkas['po'],
        SparepartImportService::SUMBER_MRP  => spMrpBerkas(),
    ]);

    $material = SpMaterial::query()->where('kode_material', '30000001')->firstOrFail();
    $riwayat  = json_decode((string) $material->pr_history, true);

    expect($material->pr_line)->toBe(2)
        ->and($material->pr_terbuka)->toBe(2)
        ->and($material->pr_nums)->toContain('1000000011')
        ->and($material->pr_nums)->toContain('1000000012')
        ->and($material->pr_open_nums)->toContain('1000000011')
        ->and($material->pr_open_nums)->toContain('1000000012')
        ->and($material->aksi)->toBe(SpMaterial::AKSI_BUAT_PO)
        ->and($riwayat)->toHaveCount(2)
        ->and(collect($riwayat)->pluck('no_pr')->all())->toContain('1000000011')
        ->and(collect($riwayat)->pluck('no_pr')->all())->toContain('1000000012')
        ->and(collect($riwayat)->pluck('status')->unique()->all())->toContain('Terbuka (belum PO)');

    $this->actingAs($admin)->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertSee('1000000011')
        ->assertSee('1000000012')
        ->assertSee('History PR (EXPORT ME5A)')
        ->assertSee('Kejar Delivery')
        ->assertSee('Kejar PO');
});

test('berkas EXPORT MRP mengisi kolom MRP, stok minimum, dan pembanding stok gudang', function () {
    $this->seed();

    $admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $berkas = spUjiBerkas();

    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);
    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_MRP  => spMrpBerkas(),
    ]);

    $material = SpMaterial::query()->where('kode_material', '30000002')->firstOrFail();

    expect((float) $material->mrp_stock)->toBe(0.0)
        ->and((float) $material->mrp_safety)->toBe(1.0)
        ->and((float) $material->mrp_reorder)->toBe(2.0)
        ->and((float) $material->stok_min)->toBe(1.0)
        ->and((bool) $material->reorder)->toBeTrue()
        ->and($material->mrp_unit)->toBe('PCS')
        ->and($material->mrp_type)->toBe('VB')
        ->and($material->mrp_cmp)->toBe('Sama')
        ->and($material->mrp_diupdate_at)->not->toBeNull();

    $this->actingAs($admin)->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertSee('Stock by SAP')
        ->assertSee('Safety Stk (stok min)')
        ->assertSee('Reorder Pt')
        ->assertSee('Stok Sama');
});

test('halaman import menampilkan berkas MRP dan menerima unggahannya', function () {
    $this->seed();

    $admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $berkas = spUjiBerkas();

    $this->actingAs($admin)->get(route('sparepart.impor'))
        ->assertStatus(200)
        ->assertSee('EXPORT MRP (SAP MD07/MD04)')
        ->assertSee('Plnt stock');

    $this->actingAs($admin)
        ->post(route('sparepart.impor.proses'), [
            'stok'   => new UploadedFile($berkas['stok'], 'stok.xlsx', null, null, true),
            'mrp'    => new UploadedFile(spMrpBerkas(), 'mrp.xlsx', null, null, true),
            'pantau' => new UploadedFile($berkas['pantau'], 'pantau.xlsx', null, null, true),
            'label'  => 'Uji unggah MRP',
        ])
        ->assertRedirect(route('sparepart.riwayat', ['impor' => 1]));

    expect((int) \App\Models\SpImpor::query()->value('jumlah_mrp'))->toBe(2)
        ->and((float) SpMaterial::query()->where('kode_material', '30000002')->value('mrp_reorder'))->toBe(2.0);
});

test('export Excel memuat kolom MRP setelah kolom lama tanpa menggeser kolom lama', function () {
    $this->seed();

    $admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $berkas = spUjiBerkas();

    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);
    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_MRP  => spMrpBerkas(),
    ]);

    $response = $this->actingAs($admin)->get(route('sparepart.export'));
    $response->assertStatus(200);

    $path = spUjiFolder() . DIRECTORY_SEPARATOR . 'export-mrp.xlsx';
    file_put_contents($path, $response->streamedContent());

    $buku  = IOFactory::load($path);
    $sheet = $buku->getSheetByName('Keadaan Spare Part');

    expect($sheet->getCell('AS7')->getValue())->toBe('Stock by SAP (MRP)')
        ->and($sheet->getCell('AT7')->getValue())->toBe('Safety Stk (MRP)')
        ->and($sheet->getCell('AU7')->getValue())->toBe('Reorder Pt (MRP)')
        ->and($sheet->getCell('AX7')->getValue())->toBe('Stok Beda')
        ->and($sheet->getCell('AY7')->getValue())->toBe('MRP Diperbarui')
        // Kolom lama tidak bergeser: Lokasi G001 tetap AN.
        ->and($sheet->getCell('AN7')->getValue())->toBe('Lokasi G001');

    foreach ($buku->getWorksheetIterator() as $halaman) {
        $halaman->disconnectCells();
    }

    $buku->disconnectWorksheets();
});
