<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Dashboard Monitoring Ruang Panel dibuat ringkas: tiap kartu memuat kurva
 * singkat + lencana indikator suhu, sedangkan grafik lengkap dan riwayat
 * pembacaan hanya ada di halaman detail ruangan.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    config(['panel.pull.enabled' => false]);
});

test('dashboard panel tidak lagi memuat panel grafik besar dan tabel riwayat', function () {
    $this->actingAs($this->admin)
        ->get('/panel')
        ->assertOk()
        ->assertDontSee('panelGrafik', false)
        ->assertDontSee('grafikSuhu', false)
        ->assertDontSee('tabelRiwayat', false)
        ->assertDontSee('pilihRuanganGrafik', false);
});

test('kartu dashboard memuat kurva singkat dan lencana indikator suhu', function () {
    $this->actingAs($this->admin)
        ->get('/panel')
        ->assertOk()
        ->assertSee('kurva-', false)
        ->assertSee('TERLALU PANAS')
        ->assertSee('TERLALU DINGIN')
        ->assertSee('Penanggung jawab:')
        ->assertSee('dan ekspor Excel ada di halaman detail ruangan', false);
});

test('penyusun kartu suhu tidak memakai tanda plus ganda pada penggabungan teks', function () {
    $isi = file_get_contents(resource_path('views/panel/index.blade.php'));

    // Tanda plus kedua pada baris lanjutan penggabungan string
    // ("+ \"+ '<span...> C</span>'") adalah unary plus: teks span berubah
    // menjadi NaN sehingga kartu menampilkan "24.4NaN" dan satuan C hilang.
    expect(preg_match('/^\s*\+\s*\+/m', $isi))->toBe(0);
});

test('kartu suhu dashboard menampilkan nilai dengan satuan C', function () {
    $this->actingAs($this->admin)
        ->get('/panel')
        ->assertOk()
        ->assertSee('formatAngka(ruangan.suhu, 1)', false)
        ->assertSee('\'<span class="text-sm font-semibold text-slate-400"> C</span>\'', false)
        ->assertDontSee('+          +', false);
});
