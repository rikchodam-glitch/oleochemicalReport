<?php

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Klik tengah (roda gulir) pada baris tabel membuka halaman tujuan di tab
 * baru. Halaman yang memakai: Laporan dan Asset Management.
 *
 * Yang dijaga:
 *   1. Baris membawa atribut data-buka-tab berisi URL tujuan yang benar.
 *   2. Script penanganan auxclick ikut termuat di kedua halaman.
 *   3. Klik kiri (onclick) dan tautan di dalam baris tetap ada.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->funcloc = FunctionalLocation::create([
        'code'      => 'BT-PT',
        'segment'   => 'BT-PT',
        'name'      => 'PT Buka Tab',
        'level'     => FunctionalLocation::LEVEL_SITE,
        'is_active' => true,
    ]);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Buka Tab',
        'nik'    => 'BT001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();

    $this->laporan = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => $this->tanggal,
        'work_description' => 'Pekerjaan uji klik tengah laporan',
        'status'           => 'completed',
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-BT-001',
        'description'    => 'Aset uji klik tengah',
        'tech_ident_no'  => 'BT-ASSET-01',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'status'         => 'active',
        'data_source'    => 'manual',
    ]);
});

test('baris laporan membawa data-buka-tab dan script klik tengah', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal);

    $respons->assertStatus(200)
        ->assertSee('data-buka-tab="' . route('reports.show', $this->laporan) . '"', false)
        ->assertSee("window.location='" . route('reports.show', $this->laporan) . "'", false)
        ->assertSee('auxclick', false)
        ->assertSee('window.__bukaTabTengahTerpasang', false);
});

test('baris asset membawa data-buka-tab dan script klik tengah', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/assets');

    $respons->assertStatus(200)
        ->assertSee('data-buka-tab="' . route('assets.show', $this->aset) . '"', false)
        ->assertSee("window.location='" . route('assets.show', $this->aset) . "'", false)
        ->assertSee('auxclick', false)
        ->assertSee('window.__bukaTabTengahTerpasang', false);
});
