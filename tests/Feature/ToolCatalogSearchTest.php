<?php

use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolGroup;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Master jenis/kelompok alat dan pencarian pintar Management Tools.
 */
beforeEach(function () {
    $this->seed();
    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->lokasi = ToolLocation::create(['name' => 'Workshop']);
    $this->rak    = ToolRack::create(['tool_location_id' => $this->lokasi->id, 'name' => 'Rak A']);
    $this->jenis  = ToolType::create(['name' => 'Bor']);
    $this->grup   = ToolGroup::create(['tool_type_id' => $this->jenis->id, 'name' => 'Bor Batrai']);

    $this->alat = Tool::create([
        'tool_rack_id'  => $this->rak->id,
        'tool_group_id' => $this->grup->id,
        'name'          => 'Bor Batrai',
        'code'          => 'BRBT001',
        'status'        => Tool::STATUS_TERSEDIA,
    ]);
});

test('halaman jenis dan kelompok dapat dibuka', function () {
    $this->actingAs($this->admin)
        ->get(route('tools.catalog.index'))
        ->assertOk()
        ->assertSee('Jenis & Kelompok')
        ->assertSee('Bor Batrai');
});

test('jenis dan kelompok dapat dikelola dan nama unit mengikuti kelompok', function () {
    $this->actingAs($this->admin)->post(route('tools.types.store'), [
        'name' => 'Kunci',
    ])->assertRedirect();

    $jenisBaru = ToolType::where('name', 'Kunci')->firstOrFail();

    $this->actingAs($this->admin)->post(route('tools.groups.store'), [
        'tool_type_id' => $jenisBaru->id,
        'name'         => 'Kunci Pas',
    ])->assertRedirect();

    $grupBaru = ToolGroup::where('name', 'Kunci Pas')->firstOrFail();

    $this->actingAs($this->admin)->put(route('tools.groups.update', $this->grup->id), [
        'tool_type_id' => $this->jenis->id,
        'name'         => 'Bor Batrai 18V',
    ])->assertRedirect();

    expect($this->alat->fresh()->name)->toBe('Bor Batrai 18V')
        ->and($grupBaru->type->name)->toBe('Kunci');
});

test('jenis atau kelompok yang masih dipakai tidak dapat dihapus', function () {
    $this->actingAs($this->admin)->delete(route('tools.types.destroy', $this->jenis->id))
        ->assertSessionHasErrors('jenis');

    $this->actingAs($this->admin)->delete(route('tools.groups.destroy', $this->grup->id))
        ->assertSessionHasErrors('kelompok');

    expect(ToolType::whereKey($this->jenis->id)->exists())->toBeTrue()
        ->and(ToolGroup::whereKey($this->grup->id)->exists())->toBeTrue();
});

test('form alat memakai kelompok dan filter jenis', function () {
    $this->actingAs($this->admin)
        ->get(route('tools.index', ['jenis' => $this->jenis->id]))
        ->assertOk()
        ->assertSee('Bor Batrai')
        ->assertSee('BRBT001');

    $this->actingAs($this->admin)->post(route('tools.items.store'), [
        'tool_rack_id'  => $this->rak->id,
        'tool_group_id' => $this->grup->id,
        'code'          => 'BRBT002',
        'status'        => Tool::STATUS_TERSEDIA,
    ])->assertRedirect();

    expect(Tool::where('code', 'BRBT002')->firstOrFail()->name)->toBe('Bor Batrai');
});

test('pencarian dan saran menemukan kelompok, jenis, lokasi, rak, dan unit', function () {
    $this->actingAs($this->admin)
        ->getJson(route('tools.cari', ['q' => 'bor']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonFragment(['judul' => 'Bor Batrai'])
        ->assertJsonFragment(['subjudul' => 'BRBT001']);
});

test('teknisi dapat memakai endpoint saran /alat', function () {
    $teknisi = Technician::create([
        'name'        => 'Teknisi Cari',
        'nik'         => 'CRI001',
        'status'      => 'active',
        'telegram_id' => '12001',
    ]);

    $akun = User::create([
        'name'          => 'Akun Cari',
        'email'         => 'cari@oleochemical.pro',
        'password'      => bcrypt('rahasia'),
        'role'          => 'mekanik',
        'technician_id' => $teknisi->id,
    ]);

    $this->actingAs($akun)
        ->getJson(route('alat.cari', ['q' => 'rak a']))
        ->assertOk()
        ->assertJsonFragment(['judul' => 'Rak A']);
});
