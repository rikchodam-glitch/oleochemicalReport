<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Shift\ManHourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Kecocokan daftar laporan halaman Laporan dengan Rincian Man Hour Harian.
 *
 * Baris "N laporan" pada Rincian Man Hour Harian membuka
 * reports.index?technician_id=..&tanggal=.. . Karena man hour menghitung
 * salinan kolaborasi sebagai pekerjaan teknisi kolaborator, daftar itu WAJIB
 * ikut menampilkannya; kalau tidak, hari yang isinya hanya pekerjaan kolaborasi
 * tampak kosong walau kolom Realisasi menitnya terisi.
 *
 * Aturan yang dijaga di sini:
 *   1. Disaring ke satu teknisi  -> laporan milik teknisi itu, TERMASUK salinan
 *      kolaborasinya, tampil (jumlahnya sama dengan kolom Pekerjaan harian).
 *   2. Tanpa saringan teknisi    -> tetap konsolidasi (hanya laporan induk,
 *      salinan kolaborasi tidak jadi baris tersendiri).
 *   3. Salinan multi alat (multi_of) tetap tidak tampil walau disaring teknisi,
 *      supaya satu pekerjaan tidak terhitung/terlihat berkali-kali.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // Pelapor (pemilik laporan induk) dan kolaboratornya; keduanya reguler
    // supaya tampilan default halaman Laporan (Reguler) memuat keduanya.
    $this->pelapor = Technician::create([
        'name'   => 'Teknisi Pelapor Uji',
        'nik'    => 'PLP001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->kolaborator = Technician::create([
        'name'   => 'Teknisi Kolaborator Uji',
        'nik'    => 'KLB001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();

    $this->induk = Report::create([
        'technician_id'         => $this->pelapor->id,
        'creator_id'            => $this->pelapor->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'INDUK-01',
        'work_description'      => 'Perbaikan pompa kolaborasi uji',
        'work_duration_minutes' => 120,
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'submitted',
    ]);

    $this->salinanKolaborasi = Report::create([
        'technician_id'         => $this->kolaborator->id,
        'creator_id'            => $this->pelapor->id,
        'collaborator_of'       => $this->induk->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'KOLAB-01',
        'work_description'      => 'Perbaikan pompa kolaborasi uji',
        'work_duration_minutes' => 120,
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'submitted',
    ]);

    // Salinan multi alat milik pelapor: bukan baris tersendiri di daftar.
    $this->salinanMulti = Report::create([
        'technician_id'         => $this->pelapor->id,
        'creator_id'            => $this->pelapor->id,
        'multi_of'              => $this->induk->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'MULTI-01',
        'work_description'      => 'Perbaikan pompa kolaborasi uji',
        'work_duration_minutes' => 45,
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'submitted',
    ]);
});

test('daftar disaring ke teknisi kolaborator memuat salinan kolaborasinya', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&technician_id=' . $this->kolaborator->id);

    $respons->assertStatus(200)
        ->assertSee('KOLAB-01', false)
        // Laporan induk milik orang lain tidak ikut terbawa.
        ->assertDontSee('INDUK-01', false);
});

test('daftar disaring ke teknisi pelapor tetap satu baris induk tanpa salinan multi', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&technician_id=' . $this->pelapor->id);

    $respons->assertStatus(200)
        ->assertSee('INDUK-01', false)
        ->assertDontSee('KOLAB-01', false)
        ->assertDontSee('MULTI-01', false);
});

test('daftar umum tanpa saringan teknisi tetap mengonsolidasi kolaborasi', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal);

    $respons->assertStatus(200)
        ->assertSee('INDUK-01', false)
        ->assertSee('Berkolaborasi dengan:', false)
        ->assertSee('Teknisi Kolaborator Uji', false)
        ->assertDontSee('KOLAB-01', false)
        ->assertDontSee('MULTI-01', false);
});

test('jumlah baris teknisi kolaborator sama dengan kolom Pekerjaan rincian man hour harian', function () {
    Report::create([
        'technician_id'         => $this->kolaborator->id,
        'creator_id'            => $this->pelapor->id,
        'collaborator_of'       => $this->induk->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'KOLAB-02',
        'work_description'      => 'Perbaikan pompa kolaborasi uji',
        'work_duration_minutes' => 60,
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'submitted',
    ]);

    $harian = collect(app(ManHourService::class)->harian(
        $this->kolaborator,
        Carbon::parse($this->tanggal),
        Carbon::parse($this->tanggal)
    ))->first();

    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&technician_id=' . $this->kolaborator->id);

    $respons->assertStatus(200)
        ->assertSee('KOLAB-01', false)
        ->assertSee('KOLAB-02', false)
        ->assertDontSee('INDUK-01', false);

    expect($harian['jumlah_pekerjaan'])->toBe(2)
        ->and($harian['menit'])->toBe(180);
});
