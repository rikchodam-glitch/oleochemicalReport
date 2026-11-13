<?php

use App\Models\CmEquipment;
use App\Models\CmReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga persentase kondisi Overview setelah keputusan user:
 * visual bad dihitung sebagai kondisi baik karena nilai vibrasinya masih
 * dalam batas, tetapi tetap ditampilkan sebagai catatan tindak lanjut.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $equipment = CmEquipment::create([
        'equipment_tag'  => 'TEST-VISUAL01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    $reading = fn(string $tanggal, string $kondisi, float $vibrasi) => CmReading::create([
        'cm_equipment_id' => $equipment->id,
        'tanggal'         => $tanggal,
        'status'          => 'start',
        'kondisi'         => $kondisi,
        'ndev_motor'      => $vibrasi,
    ]);

    $reading('2026-10-01', 'good', 1.10);
    $reading('2026-10-02', 'visual_bad', 2.20);
    $reading('2026-10-03', 'alarm', 5.50);
});

test('overview menghitung visual bad sebagai kondisi baik dan menampilkan catatan', function () {
    $response = $this->actingAs($this->admin)
        ->get('/cm/overview?tahun=2026&bulan=10');

    $response->assertOk()
        ->assertSee('66.7% kondisi vibrasi baik.', false)
        ->assertSee('Catatan visual bad', false)
        ->assertSee('reading visual bad dihitung sebagai kondisi baik', false)
        ->assertSee('Tindak lanjut finding', false)
        ->assertSee('Buka Finding CM', false);
});

test('kartu ringkasan overview memakai hitungan baik yang mencakup visual bad', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('cm.overview-summary', ['tahun' => 2026, 'bulan' => 10]));

    $response->assertOk()
        ->assertSee('Baik (vibrasi)', false)
        ->assertSee('66.7', false)
        ->assertSee('1 good + 1 visual bad', false)
        ->assertSee('sudah termasuk di Baik', false);
});
