<?php

use App\Models\CmEquipment;
use App\Models\CmReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga bagian "Trend & Analisa" untuk PRESSURE dan AMPERE pada halaman
 * detail equipment CM (/cm/equipment/{tag}).
 *
 * Latar (permintaan user 14/10/2026): halaman detail equipment baru memuat
 * trend vibrasi dan temperature. Tren untuk pressure dan ampere diminta
 * ditambahkan, wajib ada di halaman SETIAP equipment (bukan hanya equipment
 * tertentu), dan kolom Pressure juga diminta tampil pada tabel "Riwayat
 * Pembacaan".
 *
 * Seluruh equipment memakai view yang sama
 * (resources/views/cm/equipment-show.blade.php), jadi test ini memakai
 * BEBERAPA equipment berbeda supaya sifat "semua halaman equipment" ikut
 * terjaga: equipment berisi data lengkap, equipment tanpa pembacaan sama
 * sekali, dan equipment dengan titik data kurang dari tiga.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->equipment = CmEquipment::create([
        'equipment_tag'  => 'TEST-TREND01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    // Equipment kedua: belum punya satu pun pembacaan.
    CmEquipment::create([
        'equipment_tag'  => 'TEST-TREND02',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    // Tiga pembacaan berurutan: ampere 10,00 -> 12,50 -> 15,00 (+50%) dan
    // discharge pressure 4,00 -> 4,40 -> 4,80 (+20%). Keduanya naik melewati
    // batas signifikan 20% pada config cm.process_parameter_insight.
    $pembacaan = [
        ['2026-01-05', 10.00, 4.00],
        ['2026-02-05', 12.50, 4.40],
        ['2026-03-05', 15.00, 4.80],
    ];

    foreach ($pembacaan as [$tanggal, $ampere, $pressure]) {
        CmReading::create([
            'cm_equipment_id'    => $this->equipment->id,
            'tanggal'            => $tanggal,
            'status'             => 'start',
            'kondisi'            => 'good',
            'ampere'             => $ampere,
            'discharge_pressure' => $pressure,
        ]);
    }
});

test('halaman detail equipment menampilkan trend ampere dan discharge pressure', function () {
    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-TREND01?range=all');

    $response->assertStatus(200);
    $response->assertSee('Trend & Analisa', false);
    $response->assertSee('Trending Ampere', false);
    $response->assertSee('Trending Discharge Pressure', false);

    // Canvas chart yang dirender Chart.js (id dipakai fungsi renderChart)
    $response->assertSee('id="ampereChart"', false);
    $response->assertSee('id="pressureChart"', false);

    // Data trend pressure & ampere ikut terkirim ke Chart.js
    $response->assertSee('"ampere":10', false);
    $response->assertSee('"discharge_pressure":4', false);
});

test('analisa pressure dan ampere menyimpulkan arah tren dari data yang ada', function () {
    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-TREND01?range=all');

    $response->assertStatus(200);
    $response->assertSee('Analisa Pressure & Ampere', false);

    // Kenaikan di atas 20% dibanding titik pertama => "naik signifikan"
    $response->assertSee('Tren Ampere naik signifikan', false);
    $response->assertSee('Tren Discharge Pressure naik signifikan', false);
    $response->assertSee('Naik signifikan', false);

    // Persentase perubahan ikut ditampilkan: 10,00 -> 15,00 (+50%) dan
    // 4,00 -> 4,80 (+20%)
    $response->assertSee('perubahan +50.0%', false);
    $response->assertSee('perubahan +20.0%', false);
});

test('riwayat pembacaan menampilkan kolom Pressure berisi discharge_pressure', function () {
    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-TREND01?range=all');

    $response->assertStatus(200);
    $response->assertSeeInOrder(['Ampere', 'Pressure', 'Remark'], false);

    // Pembacaan terbaru (2026-03-05) tampil paling atas dengan pressure 4,80.
    // Sel Pressure sekarang memakai padding py-4 (baris lebih lega), jadi
    // cukup pastikan nilainya tampil, bukan mencocokkan class HTML lama.
    $response->assertSee('4.80', false);
});

test('equipment tanpa data pressure dan ampere tetap punya bagian trend dan analisa', function () {
    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-TREND02?range=all');

    $response->assertStatus(200);
    $response->assertSee('Trend & Analisa', false);
    $response->assertSee('Analisa Pressure & Ampere', false);
    $response->assertSee('Belum ada data', false);
    $response->assertSee('belum pernah diisi pada rentang waktu ini', false);
});

test('analisa menyebut data belum cukup bila titik data kurang dari tiga', function () {
    $equipment = CmEquipment::create([
        'equipment_tag'  => 'TEST-TREND03',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    foreach ([['2026-01-05', 10.00], ['2026-02-05', 11.00]] as [$tanggal, $ampere]) {
        CmReading::create([
            'cm_equipment_id' => $equipment->id,
            'tanggal'         => $tanggal,
            'status'          => 'start',
            'kondisi'         => 'good',
            'ampere'          => $ampere,
        ]);
    }

    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-TREND03?range=all');

    $response->assertStatus(200);
    $response->assertSee('Trending Ampere', false);
    $response->assertSee('arah tren belum bisa disimpulkan', false);
});

test('bagian trend dan analisa pressure serta ampere tampil di semua halaman equipment', function () {
    $this->actingAs($this->admin)
        ->get('/cm/equipment/TEST-TREND01?range=all')
        ->assertStatus(200)
        ->assertSee('Trending Ampere', false)
        ->assertSee('Trending Discharge Pressure', false)
        ->assertSee('Analisa Pressure & Ampere', false);

    $this->actingAs($this->admin)
        ->get('/cm/equipment/TEST-TREND02?range=all')
        ->assertStatus(200)
        ->assertSee('Analisa Pressure & Ampere', false);
});
