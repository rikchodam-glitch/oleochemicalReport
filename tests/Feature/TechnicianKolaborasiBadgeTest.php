<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lencana kolaborasi pada halaman detail teknisi (Tabel Pekerjaan Harian).
 *
 * Latar: baris salinan kolaborasi (`collaborator_of` terisi) muncul di halaman
 * detail teknisi KOLABORATOR, sehingga pembaca tidak tahu siapa yang membuat
 * pekerjaan itu. Karena itu lencana "KOLABORASI" di baris tersebut WAJIB
 * menyebut nama PEMBUAT laporan (creator_id laporan induk).
 *
 * Aturan yang dijaga di sini:
 *   1. Salinan kolaborasi -> lencana "KOLABORASI : <nama pembuat>".
 *   2. Laporan biasa (bukan salinan) -> tidak ada lencana kolaborasi.
 *   3. Data lama tanpa creator_id -> nama pembuat diambil dari laporan induk,
 *      BUKAN dari teknisi kolaborator pemilik baris salinan.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->pembuat = Technician::create([
        'name'   => 'Teknisi Pembuat Lencana',
        'nik'    => 'LNC001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->kolaborator = Technician::create([
        'name'   => 'Teknisi Kolaborator Lencana',
        'nik'    => 'LNC002',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();

    $this->induk = Report::create([
        'technician_id'         => $this->pembuat->id,
        'creator_id'            => $this->pembuat->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'LENCANA-INDUK',
        'work_description'      => 'Pekerjaan lencana kolaborasi',
        'work_duration_minutes' => 120,
        'status'                => 'submitted',
    ]);

    $this->salinan = Report::create([
        'technician_id'         => $this->kolaborator->id,
        'creator_id'            => $this->pembuat->id,
        'collaborator_of'       => $this->induk->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'LENCANA-SALINAN',
        'work_description'      => 'Pekerjaan lencana kolaborasi',
        'work_duration_minutes' => 120,
        'status'                => 'submitted',
    ]);
});

test('halaman teknisi kolaborator menampilkan lencana kolaborasi beserta nama pembuat', function () {
    $respons = $this->actingAs($this->admin)->get('/technicians/' . $this->kolaborator->id);

    $respons->assertStatus(200)
        ->assertSee('LENCANA-SALINAN', false)
        ->assertSee('KOLABORASI : Teknisi Pembuat Lencana', false);
});

test('halaman teknisi pembuat tidak menampilkan lencana kolaborasi pada laporan biasa', function () {
    $respons = $this->actingAs($this->admin)->get('/technicians/' . $this->pembuat->id);

    $respons->assertStatus(200)
        ->assertSee('LENCANA-INDUK', false)
        // Laporan induk bukan salinan kolaborasi, jadi tidak ada lencana.
        ->assertDontSee('KOLABORASI :', false);
});

test('salinan kolaborasi tanpa creator_id memakai teknisi laporan induk sebagai pembuat', function () {
    $this->salinan->update(['creator_id' => null]);

    $indukTanpaCreator = Report::create([
        'technician_id'         => $this->pembuat->id,
        'creator_id'            => null,
        'report_date'           => $this->tanggal,
        'report_code'           => 'LENCANA-INDUK-2',
        'work_description'      => 'Pekerjaan lencana tanpa creator',
        'work_duration_minutes' => 60,
        'status'                => 'submitted',
    ]);

    $salinanTanpaCreator = Report::create([
        'technician_id'         => $this->kolaborator->id,
        'creator_id'            => null,
        'collaborator_of'       => $indukTanpaCreator->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'LENCANA-SALINAN-2',
        'work_description'      => 'Pekerjaan lencana tanpa creator',
        'work_duration_minutes' => 60,
        'status'                => 'submitted',
    ]);

    // Jalur cadangan: creator_id kosong -> dibaca dari laporan induk.
    expect($salinanTanpaCreator->fresh()->nama_pembuat)->toBe('Teknisi Pembuat Lencana');

    $respons = $this->actingAs($this->admin)->get('/technicians/' . $this->kolaborator->id);

    $respons->assertStatus(200)
        ->assertSee('KOLABORASI : Teknisi Pembuat Lencana', false)
        // Lencana tidak boleh menyebut kolaborator sebagai pembuat.
        ->assertDontSee('KOLABORASI : Teknisi Kolaborator Lencana', false);
});

test('accessor nama pembuat mengembalikan pemilik laporan untuk laporan biasa', function () {
    expect($this->induk->fresh()->nama_pembuat)->toBe('Teknisi Pembuat Lencana')
        ->and($this->salinan->fresh()->nama_pembuat)->toBe('Teknisi Pembuat Lencana');
});
