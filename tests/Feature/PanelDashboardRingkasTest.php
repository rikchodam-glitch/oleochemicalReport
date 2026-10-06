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
