<?php

use App\Models\BagianAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// DASHBOARD UTAMA: RINGKASAN LINTAS MODUL
// ============================================================

test('admin melihat indikator utama dan seluruh grafik dashboard', function () {
    $this->seed();

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Maintenance')
        ->assertSee('data-section="kpi"', false)
        ->assertSee('data-chart="laporan-tren"', false)
        ->assertSee('data-tren-mode="laporan"', false)
        ->assertSee('data-tren-mode="teknisi"', false)
        ->assertSee('Tren laporan per jenis pekerjaan')
        ->assertSee('data-chart="status-laporan"', false)
        ->assertSee('data-chart="cm-kondisi"', false)
        ->assertSee('data-section="reliability"', false)
        ->assertSee('data-chart="sparepart-aksi"', false)
        ->assertSee('data-chart="it-status"', false)
        ->assertSee('data-chart="panel-suhu"', false)
        ->assertSee('Perlu perhatian');
});

test('dashboard hanya menampilkan modul yang boleh diakses akun Bagian', function () {
    BagianAccess::updateOrCreate(
        ['bagian' => 'mekanik', 'grup' => null],
        ['permissions' => ['dashboard']]
    );

    $akun = User::create([
        'name'     => 'Teknisi Mekanik Uji',
        'username' => 'mekanik-dashboard',
        'email'    => 'mekanik-dashboard@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($akun)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Maintenance')
        ->assertDontSee('data-chart="laporan-tren"', false)
        ->assertDontSee('data-chart="cm-kondisi"', false)
        ->assertDontSee('data-section="reliability"', false)
        ->assertDontSee('data-chart="sparepart-aksi"', false)
        ->assertDontSee('data-chart="it-status"', false)
        ->assertDontSee('data-chart="panel-suhu"', false);
});

test('periode dashboard yang tidak dikenal kembali ke 30 hari', function () {
    $this->seed();

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('dashboard', ['periode' => 999]))
        ->assertOk()
        ->assertSee('30 hari terakhir');
});
