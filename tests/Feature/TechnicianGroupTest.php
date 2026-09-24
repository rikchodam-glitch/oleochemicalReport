<?php

use App\Models\Department;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
});

// ============================================================
// DAFTAR GRUP TEKNISI
// ============================================================

test('konstanta GROUPS memuat Reguler dan Grub A sampai Grub D', function () {
    expect(Technician::GROUPS)->toHaveKeys(['reguler', 'grub_a', 'grub_b', 'grub_c', 'grub_d'])
        ->and(Technician::GROUPS['grub_d'])->toBe('Grub D');
});

test('form tambah dan edit teknisi menampilkan pilihan Grub D', function () {
    $this->actingAs($this->admin)
        ->get('/technicians/create')
        ->assertStatus(200)
        ->assertSee('Grub D', false);

    $teknisi = Technician::create([
        'name'   => 'Teknisi Untuk Edit',
        'nik'    => 'GRUPEDIT01',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->get('/technicians/' . $teknisi->id . '/edit')
        ->assertStatus(200)
        ->assertSee('Grub D', false);
});

test('filter daftar teknisi dan halaman bot menyediakan Grub D', function () {
    $this->actingAs($this->admin)
        ->get('/technicians')
        ->assertStatus(200)
        ->assertSee('Grub D', false);

    $this->actingAs($this->admin)
        ->get('/bot')
        ->assertStatus(200)
        ->assertSee('Grub D', false);
});

test('teknisi bisa disimpan dengan grup Grub D', function () {
    $department = Department::query()->orderBy('id')->first();

    $this->actingAs($this->admin)->post('/technicians', [
        'name'          => 'Teknisi Grub D',
        'nik'           => 'GRUPD001',
        'department_id' => $department?->id,
        'group'         => 'grub_d',
        'section'       => 'mekanik',
        'status'        => 'active',
    ])->assertRedirect();

    $teknisi = Technician::where('nik', 'GRUPD001')->firstOrFail();

    expect($teknisi->group)->toBe('grub_d')
        ->and(Technician::GROUPS[$teknisi->group])->toBe('Grub D');
});

test('grup di luar daftar tetap ditolak validasi', function () {
    $this->actingAs($this->admin)->post('/technicians', [
        'name'   => 'Teknisi Grup Salah',
        'nik'    => 'GRUPX001',
        'group'  => 'grub_z',
        'status' => 'active',
    ])->assertSessionHasErrors('group');
});
