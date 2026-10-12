<?php

use App\Models\User;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// MODUL SPARE PART: CAKUPAN BERKAS SUMBER PADA ANALISA IMPORT
// ============================================================
// Berkas PR/PO yang diunggah bisa berupa cuplikan sebagian bulan; cakupannya
// terhadap daftar pantau dicatat pada analisa supaya angka kolom Aksi tidak
// dibaca sebagai keadaan sesungguhnya tanpa peringatan (kejadian 09/10/2026).

/**
 * Menyiapkan daftar pantau dari berkas fixture lalu mengembalikan path berkas.
 *
 * @return array<string, string> Path berkas fixture (stok, pr, po, pantau)
 */
function spCakupanSiapkan(): array
{
    $berkas = spUjiBerkas();
    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);

    return $berkas;
}

/**
 * Membaca satu nilai analisa import memakai kunci bertitik.
 *
 * @param  array<string, mixed> $analisa Analisa import
 * @param  string               $kunci   Kunci bertitik, mis. pantau.sumber.pr.persen
 * @return mixed                         Nilai yang diminta (null bila tidak ada)
 */
function spCakupanNilai(array $analisa, string $kunci): mixed
{
    $kini = $analisa;

    foreach (explode('.', $kunci) as $bagian) {
        if (! is_array($kini) || ! array_key_exists($bagian, $kini)) {
            return null;
        }

        $kini = $kini[$bagian];
    }

    return $kini;
}

/**
 * Membuat berkas EXPORT PR cuplikan yang hanya menyentuh 1 dari 5 material pantau.
 *
 * @param  string $nama Nama berkas uji
 * @return string       Path berkas uji
 */
function spCakupanPrCuplikan(string $nama): string
{
    return spUjiXlsx($nama, [
        'Sheet1' => [
            ['Item of requisition', 'Purchase order', 'Processing status', 'Deletion Indicator', 'Material', 'Quantity requested', 'Delivery Date', 'Requisitioner', 'Purchasing Group'],
            ['Purchase Requisition 1000000009', '', '', '', '', '', '', '', ''],
            ['10', '', 'N', '', '30000001', 1, '05.10.2026', 'PPIC', 'A1'],
        ],
    ]);
}

/**
 * Membuat berkas EXPORT PO yang menyentuh SELURUH lima material pantau.
 *
 * @param  string $nama Nama berkas uji
 * @return string       Path berkas uji
 */
function spCakupanPoPenuh(string $nama): string
{
    return spUjiXlsx($nama, [
        'Sheet1' => [
            ['Document Date', 'Purchasing Document', 'Supplier/Supplying Plant', 'Material', 'Order Quantity', 'Deletion Indicator', 'Still to be delivered (qty)', 'Our Reference', 'Plant'],
            [46000, '4500000009', 'VENDOR UJI', '30000001', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000010', 'VENDOR UJI', '30000002', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000011', 'VENDOR UJI', '30000003', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000012', 'VENDOR UJI', '30000004', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000013', 'VENDOR UJI', '30000005', 1, '', 1, 'DAVD', '7011'],
        ],
    ]);
}

test('analisa mencatat cakupan tiap berkas sumber terhadap daftar pantau', function () {
    $berkas = spCakupanSiapkan();

    $impor = app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
    ], null, 'Uji cakupan');

    $analisa = (array) $impor->analisa;

    // Fixture: 5 kode pantau; berkas stok menyentuh 4 kode, PR dan PO 3 kode.
    expect(spCakupanNilai($analisa, 'pantau.total'))->toBe(5)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.stok.punya'))->toBe(4)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.stok.tanpa'))->toBe(1)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.stok.persen'))->toBe(80)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.pr.punya'))->toBe(3)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.pr.tanpa'))->toBe(2)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.pr.persen'))->toBe(60)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.po.punya'))->toBe(3)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.po.persen'))->toBe(60)
        // Nilai lama tetap ada supaya tampilan riwayat lama tidak berubah.
        ->and(spCakupanNilai($analisa, 'pantau.bercatatan_stok'))->toBe(4)
        ->and(spCakupanNilai($analisa, 'pantau.tanpa_catatan_stok'))->toBe(1);

    $catatan = implode(' | ', (array) $analisa['catatan']);

    expect($catatan)->toContain('Cakupan berkas laporan stok gudang: 4 dari 5 material pantau (80%)')
        ->and($catatan)->toContain('Cakupan berkas EXPORT PR: 3 dari 5 material pantau (60%)')
        ->and($catatan)->toContain('Cakupan berkas EXPORT PO: 3 dari 5 material pantau (60%)')
        // Cakupan 60-80 persen masih dinilai wajar, jadi belum diberi peringatan.
        ->and($catatan)->not->toContain('PERINGATAN');
});

