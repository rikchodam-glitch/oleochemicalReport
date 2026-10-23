<?php

use App\Models\Asset;
use App\Models\CmEquipment;
use App\Models\Company;
use App\Models\FunctionalLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('public');

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $perusahaan = Company::first() ?? Company::create(['code' => 'AST', 'name' => 'PT Asset Uji']);

    $this->funcLoc = FunctionalLocation::create([
        'code' => 'AST-CM-PT', 'segment' => 'AST-CM-PT', 'name' => 'PT Asset CM Uji',
        'level' => 0, 'company_id' => $perusahaan->id, 'is_active' => true,
    ]);

    $this->asset = Asset::create([
        'tech_ident_no' => 'AST-CM-01',
        'description'   => 'Pompa Integrasi CM',
        'equipment_no'  => 'EQ-AST-CM-01',
        'equipment_type'=> 'PUMP',
        'funcloc_id'    => $this->funcLoc->id,
        'company_id'    => $perusahaan->id,
        'status'        => 'active',
        'data_source'   => 'manual',
    ]);

    $this->cm = CmEquipment::create([
        'equipment_tag' => 'AST-CM-01',
        'pt_location'   => 'PT Asset CM Uji',
        'plant'         => 'Plant Uji',
        'tipe_lubrikasi'=> 'Grease',
        'is_active'     => true,
        'in_master'     => true,
    ]);
});

/**
 * Menambahkan satu foto uji ke asset lalu mengembalikan URL publiknya.
 *
 * @param  Asset $asset Asset yang diberi foto
 * @return string       URL foto publik
 */
function pasangFotoAssetUji(Asset $asset): string
{
    $path = 'assets/photos/' . $asset->id . '/foto-uji.jpg';
    Storage::disk('public')->put($path, 'foto-uji');
    $asset->update(['photos' => [$path]]);

    return Storage::disk('public')->url($path);
}

test('detail asset menampilkan preview CM, tombol CM, dan galeri foto', function () {
    $url = pasangFotoAssetUji($this->asset);

    $this->actingAs($this->admin)
        ->get(route('assets.show', $this->asset))
        ->assertOk()
        ->assertSee('Ringkasan Equipment (CM)')
        ->assertSee('Buka Halaman CM')
        ->assertSee(route('cm.equipment-show', $this->cm->equipment_tag), false)
        ->assertSee('Foto Equipment')
        ->assertSee('Lihat semua')
        ->assertSee('galeriFotoAset', false)
        ->assertSee('x-init="mulaiSlide()"', false)
        ->assertSee('aspect-[4/3]', false)
        ->assertSee($url, false);
});

test('halaman CM menampilkan foto asset dan tautan ke Asset Management', function () {
    $url = pasangFotoAssetUji($this->asset);

    $this->actingAs($this->admin)
        ->get(route('cm.equipment-show', $this->cm->equipment_tag))
        ->assertOk()
        ->assertSee('Ringkasan Equipment')
        ->assertSee('Foto Equipment')
        ->assertSee('Buka Asset Management')
        ->assertSee(route('assets.show', $this->asset), false)
        ->assertSee($url, false)
        ->assertSee('galeriFotoAset', false)
        ->assertSee('x-init="mulaiSlide()"', false)
        ->assertSee('aspect-[4/3]', false);
});

test('foto yang ditambahkan lewat Edit Asset langsung muncul di halaman CM', function () {
    $this->actingAs($this->admin)
        ->put(route('assets.update', $this->asset), [
            'description'    => 'Pompa Integrasi CM',
            'tech_ident_no'  => 'AST-CM-01',
            'equipment_type' => 'PUMP',
            'funcloc_id'     => $this->funcLoc->id,
            'manufacturer'   => 'Test',
            'model_number'   => 'X1',
            'construct_year' => '2026',
            'status'         => 'active',
            'photos'         => [UploadedFile::fake()->image('foto-cm.jpg')],
        ])
        ->assertRedirect(route('assets.index'));

    $url = $this->asset->fresh()->photo_urls[0];

    $this->actingAs($this->admin)
        ->get(route('cm.equipment-show', $this->cm->equipment_tag))
        ->assertOk()
        ->assertSee($url, false);
});

test('daftar CM Equipment Status menautkan nama equipment ke detail asset', function () {
    $this->actingAs($this->admin)
        ->get(route('cm.equipment-status'))
        ->assertOk()
        ->assertSee(route('assets.show', $this->asset), false);
});
