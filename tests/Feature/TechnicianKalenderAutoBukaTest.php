<?php

use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Popup kalender teknisi TIDAK boleh terbuka sendiri (perbaikan 15/10/2026).
 *
 * Penyebabnya: popup lama dibuka otomatis setiap kali URL memuat parameter
 * `jadwal_bulan`, sehingga paginasi (yang menyalin query string) dan simpan
 * hari libur (kembali ke halaman ber-`jadwal_bulan`) ikut membuka popup.
 * Sekarang popup hanya dibuka bila URL ditandai `buka_kalender=1` (hanya dari
 * tombol Bulan Sebelumnya / Bulan Berikutnya di dalam popup).
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
});

test('tautan bulan sebelumnya dan berikutnya menandai kalender untuk dibuka', function () {
    $html = $this->actingAs($this->admin)
        ->get('/technicians?jadwal_bulan=2026-11')
        ->assertStatus(200)
        ->getContent();

    // Kedua tombol bulan membawa penanda buka_kalender=1.
    expect(substr_count($html, 'buka_kalender=1'))->toBeGreaterThanOrEqual(2)
        // Pemicu auto-buka memakai penanda itu, bukan lagi sekadar jadwal_bulan.
        ->and($html)->toContain("indexOf('buka_kalender=1')")
        ->and($html)->not->toContain("indexOf('jadwal_bulan=')");
});

test('tautan paginasi tidak membawa parameter kalender', function () {
    for ($i = 1; $i <= 25; $i++) {
        Technician::create([
            'name'   => 'Teknisi Halaman ' . $i,
            'nik'    => 'HAL' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'group'  => 'reguler',
            'status' => 'active',
        ]);
    }

    $html = $this->actingAs($this->admin)
        ->get('/technicians?jadwal_bulan=2026-11&buka_kalender=1')
        ->assertStatus(200)
        ->getContent();

    preg_match_all('/href="([^"]*page=\d+[^"]*)"/', $html, $cocok);

    $tautanHalaman = $cocok[1] ?? [];

    expect($tautanHalaman)->not->toBeEmpty('Tautan paginasi tidak ditemukan.');

    foreach ($tautanHalaman as $url) {
        expect($url)->not->toContain('jadwal_bulan')
            ->and($url)->not->toContain('buka_kalender');
    }
});

test('paginasi tetap membawa filter daftar teknisi', function () {
    for ($i = 1; $i <= 25; $i++) {
        Technician::create([
            'name'   => 'Teknisi Filter ' . $i,
            'nik'    => 'FLT' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'group'  => 'reguler',
            'status' => 'active',
        ]);
    }

    $html = $this->actingAs($this->admin)
        ->get('/technicians?group=reguler&search=Teknisi&jadwal_bulan=2026-11')
        ->assertStatus(200)
        ->getContent();

    preg_match_all('/href="([^"]*page=\d+[^"]*)"/', $html, $cocok);

    $tautanHalaman = $cocok[1] ?? [];

    expect($tautanHalaman)->not->toBeEmpty();

    foreach ($tautanHalaman as $url) {
        expect($url)->toContain('group=reguler')
            ->and($url)->not->toContain('jadwal_bulan');
    }
});
