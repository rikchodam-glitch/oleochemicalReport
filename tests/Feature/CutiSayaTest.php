<?php

use App\Models\CutiTeknisi;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// HALAMAN CUTI SAYA: EDIT/HAPUS MILIK SENDIRI
// ============================================================

beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Cuti Saya',
        'nik'    => 'CUTISAYA01',
        'group'  => 'reguler',
        'section' => 'mekanik',
        'status' => 'active',
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
        ->assertDontSee('Cuti teknisi lain');
});

test('teknisi bisa mengedit cuti miliknya dan perubahan tersimpan', function () {
    $this->actingAs($this->akun)
        ->get(route('cuti.saya.edit', $this->cuti))
        ->assertOk()
        ->assertSee('Edit Cuti');

    $this->actingAs($this->akun)
        ->put(route('cuti.saya.update', $this->cuti), [
            'tanggal_mulai'   => '2026-10-20',
            'tanggal_selesai' => '2026-10-20',
            'jenis'           => 'sakit',
            'alasan'          => 'Alasan diubah',
        ])
        ->assertRedirect(route('cuti.saya'));

    $this->cuti->refresh();

    expect($this->cuti->tanggal_selesai->toDateString())->toBe('2026-10-20')
        ->and($this->cuti->jenis)->toBe('sakit')
        ->and($this->cuti->alasan)->toBe('Alasan diubah')
        ->and($this->cuti->status)->toBe(CutiTeknisi::STATUS_DISETUJUI);
});

test('teknisi bisa menghapus cuti miliknya', function () {
    $this->actingAs($this->akun)
        ->delete(route('cuti.saya.destroy', $this->cuti))
        ->assertRedirect(route('cuti.saya'));

    expect(CutiTeknisi::find($this->cuti->id))->toBeNull();
});

test('teknisi tidak bisa mengedit atau menghapus cuti teknisi lain', function () {
    $lain = Technician::create([
        'name'   => 'Teknisi Lain',
        'nik'    => 'CUTISAYA03',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $cutiLain = CutiTeknisi::create([
        'technician_id'   => $lain->id,
        'tanggal_mulai'   => '2026-10-22',
        'tanggal_selesai' => '2026-10-22',
        'jenis'           => 'izin',
        'status'          => CutiTeknisi::STATUS_DISETUJUI,
    ]);

    $this->actingAs($this->akun)->get(route('cuti.saya.edit', $cutiLain))->assertForbidden();
    $this->actingAs($this->akun)->put(route('cuti.saya.update', $cutiLain), [
        'tanggal_mulai'   => '2026-10-22',
        'tanggal_selesai' => '2026-10-22',
        'jenis'           => 'izin',
    ])->assertForbidden();
    $this->actingAs($this->akun)->delete(route('cuti.saya.destroy', $cutiLain))->assertForbidden();

    expect(CutiTeknisi::find($cutiLain->id))->not->toBeNull();
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
