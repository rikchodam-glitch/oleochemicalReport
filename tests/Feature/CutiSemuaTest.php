<?php

use App\Models\CutiTeknisi;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// HALAMAN SEMUA CUTI: ADMIN, SUPERVISOR, ADMIN GENERAL
// ============================================================

beforeEach(function () {
    $this->seed();

    $this->teknisiA = Technician::create([
        'name'    => 'Teknisi Cuti A',
        'nik'     => 'SEMUA001',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->teknisiB = Technician::create([
        'name'    => 'Teknisi Cuti B',
        'nik'     => 'SEMUA002',
        'group'   => 'grub_b',
        'section' => 'ei',
        'status'  => 'active',
    ]);

    CutiTeknisi::create([
        'technician_id'   => $this->teknisiA->id,
        'tanggal_mulai'   => '2026-10-10',
        'tanggal_selesai' => '2026-10-12',
        'jenis'           => 'tahunan',
        'alasan'          => 'Cuti teknisi A',
    ]);

    CutiTeknisi::create([
        'technician_id'   => $this->teknisiB->id,
        'tanggal_mulai'   => '2026-11-01',
        'tanggal_selesai' => '2026-11-02',
        'jenis'           => 'izin',
        'alasan'          => 'Cuti teknisi B',
    ]);

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->supervisor = User::create([
        'name'     => 'Supervisor Uji',
        'username' => 'supervisor-cuti',
        'email'    => 'supervisor-cuti@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'supervisor',
    ]);

    $this->adminGeneral = User::create([
        'name'     => 'Admin General Uji',
        'username' => 'admin-general-cuti',
        'email'    => 'admin-general-cuti@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => User::ROLE_ADMIN_GENERAL,
    ]);

    $this->adminReguler = User::create([
        'name'     => 'Admin Reguler Cuti Uji',
        'username' => 'admin-reguler-cuti-semua',
        'email'    => 'admin-reguler-cuti-semua@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => User::ROLE_ADMIN_REGULER,
    ]);
});

test('administrator, supervisor, admin general, dan admin reguler melihat seluruh cuti', function () {
    foreach ([$this->admin, $this->supervisor, $this->adminGeneral, $this->adminReguler] as $akun) {
        $this->actingAs($akun)
            ->get(route('cuti.semua'))
            ->assertOk()
            ->assertSee('Semua Cuti Karyawan')
            ->assertSee('Cuti teknisi A')
            ->assertSee('Cuti teknisi B')
            ->assertSee('Teknisi Cuti A')
            ->assertSee('Teknisi Cuti B');
    }
});

test('admin general hanya mendapat akses dashboard dan halaman semua cuti', function () {
    expect($this->adminGeneral->bolehLihatSemuaCuti())->toBeTrue()
        ->and($this->adminGeneral->bolehMenulis())->toBeFalse()
        ->and($this->adminGeneral->punyaAkses('dashboard'))->toBeTrue()
        ->and($this->adminGeneral->punyaAkses('cuti_semua'))->toBeTrue()
        ->and($this->adminGeneral->punyaAkses('reports'))->toBeFalse();
});

test('teknisi biasa tidak bisa melihat halaman semua cuti', function () {
    $teknisi = User::create([
        'name'     => 'Teknisi Biasa Cuti',
        'username' => 'teknisi-biasa-cuti',
        'email'    => 'teknisi-biasa-cuti@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($teknisi)
        ->get(route('cuti.semua'))
        ->assertForbidden();
});

test('filter group, bagian, pencarian, dan tanggal bekerja', function () {
    $this->actingAs($this->admin)
        ->get(route('cuti.semua', ['grup' => 'grub_b']))
        ->assertOk()
        ->assertSee('Cuti teknisi B')
        ->assertDontSee('Cuti teknisi A');

    $this->actingAs($this->admin)
        ->get(route('cuti.semua', ['bagian' => 'mekanik']))
        ->assertOk()
        ->assertSee('Cuti teknisi A')
        ->assertDontSee('Cuti teknisi B');

    $this->actingAs($this->admin)
        ->get(route('cuti.semua', ['q' => 'SEMUA002']))
        ->assertOk()
        ->assertSee('Cuti teknisi B')
        ->assertDontSee('Cuti teknisi A');

    $this->actingAs($this->admin)
        ->get(route('cuti.semua', ['dari' => '2026-11-01']))
        ->assertOk()
        ->assertSee('Cuti teknisi B')
        ->assertDontSee('Cuti teknisi A');
});
