<?php

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmReading;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga tampilan dan edit pada halaman detail equipment CM:
 *   - seluruh sumbu vibrasi (NDE/DE, V/H/A) tampil, bukan hanya NDE;
 *   - nilai tertinggi pada baris danger/alarm diberi warna dan sumbernya;
 *   - remark pembacaan tampil utuh tanpa dipotong;
 *   - finding punya kolom remark dan tombol Edit;
 *   - update finding menghitung ulang hari_open;
 *   - tautan Edit laporan teknisi langsung membuka mode edit.
 *
 * Data uji memakai contoh nyata 6811P09: nilai bahaya 21,90 mm/s ada di
 * Motor DE-A, tetapi tabel lama hanya menampilkan tiga nilai NDE.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->equipment = CmEquipment::create([
        'equipment_tag'  => 'TEST-CMUI01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    $this->reading = CmReading::create([
        'cm_equipment_id'      => $this->equipment->id,
        'tanggal'              => '2026-09-15',
        'status'               => 'Start',
        'kondisi'              => 'danger',
        'max_vibration'        => 21.90,
        'ndev_motor'           => 3.10,
        'ndeh_motor'           => 1.70,
        'ndea_motor'           => 0.00,
        'dev_motor'            => 9.60,
        'deh_motor'            => 1.60,
        'dea_motor'            => 21.90,
        'ndev_pompa'           => 4.80,
        'ndeh_pompa'           => 2.30,
        'ndea_pompa'           => 3.50,
        'dev_pompa'            => 7.70,
        'deh_pompa'            => 5.60,
        'dea_pompa'            => 0.00,
        'temp_de_motor'        => 35.00,
        'temp_nde_motor'       => 34.00,
        'temp_de_pompa'        => 32.00,
        'temp_nde_pompa'       => 31.00,
        'ampere'               => 0.00,
        'discharge_pressure'   => 5.40,
        'remark'               => 'Jarum PG rusak, tidak ada cover kopling [Temuan: 1]',
    ]);

    $this->finding = CmFinding::create([
        'cm_equipment_id' => $this->equipment->id,
        'cm_reading_id'   => $this->reading->id,
        'kode_finding'    => 'UI-FIND-001',
        'severity'        => 'medium',
        'kategori'        => 'High Vibration',
        'deskripsi'       => '-',
        'analysis'        => '',
        'action'          => '',
        'remark'          => 'Perlu cek alignment motor DE.',
        'status'          => 'open',
        'pic'             => 'Mechanic',
        'tanggal_temuan'  => '2026-09-15',
        'hari_open'       => 7,
    ]);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi UI',
        'nik'    => 'UI001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);
});

test('halaman equipment menampilkan seluruh sumbu vibrasi dan sumber nilai tertinggi', function () {
    $response = $this->actingAs($this->admin)
        ->get('/cm/equipment/TEST-CMUI01?range=all');

    $response->assertStatus(200)
        ->assertSee('Vibrasi Motor', false)
        ->assertSee('Vibrasi Mesin', false)
        ->assertSee('21.9', false)
        ->assertSee('Motor DE-A', false)
        ->assertSee('9.6', false)
        ->assertSee('7.7', false);
});

test('remark pembacaan tampil utuh dan finding punya kolom remark serta tombol edit', function () {
    $response = $this->actingAs($this->admin)
        ->get('/cm/equipment/TEST-CMUI01?range=all');

    $response->assertStatus(200)
        ->assertSee('Jarum PG rusak, tidak ada cover kopling', false)
        ->assertSee('Temuan: 1', false)
        ->assertSee('Perlu cek alignment motor DE.', false)
        ->assertSee('Uraian', false)
        ->assertSee('data-edit-finding="' . $this->finding->id . '"', false)
        ->assertSee('bukaEditFinding', false);
});

test('finding bisa diperbarui dari modal edit dan hari open dihitung ulang', function () {
    $response = $this->actingAs($this->admin)->put(
        route('cm.findings.update', $this->finding),
        [
            'severity'       => 'high',
            'kategori'       => 'Leak',
            'deskripsi'      => 'Seal bocor.',
            'analysis'       => 'Seal aus.',
            'action'         => 'Ganti seal.',
            'remark'         => 'Sudah diganti, pantau 3 hari.',
            'status'         => 'closed',
            'pic'            => 'Mekanik',
            'tanggal_temuan' => '2026-09-01',
            'date_action'    => '2026-09-10',
        ]
    );

    $response->assertRedirect(route('cm.equipment-show', 'TEST-CMUI01'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('cm_findings', [
        'id'             => $this->finding->id,
        'severity'       => 'high',
        'kategori'       => 'Leak',
        'remark'         => 'Sudah diganti, pantau 3 hari.',
        'status'         => 'closed',
        'pic'            => 'Mekanik',
        'tanggal_temuan' => '2026-09-01',
        'date_action'    => '2026-09-10',
        'hari_open'      => 9,
    ]);
});

test('update finding menolak status dan tanggal tindakan yang tidak valid', function () {
    $response = $this->actingAs($this->admin)->put(
        route('cm.findings.update', $this->finding),
        [
            'severity'       => 'low',
            'kategori'       => 'Safety',
            'deskripsi'      => '-',
            'status'         => 'unknown',
            'tanggal_temuan' => '2026-09-15',
            'date_action'    => '2026-08-01',
        ]
    );

    $response->assertSessionHasErrors(['status', 'date_action']);

    $this->assertDatabaseHas('cm_findings', [
        'id'     => $this->finding->id,
        'status' => 'open',
        'remark' => 'Perlu cek alignment motor DE.',
    ]);
});

test('laporan teknisi punya tautan edit yang langsung membuka mode edit', function () {
    $laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => '2026-09-16',
        'report_code'           => 'UI-LAP-001',
        'work_description'      => 'Cek vibrasi motor DE',
        'work_duration_minutes' => 60,
        'report_type'           => 'equipment_repair',
        'status'                => 'completed',
        'equipment_tag'         => 'TEST-CMUI01',
    ]);

    $tautanEdit = route('reports.show', ['report' => $laporan, 'edit' => 1]);

    $this->actingAs($this->admin)
        ->get('/cm/equipment/TEST-CMUI01?range=all')
        ->assertStatus(200)
        ->assertSee($tautanEdit, false);

    $this->actingAs($this->admin)
        ->get($tautanEdit)
        ->assertStatus(200)
        ->assertSee('editMode: true', false);
});

