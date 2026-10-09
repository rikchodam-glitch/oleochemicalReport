<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

// ============================================================
// BANTUAN PENGUJIAN MODUL SPARE PART
// ============================================================

/**
 * Menulis berkas XLSX uji: satu kunci array = satu lembar.
 *
 * @param  string                                        $nama   Nama berkas
 * @param  array<string, array<int, array<int, mixed>>>  $lembar Peta nama lembar => baris (baris pertama = judul)
 * @return string                                                Path berkas
 */
function spUjiXlsx(string $nama, array $lembar): string
{
    $buku  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $mulai = true;

    foreach ($lembar as $namaLembar => $baris) {
        $sheet = $mulai ? $buku->getActiveSheet() : $buku->createSheet();
        $sheet->setTitle($namaLembar);
        $sheet->fromArray($baris, null, 'A1');
        $mulai = false;
    }

    $path = spUjiFolder() . DIRECTORY_SEPARATOR . $nama;
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($buku))->save($path);
    $buku->disconnectWorksheets();

    return $path;
}

/**
 * Folder sementara pengujian modul sparepart.
 *
 * @return string Path folder
 */
function spUjiFolder(): string
{
    $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparepart-uji';

    if (! is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    return $folder;
}

/**
 * Berkas fixture modul sparepart (stok gudang, PR, PO, daftar pantau).
 *
 * Data fixture menutup empat nilai kolom aksi sekaligus:
 * 30000001 PR terbuka tanpa PO        -> BUAT PO
 * 30000002 PR tertutup + PO terbuka   -> KEJAR KEDATANGAN (PO OPEN)
 * 30000003 PR tertutup + PO tertutup  -> CEK / BUAT PR BARU
 * 30000004 PO tertutup tanpa PR        -> BUAT PR
 * 30000005 tanpa PR maupun PO         -> BUAT PR
 *
 * Stok gudang (stok awal + masuk - keluar):
 * 30000002 sisa 0 (REORDER), 30000003 sisa 7 (aman), 30000004 sisa 0 (REORDER),
 * 30000005 sisa 2 (aman), 30000001 tanpa catatan stok (REORDER).
 *
 * @return array<string, string> Path berkas per kunci: stok, pr, po, pantau
 */
function spUjiBerkas(): array
{
    $stok = spUjiXlsx('stok-uji.xlsx', [
        'G001' => [
            ['S. Loc', 'Kode', 'Nama Item', 'Stok Awal', 'Masuk', 'Keluar', 'Sisa Stok', 'UN'],
            ['A1.1', '30000002', 'SEAL UJI', 1, 0, 0, 1, 'PCS'],
            ['A1.2', '30000003', 'BEARING UJI', 5, 0, 0, 5, 'PCS'],
            ['A1.3', '30000004', 'V-BELT UJI', 0, 0, 0, 0, 'PCS'],
            ['A1.4', '30000005', 'GASKET UJI', 2, 0, 0, 2, 'PCS'],
        ],
        'MASUK 1' => [
            ['Tanggal', 'Kode', 'Nama Item', 'Qty', 'Uom', 'No. PO', 'Request by'],
            ['01/10/2026', '30000003', 'BEARING UJI', 2, 'PCS', '', 'MTC'],
        ],
        'KELUAR 1' => [
            ['Tanggal', 'Kode', 'Nama Item', 'Jumlah', 'Uom', 'User', 'MvT'],
            ['02/10/2026', '30000002', 'SEAL UJI', 1, 'PCS', 'Ainul - Prod', '201'],
        ],
    ]);

    $pr = spUjiXlsx('pr-uji.xlsx', [
        'Sheet1' => [
            ['Item of requisition', 'Purchase order', 'Processing status', 'Deletion Indicator', 'Material', 'Quantity requested', 'Delivery Date', 'Requisitioner', 'Purchasing Group'],
            ['Purchase Requisition 1000000001', '', '', '', '', '', '', '', ''],
            ['10', '', 'N', '', '30000001', 5, '05.10.2026', 'PPIC', 'A1'],
            ['Purchase Requisition 1000000002', '', '', '', '', '', '', '', ''],
            ['10', '4500000001', 'B', '', '30000002', 2, '05.10.2026', 'PPIC', 'A1'],
            ['Purchase Requisition 1000000003', '', '', '', '', '', '', '', ''],
            ['10', '4500000002', 'B', '', '30000003', 3, '05.10.2026', 'STORE', 'A2'],
            ['Purchase Requisition 1000000004', '', '', '', '', '', '', '', ''],
            ['10', '4500000003', 'B', '', '30009999', 1, '05.10.2026', 'STORE', 'A2'],
        ],
    ]);

    $po = spUjiXlsx('po-uji.xlsx', [
        'Sheet1' => [
            ['Document Date', 'Purchasing Document', 'Supplier/Supplying Plant', 'Material', 'Order Quantity', 'Deletion Indicator', 'Still to be delivered (qty)', 'Our Reference', 'Plant'],
            [46000, '4500000001', 'VENDOR UJI', '30000002', 10, '', 5, 'DAVD', '7011'],
            [46000, '4500000002', 'VENDOR UJI', '30000003', 3, '', 0, 'DAVD', '7011'],
            [46000, '4500000003', 'VENDOR UJI', '30000004', 1, '', 0, 'DAVD', '7011'],
            [46000, '4500000004', 'VENDOR UJI', '30009999', 1, '', 0, 'DAVD', '7011'],
        ],
    ]);

    $pantau = spUjiXlsx('pantau-uji.xlsx', [
        'MASTER DATA' => [
            ['No', 'Plant', 'Model', 'Tag No.', 'Kode Material', 'Deskripsi', 'Jumlah', 'UOM'],
            [1, '', '', '', '30000001', 'BRACKET UJI', 1, 'PCS'],
            [2, '', '', '', '30000002', 'SEAL UJI', 1, 'PCS'],
            [3, '', '', '', '30000003', 'BEARING UJI', 1, 'PCS'],
            [4, '', '', '', '30000004', 'V-BELT UJI', 1, 'PCS'],
            [5, '', '', '', '30000005', 'GASKET UJI', 1, 'PCS'],
        ],
    ]);

    return ['stok' => $stok, 'pr' => $pr, 'po' => $po, 'pantau' => $pantau];
}

