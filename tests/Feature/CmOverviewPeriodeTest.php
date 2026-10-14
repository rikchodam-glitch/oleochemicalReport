<?php

use App\Models\CmEquipment;
use App\Models\CmReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga tampilan baris "Periode:" pada halaman Overview Condition Monitoring.
 *
 * Latar (permintaan user 14/10/2026): pada halaman
 * /cm/overview?tahun=2026&bulan=10 — yaitu bulan yang sedang tampil adalah
 * bulan terakhir yang punya data — di samping nama bulan muncul keterangan
 * tambahan "(bulan terakhir yang ada datanya)". Keterangan itu diminta dihapus.
 *
 * Test di bawah menjaga dua hal sekaligus:
 *   1. keterangan tambahan itu TIDAK muncul lagi walaupun kondisi yang dulu
 *      memicunya masih sama (bulan yang difilter = bulan terakhir yang berisi
 *      data); dan
 *   2. baris "Periode: <bulan> <tahun>" TIDAK ikut terhapus, supaya penanda
 *      periode aktif tetap ada di layar.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // Satu equipment CM + satu baris pengukuran Oktober 2026, supaya bulan 10
    // benar-benar menjadi "bulan terakhir yang ada datanya" pada tahun 2026 —
    // inilah kondisi yang dahulu memunculkan keterangan tambahan tersebut.
    $this->cmEquipment = CmEquipment::create([
        'equipment_tag'  => 'TEST-PERIODE01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    CmReading::create([
        'cm_equipment_id' => $this->cmEquipment->id,
        'tanggal'         => '2026-10-05',
        'status'          => 'start',
        'kondisi'         => 'alarm',
        'ndev_motor'      => 5.20,
        'ndev_pompa'      => 6.10,
    ]);
});

test('Overview CM tidak lagi menampilkan keterangan "(bulan terakhir yang ada datanya)"', function () {
    $response = $this->actingAs($this->admin)->get('/cm/overview?tahun=2026&bulan=10');

    $response->assertStatus(200);

    // Dihitung dengan cara yang sama seperti view, sehingga test ini juga
    // membuktikan kondisi pemicunya benar-benar aktif (label bulan ikut tampil).
    $labelBulan = \DateTime::createFromFormat('!m', 10)->format('F') . ' 2026';

    $response->assertSee($labelBulan, false);
    $response->assertDontSee('bulan terakhir yang ada datanya', false);
    $response->assertDontSee('(bulan terakhir', false);
});

test('baris "Periode:" tetap tampil sebagai penanda periode aktif', function () {
    $response = $this->actingAs($this->admin)->get('/cm/overview?tahun=2026&bulan=10');

    $response->assertStatus(200);
    $response->assertSee('Periode:', false);
});