test('berkas cuplikan sempit diberi peringatan cakupan', function () {
    $berkas = spCakupanSiapkan();

    $prCuplikan = spUjiXlsx('pr-cuplikan-uji.xlsx', [
        'Sheet1' => [
            ['Item of requisition', 'Purchase order', 'Processing status', 'Deletion Indicator', 'Material', 'Quantity requested', 'Delivery Date', 'Requisitioner', 'Purchasing Group'],
            ['Purchase Requisition 1000000009', '', '', '', '', '', '', '', ''],
            ['10', '', 'N', '', '30000001', 1, '05.10.2026', 'PPIC', 'A1'],
        ],
    ]);

    $impor = app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_PR => $prCuplikan,
    ], null, 'Uji cuplikan');

    $analisa = (array) $impor->analisa;

    expect(spCakupanNilai($analisa, 'pantau.sumber.pr.punya'))->toBe(1)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.pr.tanpa'))->toBe(4)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.pr.persen'))->toBe(20)
        // Berkas yang tidak diunggah TIDAK dinilai nol, melainkan tidak dinilai.
        ->and(spCakupanNilai($analisa, 'pantau.sumber.stok'))->toBeNull()
        ->and(spCakupanNilai($analisa, 'pantau.sumber.po'))->toBeNull()
        ->and(spCakupanNilai($analisa, 'pantau.bercatatan_stok'))->toBeNull()
        ->and(spCakupanNilai($analisa, 'pantau.tanpa_catatan_stok'))->toBeNull();

    expect($berkas['pantau'])->toBeFile();

    $catatan = implode(' | ', (array) $analisa['catatan']);

    expect($catatan)->toContain('PERINGATAN')
        ->and($catatan)->toContain('Cakupan berkas EXPORT PR: 1 dari 5 material pantau (20%)')
        ->and($catatan)->toContain('cuplikan sebagian bulan');
});

test('cakupan penuh tidak menambah catatan cakupan', function () {
    spCakupanSiapkan();

    $poLengkap = spUjiXlsx('po-lengkap-uji.xlsx', [
        'Sheet1' => [
            ['Document Date', 'Purchasing Document', 'Supplier/Supplying Plant', 'Material', 'Order Quantity', 'Deletion Indicator', 'Still to be delivered (qty)', 'Our Reference', 'Plant'],
            [46000, '4500000009', 'VENDOR UJI', '30000001', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000010', 'VENDOR UJI', '30000002', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000011', 'VENDOR UJI', '30000003', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000012', 'VENDOR UJI', '30000004', 1, '', 1, 'DAVD', '7011'],
            [46000, '4500000013', 'VENDOR UJI', '30000005', 1, '', 1, 'DAVD', '7011'],
        ],
    ]);

    $impor = app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_PO => $poLengkap,
    ], null, 'Uji cakupan penuh');

    $analisa = (array) $impor->analisa;

    expect(spCakupanNilai($analisa, 'pantau.total'))->toBe(5)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.po.punya'))->toBe(5)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.po.tanpa'))->toBe(0)
        ->and(spCakupanNilai($analisa, 'pantau.sumber.po.persen'))->toBe(100)
        // Cakupan penuh dan tidak ada material di luar pantau -> tanpa catatan.
        ->and((array) $analisa['catatan'])->toBe([]);
});

test('catatan cakupan tampil pada halaman riwayat import', function () {
    $this->seed();

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    spCakupanSiapkan();

    $impor = app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_PR => spCakupanPrCuplikan('pr-cuplikan-riwayat.xlsx'),
    ], null, 'Uji catatan riwayat');

    // Daftar riwayat menandai import yang berkasnya bercakupan sempit.
    $this->actingAs($admin)->get(route('sparepart.riwayat'))
        ->assertStatus(200)
        ->assertSee('Uji catatan riwayat')
        ->assertSee('CAKUPAN SEMPIT');

    // Catatan lengkapnya tampil pada analisa import yang dibuka.
    $this->actingAs($admin)->get(route('sparepart.riwayat', ['impor' => $impor->id]))
        ->assertStatus(200)
        ->assertSee('Cakupan berkas EXPORT PR: 1 dari 5 material pantau (20%)')
        ->assertSee('cuplikan sebagian bulan');
});

test('halaman sparepart memperingatkan cakupan berkas import terakhir', function () {
    $this->seed();

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    spCakupanSiapkan();

    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_PR => spCakupanPrCuplikan('pr-cuplikan-beranda.xlsx'),
    ], null, 'Uji peringatan beranda');

    $this->actingAs($admin)->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertSee('Cakupan berkas import terakhir belum lengkap')
        ->assertSee('Cakupan berkas EXPORT PR: 1 dari 5 material pantau (20%)')
        ->assertSee('cuplikan sebagian bulan');
});

test('import bercakupan lengkap tidak memunculkan peringatan', function () {
    $this->seed();

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    spCakupanSiapkan();

    $impor = app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_PO => spCakupanPoPenuh('po-lengkap-beranda.xlsx'),
    ], null, 'Uji beranda penuh');

    expect($impor->peringatanCakupan())->toBe([]);

    $this->actingAs($admin)->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertDontSee('Cakupan berkas import terakhir belum lengkap');

    $this->actingAs($admin)->get(route('sparepart.riwayat'))
        ->assertStatus(200)
        ->assertDontSee('CAKUPAN SEMPIT');
});
