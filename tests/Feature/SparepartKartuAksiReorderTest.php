<?php

use App\Models\SpMaterial;
use App\Models\User;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// MODUL SPARE PART: LINGKUP KARTU AKSI (HANYA ITEM REORDER)
// ============================================================
// 12/10/2026 atas permintaan user: kartu Aksi disamakan dengan sheet "Daftar
// Aksi" dashboard lama yang hanya memuat item REORDER (fokus tindakan).
// Sebelumnya kartu menghitung seluruh 449 material pantau, sehingga angka
// Angka kartu tidak bisa dibandingkan dengan Ringkasan dashboard lama
// (Kejar Delivery 25 / Kejar PO 51 / Cek 45 / Buat PR 28 dari 149 item REORDER).

/**
 * Membaca angka satu kartu aksi dari HTML halaman Sparepart.
 *
 * @param  string $html  Isi halaman
 * @param  string $label Label kartu (mis. "Kejar PO")
 * @return int           Angka kartu (0 bila kartu tidak ditemukan)
 */
function spAngkaKartuAksi(string $html, string $label): int
{
    $pola = '/data-kartu-aksi="' . preg_quote($label, '/') . '">(.*?)<\/a>/s';

    if (preg_match($pola, $html, $kartu) !== 1) {
        return 0;
    }

    preg_match('/mt-1">([0-9.]+)</', $kartu[1], $angka);

    return (int) str_replace('.', '', $angka[1] ?? '0');
}

/**
 * Menyiapkan halaman Sparepart dengan fixture bawaan modul.
 *
 * Fixture: 30000001 (tanpa catatan stok, PR terbuka) -> REORDER + KEJAR PO;
 * 30000002 (sisa 0, PO terbuka) -> REORDER + KEJAR; 30000003 (sisa 7, PR sudah
 * jadi PO) -> AMAN + CEK; 30000004 (sisa 0, PO tertutup) -> REORDER + BUAT PR;
 * 30000005 (sisa 2, tanpa PR/PO) -> AMAN + BUAT PR.
 *
 * @param  object $uji Test case Pest yang sedang berjalan
 * @return string      Isi HTML halaman Sparepart
 */
function spHalamanSparepart(object $uji): string
{
    $uji->seed();

    $admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $berkas = spUjiBerkas();

    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);
    app(SparepartImportService::class)->import($berkas);

    return (string) $uji->actingAs($admin)->get(route('sparepart.index'))->assertStatus(200)->getContent();
}

test('kartu aksi hanya menghitung item REORDER seperti sheet Daftar Aksi dashboard lama', function () {
    $html = spHalamanSparepart($this);

    // Fixture memuat 3 item REORDER: 30000001 (Kejar PO), 30000002 (Kejar Delivery),
    // 30000004 (Buat PR). Item aman 30000003 (Cek/Buat PR) tidak dihitung.
    expect(spAngkaKartuAksi($html, 'Kejar Delivery'))->toBe(1)
        ->and(spAngkaKartuAksi($html, 'Kejar PO'))->toBe(1)
        ->and(spAngkaKartuAksi($html, 'Cek / Buat PR'))->toBe(0)
        ->and(spAngkaKartuAksi($html, 'Buat PR'))->toBe(1)
        ->and($html)->toContain('item REORDER (fokus tindakan)')
        ->and($html)->toContain('material yang stoknya masih aman tidak dihitung');
});

test('kartu ringkasan keadaan tetap menghitung seluruh material pantau', function () {
    $html = spHalamanSparepart($this);

    preg_match('/data-kartu="total">([0-9.]+)</', $html, $total);
    preg_match('/data-kartu="reorder">([0-9.]+)</', $html, $reorder);
    preg_match('/data-kartu="aman">([0-9.]+)</', $html, $aman);

    expect((int) ($total[1] ?? 0))->toBe(5)
        ->and((int) ($reorder[1] ?? 0))->toBe(3)
        ->and((int) ($aman[1] ?? 0))->toBe(2);
});

test('tautan kartu aksi menyaring status reorder supaya jumlahnya sama dengan daftar yang terbuka', function () {
    $html = spHalamanSparepart($this);

    $tautan = static fn (string $aksi): string => str_replace(
        '&',
        '&amp;',
        route('sparepart.index', ['aksi' => $aksi, 'status' => 'reorder'])
    );

    expect($html)->toContain($tautan(SpMaterial::AKSI_CEK))
        ->and($html)->toContain($tautan(SpMaterial::AKSI_BUAT_PR));
});

test('jumlah baris daftar saat kartu dibuka sama dengan angka kartunya', function () {
    $html = spHalamanSparepart($this);
    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // Kartu BUAT PR = 1 -> hanya 30000004 yang terbuka, 30000005 (aman) tidak.
    expect(spAngkaKartuAksi($html, 'Buat PR'))->toBe(1);

    $this->actingAs($admin)
        ->get(route('sparepart.index', ['aksi' => SpMaterial::AKSI_BUAT_PR, 'status' => 'reorder']))
        ->assertStatus(200)
        ->assertSee('data-kode-material="30000004"', false)
        ->assertDontSee('data-kode-material="30000005"', false);

    // Kartu CEK = 0 -> daftarnya kosong.
    $this->actingAs($admin)
        ->get(route('sparepart.index', ['aksi' => SpMaterial::AKSI_CEK, 'status' => 'reorder']))
        ->assertStatus(200)
        ->assertSee('Tidak ada material yang cocok.');
});
