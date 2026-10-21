<?php

use App\Models\CutiTeknisi;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// HALAMAN CUTI SAYA: TEKNISI HANYA MELIHAT
// ============================================================

beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'    => 'Teknisi Cuti Saya',
        'nik'     => 'CUTISAYA01',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->akun = User::create([
        'name'          => 'Akun Cuti Saya',
        'username'      => 'cuti-saya-uji',
        'email'         => 'cuti-saya-uji@oleochemical.pro',
        'password'      => bcrypt('rahasia123'),
        'role'          => 'mekanik',
        'technician_id' => $this->teknisi->id,
    ]);

    $this->cuti = CutiTeknisi::create([
        'technician_id'   => $this->teknisi->id,
        'tanggal_mulai'   => '2026-10-20',
        'tanggal_selesai' => '2026-10-21',
        'jenis'           => 'tahunan',
        'alasan'          => 'Cuti milik sendiri',
        'status'          => CutiTeknisi::STATUS_DISETUJUI,
        'disetujui_at'    => now(),
    ]);
});

test('teknisi melihat cuti miliknya sendiri di halaman Cuti Saya', function () {
    $lain = Technician::create([
        'name'   => 'Teknisi Lain',
        'nik'    => 'CUTISAYA02',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    CutiTeknisi::create([
        'technician_id'   => $lain->id,
        'tanggal_mulai'   => '2026-10-22',
        'tanggal_selesai' => '2026-10-22',
        'jenis'           => 'izin',
        'alasan'          => 'Cuti teknisi lain',
        'status'          => CutiTeknisi::STATUS_DISETUJUI,
    ]);

    $this->actingAs($this->akun)
        ->get(route('cuti.saya'))
        ->assertOk()
        ->assertSee('Cuti Saya')
        ->assertSee('Cuti milik sendiri')
        ->assertDontSee('Cuti teknisi lain')
        ->assertDontSee('>Edit<', false)
        ->assertDontSee('>Hapus<', false);
});

test('akun tanpa data teknisi tidak bisa membuka Cuti Saya', function () {
    $tanpaTeknisi = User::create([
        'name'     => 'Akun Tanpa Teknisi',
        'username' => 'cuti-tanpa-teknisi',
        'email'    => 'cuti-tanpa-teknisi@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($tanpaTeknisi)
        ->get(route('cuti.saya'))
        ->assertForbidden();
});
