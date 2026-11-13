<?php

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga halaman daftar dan detail Finding CM:
 *   - filter PIC memakai nilai PIC dari sheet Finding CM;
 *   - baris finding bisa diklik ke halaman detail, tag equipment tetap ke
 *     halaman status equipment;
 *   - halaman detail menampilkan PIC, foto, dan bisa membuka modal edit.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->equipment = CmEquipment::create([
        'equipment_tag'  => 'TEST-PIC01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    $this->reading = CmReading::create([
        'cm_equipment_id' => $this->equipment->id,
        'tanggal'         => '2026-11-10',
        'status'          => 'Start',
        'kondisi'         => 'alarm',
        'max_vibration'   => 8.40,
        'max_temp'        => 76.50,
    ]);

    $this->findingMekanik = CmFinding::create([
        'cm_equipment_id' => $this->equipment->id,
        'cm_reading_id'   => $this->reading->id,
        'kode_finding'    => 'UI-PIC-001',
        'severity'        => 'high',
        'kategori'        => 'High Vibration',
        'deskripsi'       => 'Vibrasi tinggi di motor DE.',
        'remark'          => 'Perlu cek alignment.',
        'status'          => 'open',
        'pic'             => 'Mechanic',
        'tanggal_temuan'  => '2026-11-10',
        'hari_open'       => 3,
        'foto_url'        => 'https://example.test/foto-finding.jpg',
    ]);

    $this->findingElectric = CmFinding::create([
        'cm_equipment_id' => $this->equipment->id,
        'kode_finding'    => 'UI-PIC-002',
        'severity'        => 'medium',
        'kategori'        => 'Electrical',
        'deskripsi'       => 'Kabel motor perlu dicek.',
        'status'          => 'open',
        'pic'             => 'Electric',
        'tanggal_temuan'  => '2026-11-09',
        'hari_open'       => 4,
    ]);

    $this->findingTanpaPic = CmFinding::create([
        'cm_equipment_id' => $this->equipment->id,
        'kode_finding'    => 'UI-PIC-003',
        'severity'        => 'low',
        'kategori'        => 'Housekeeping',
        'deskripsi'       => 'Area sekitar pompa kotor.',
        'status'          => 'closed',
        'pic'             => null,
        'tanggal_temuan'  => '2026-11-08',
        'hari_open'       => 0,
    ]);
});

test('halaman finding menampilkan filter PIC dan menyaring sesuai PIC', function () {
    $response = $this->actingAs($this->admin)->get('/cm/findings?pic=Mechanic');

    $response->assertOk()
        ->assertSee('name="pic"', false)
        ->assertSee('Mechanic')
        ->assertSee('UI-PIC-001')
        ->assertDontSee('UI-PIC-002')
        ->assertDontSee('UI-PIC-003');
});

test('filter tanpa PIC menampilkan hanya finding tanpa PIC', function () {
    $response = $this->actingAs($this->admin)->get('/cm/findings?pic=__none');

    $response->assertOk()
        ->assertSee('UI-PIC-003')
        ->assertDontSee('UI-PIC-001')
        ->assertDontSee('UI-PIC-002');
});

test('baris finding mengarah ke detail dan tag equipment mengarah ke status equipment', function () {
    $response = $this->actingAs($this->admin)->get('/cm/findings');

    $response->assertOk()
        ->assertSee(route('cm.findings.show', 'UI-PIC-001'), false)
        ->assertSee(route('cm.equipment-show', 'TEST-PIC01'), false)
        ->assertSee('window.location.href', false)
        ->assertSee('Buka detail finding', false);
});

test('halaman detail finding menampilkan PIC foto dan tombol edit', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('cm.findings.show', 'UI-PIC-001'));

    $response->assertOk()
        ->assertSee('PIC: Mechanic')
        ->assertSee('https://example.test/foto-finding.jpg', false)
        ->assertSee('data-finding-edit', false)
        ->assertSee('name="redirect"', false)
        ->assertSee('Edit Finding')
        ->assertSee('Konteks Pembacaan')
        ->assertSee('8.40', false);
});

test('halaman detail finding tanpa foto menampilkan keadaan kosong', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('cm.findings.show', 'UI-PIC-002'));

    $response->assertOk()
        ->assertSee('Belum ada foto untuk finding ini.')
        ->assertSee('PIC: Electric');
});

test('edit dari halaman detail kembali ke halaman detail', function () {
    $response = $this->actingAs($this->admin)->put(
        route('cm.findings.update', $this->findingMekanik),
        [
            'severity'       => 'medium',
            'kategori'       => 'High Vibration',
            'deskripsi'      => 'Vibrasi tinggi di motor DE.',
            'analysis'       => 'Alignment bergeser.',
            'action'         => 'Lakukan alignment ulang.',
            'remark'         => 'Sudah dijadwalkan.',
            'status'         => 'open',
            'pic'            => 'PM',
            'tanggal_temuan' => '2026-11-10',
            'date_action'    => '2026-11-12',
            'redirect'       => 'finding',
        ]
    );

    $response->assertRedirect(route('cm.findings.show', 'UI-PIC-001'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('cm_findings', [
        'id'   => $this->findingMekanik->id,
        'pic'  => 'PM',
        'remark' => 'Sudah dijadwalkan.',
    ]);
});
