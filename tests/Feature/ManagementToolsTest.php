<?php

use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Halaman admin Management Tools: master lokasi/rak/alat, foto, dan aturan
 * hapus. Transaksi peminjaman diuji terpisah di ToolLendingTest.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    Storage::fake('public');
});

test('halaman management tools dapat dibuka administrator', function () {
    $this->actingAs($this->admin)->get(route('tools.index'))->assertOk()->assertSee('Management Tools');
    $this->actingAs($this->admin)->get(route('tools.locations.index'))->assertOk()->assertSee('Lokasi Penyimpanan');
    $this->actingAs($this->admin)->get(route('tools.loans.index'))->assertOk()->assertSee('Riwayat Peminjaman');
    $this->actingAs($this->admin)->get(route('tools.keepers.index'))->assertOk()->assertSee('Tool Keeper');
});

test('lokasi, rak, dan alat dapat ditambah diubah lalu dihapus', function () {
    $this->actingAs($this->admin)->post(route('tools.locations.store'), [
        'name'        => 'Workshop Mekanik',
        'description' => 'Gudang alat mekanik',
    ])->assertRedirect();

    $lokasi = ToolLocation::firstOrFail();
    expect($lokasi->name)->toBe('Workshop Mekanik');

    $this->actingAs($this->admin)->post(route('tools.racks.store'), [
        'tool_location_id' => $lokasi->id,
        'name'             => 'Rak A',
    ])->assertRedirect();

    $rak = ToolRack::firstOrFail();
    expect($rak->tool_location_id)->toBe($lokasi->id);

    $this->actingAs($this->admin)->post(route('tools.items.store'), [
        'tool_rack_id' => $rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'brbt001',
        'status'       => Tool::STATUS_TERSEDIA,
        'note'         => 'Bor cadangan',
        'photo'        => UploadedFile::fake()->image('bor.jpg', 500, 500),
    ])->assertRedirect();

    $alat = Tool::firstOrFail();
    expect($alat->code)->toBe('BRBT001')
        ->and($alat->name)->toBe('Bor Batrai')
        ->and($alat->status)->toBe(Tool::STATUS_TERSEDIA)
        ->and($alat->photo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($alat->photo_path);
    $fotoLama = $alat->photo_path;

    $this->actingAs($this->admin)->put(route('tools.items.update', $alat->id), [
        'tool_rack_id' => $rak->id,
        'name'         => 'Bor Batrai 18V',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_RUSAK,
        'note'         => 'Perlu servis',
        'photo'        => UploadedFile::fake()->image('bor-baru.jpg', 500, 500),
    ])->assertRedirect();

    $alat->refresh();

    expect($alat->name)->toBe('Bor Batrai 18V')
        ->and($alat->status)->toBe(Tool::STATUS_RUSAK)
        ->and($alat->photo_path)->not->toBe($fotoLama);

    Storage::disk('public')->assertMissing($fotoLama);
    Storage::disk('public')->assertExists($alat->photo_path);

    $this->actingAs($this->admin)->delete(route('tools.items.destroy', $alat->id))->assertRedirect();
    $this->assertDatabaseMissing('tools', ['id' => $alat->id]);
    Storage::disk('public')->assertMissing($alat->photo_path);

    $this->actingAs($this->admin)->delete(route('tools.racks.destroy', $rak->id))->assertRedirect();
    $this->assertDatabaseMissing('tool_racks', ['id' => $rak->id]);

    $this->actingAs($this->admin)->delete(route('tools.locations.destroy', $lokasi->id))->assertRedirect();
    $this->assertDatabaseMissing('tool_locations', ['id' => $lokasi->id]);
});

test('lokasi dan rak yang masih berisi tidak boleh dihapus', function () {
    $lokasi = ToolLocation::create(['name' => 'Workshop']);
    $rak    = ToolRack::create(['tool_location_id' => $lokasi->id, 'name' => 'Rak A']);
    Tool::create([
        'tool_rack_id' => $rak->id,
        'name'         => 'Tang',
        'code'         => 'TNG001',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    $this->actingAs($this->admin)->delete(route('tools.locations.destroy', $lokasi->id))
        ->assertSessionHasErrors('lokasi');
    $this->assertDatabaseHas('tool_locations', ['id' => $lokasi->id]);

    $this->actingAs($this->admin)->delete(route('tools.racks.destroy', $rak->id))
        ->assertSessionHasErrors('rak');
    $this->assertDatabaseHas('tool_racks', ['id' => $rak->id]);
});

test('alat yang sedang dipinjam tidak boleh dihapus', function () {
    $lokasi = ToolLocation::create(['name' => 'Workshop']);
    $rak    = ToolRack::create(['tool_location_id' => $lokasi->id, 'name' => 'Rak A']);
    $alat   = Tool::create([
        'tool_rack_id' => $rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_DIPAKAI,
    ]);

    ToolLoan::create([
        'kode'             => 'PJM-20261120-0001',
        'tool_id'          => $alat->id,
        'tool_name'        => $alat->name,
        'tool_code'        => $alat->code,
        'borrower_type'    => ToolLoan::BORROWER_NON_TEKNISI,
        'borrower_name'    => 'Tamu',
        'borrowed_at'      => now(),
        'handled_by_type'  => ToolLoan::HANDLED_ADMIN,
    ]);

    $this->actingAs($this->admin)->delete(route('tools.items.destroy', $alat->id))
        ->assertSessionHasErrors('alat');

    $this->assertDatabaseHas('tools', ['id' => $alat->id]);
});

test('kode alat wajib unik', function () {
    $lokasi = ToolLocation::create(['name' => 'Workshop']);
    $rak    = ToolRack::create(['tool_location_id' => $lokasi->id, 'name' => 'Rak A']);

    Tool::create([
        'tool_rack_id' => $rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    $this->actingAs($this->admin)->post(route('tools.items.store'), [
        'tool_rack_id' => $rak->id,
        'name'         => 'Bor Batrai Lain',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_TERSEDIA,
    ])->assertSessionHasErrors('code');
});

test('akun non-administrator dengan akses modul tetap hanya bisa membaca', function () {
    \App\Models\BagianAccess::query()->updateOrCreate(
        ['bagian' => 'mekanik', 'grup' => null],
        ['permissions' => ['dashboard', 'tools']]
    );

    $akun = User::create([
        'name'     => 'Mekanik Pembaca',
        'email'    => 'mekanik-pembaca@oleochemical.pro',
        'password' => bcrypt('rahasia'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($akun)->get(route('tools.index'))->assertOk();

    $this->actingAs($akun)->post(route('tools.locations.store'), [
        'name' => 'Lokasi Tidak Boleh',
    ])->assertForbidden();

    $this->assertDatabaseMissing('tool_locations', ['name' => 'Lokasi Tidak Boleh']);
});

test('pengguna tanpa akses ditolak dan supervisor tidak boleh menunjuk keeper', function () {
    \App\Models\BagianAccess::query()->updateOrCreate(
        ['bagian' => 'mekanik', 'grup' => null],
        ['permissions' => ['dashboard']]
    );

    $tanpaAkses = User::create([
        'name'     => 'Teknisi Biasa',
        'email'    => 'teknisi-biasa@oleochemical.pro',
        'password' => bcrypt('rahasia'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($tanpaAkses)->get(route('tools.index'))->assertForbidden();

    $supervisor = User::create([
        'name'     => 'Supervisor Uji',
        'email'    => 'supervisor-uji@oleochemical.pro',
        'password' => bcrypt('rahasia'),
        'role'     => 'supervisor',
    ]);

    $teknisi = Technician::create([
        'name'        => 'Teknisi Keeper Uji',
        'nik'         => 'KPR001',
        'status'      => 'active',
        'telegram_id' => '88001',
    ]);

    $this->actingAs($supervisor)->get(route('tools.keepers.index'))->assertOk();
    $this->actingAs($supervisor)->post(route('tools.keepers.toggle', $teknisi->id))->assertForbidden();
});
