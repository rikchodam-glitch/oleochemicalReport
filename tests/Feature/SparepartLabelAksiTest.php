<?php

use App\Models\SpMaterial;
use App\Models\User;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// MODUL SPARE PART: KETERANGAN KARTU AKSI
// ============================================================
// Kartu "Kejar PO" mudah tertukar dengan angka "Belum PO" pada dashboard lama:
// 12/10/2026 keduanya sempat bernilai 51, padahal artinya berlawanan (di
// aplikasi 51 = material yang SUDAH punya nomor PO). Keterangan ikut dirender
// supaya arti tiap kartu tidak perlu ditebak.

/**
 * Membuat berkas EXPORT PR cuplikan yang hanya menyentuh 1 dari 5 material pantau.
 *
 * @param  string $nama Nama berkas uji
 * @return string       Path berkas uji
 */
function spLabelPrCuplikan(string $nama): string
{
    return spUjiXlsx($nama, [
        'Sheet1' => [
            ['Item of requisition', 'Purchase order', 'Processing status', 'Deletion Indicator', 'Material', 'Quantity requested', 'Delivery Date', 'Requisitioner', 'Purchasing Group'],
            ['Purchase Requisition 1000000009', '', '', '', '', '', '', '', ''],
            ['10', '', 'N', '', '30000001', 1, '05.10.2026', 'PPIC', 'A1'],
        ],
    ]);
}

test('setiap nilai kolom aksi punya keterangan tersendiri', function () {
    $keterangan = [];

    foreach (SpMaterial::AKSI as $aksi) {
        $teks = SpMaterial::keteranganAksi($aksi);

        expect($teks)->not->toBe('')
            ->and(in_array($teks, $keterangan, true))->toBeFalse();

        $keterangan[] = $teks;
    }

    expect(count($keterangan))->toBe(4)
        ->and(SpMaterial::keteranganAksi(null))->toBe('Belum ada keterangan untuk nilai aksi ini.');
});

test('label aksi memakai istilah Kejar Delivery dan Kejar PO', function () {
    expect(SpMaterial::labelAksi(SpMaterial::AKSI_KEJAR))->toBe('Kejar Delivery')
        ->and(SpMaterial::labelAksi(SpMaterial::AKSI_BUAT_PO))->toBe('Kejar PO')
        ->and(SpMaterial::keteranganAksi(SpMaterial::AKSI_BUAT_PO))->toContain('PO belum dibuat');
});

test('halaman sparepart menampilkan keterangan pada kartu aksi', function () {
    $this->seed();

    $admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $berkas = spUjiBerkas();

    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);
    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_PR => $berkas['pr'],
    ]);

    $this->actingAs($admin)->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertSee(SpMaterial::keteranganAksi(SpMaterial::AKSI_BUAT_PO))
        ->assertSee(SpMaterial::keteranganAksi(SpMaterial::AKSI_BUAT_PR))
        ->assertSee(SpMaterial::keteranganAksi(SpMaterial::AKSI_KEJAR));
});

test('peringatan cakupan menjelaskan akibatnya pada angka buat PO', function () {
    $this->seed();

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    app(SparepartPantauService::class)->imporDariBerkas(spUjiBerkas()['pantau']);

    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_PR => spLabelPrCuplikan('pr-cuplikan-label.xlsx'),
    ], null, 'Uji keterangan cuplikan');

    $this->actingAs($admin)->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertSee('Cakupan berkas import terakhir belum lengkap')
        ->assertSee('angka Kejar PO bisa tampak nol');
});
