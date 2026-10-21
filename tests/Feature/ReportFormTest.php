<?php

use App\Jobs\KirimBroadcastKolaborasi;
use App\Models\Area;
use App\Models\Company;
use App\Models\Department;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ============================================================
// FORM LAPORAN WEB UNTUK TEKNISI (MVP)
// ============================================================

beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'    => 'Teknisi Form Laporan',
        'nik'     => 'FORM001',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->rekan = Technician::create([
        'name'    => 'Rekan Form Laporan',
        'nik'     => 'FORM002',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->akun = User::create([
        'name'          => 'Akun Form Laporan',
        'username'      => 'form-laporan-uji',
        'email'         => 'form-laporan-uji@oleochemical.pro',
        'password'      => bcrypt('rahasia123'),
        'role'          => 'mekanik',
        'technician_id' => $this->teknisi->id,
    ]);

    $perusahaan = Company::first() ?? Company::create([
        'code' => 'FORM-CO',
        'name' => 'Company Form Uji',
    ]);

    $departemen = Department::firstOrCreate(
        ['company_id' => $perusahaan->id, 'code' => 'FORM-DEPT'],
        ['name' => 'Departemen Form Uji']
    );

    $this->area = Area::firstOrCreate(
        ['department_id' => $departemen->id, 'code' => 'FORM-AREA'],
        ['name' => 'Area Form Uji']
    );

    Queue::fake();
});

test('teknisi login bisa membuka form laporan', function () {
    $this->actingAs($this->akun)
        ->get(route('laporan.saya.create'))
        ->assertOk()
        ->assertSee('Buat Laporan')
        ->assertSee('Teknisi Form Laporan')
        ->assertSee('Simpan laporan');
});

test('teknisi bisa menyimpan laporan area dan salinan kolaborator dibuat', function () {
    $this->actingAs($this->akun)
        ->post(route('laporan.saya.store'), [
            'report_date'           => '2026-10-20',
            'report_type'           => 'area_work',
            'work_classification'   => 'zpm1',
            'jenis_pekerjaan'       => 'Mekanik',
            'area_id'               => $this->area->id,
            'work_description'      => 'Pekerjaan area dari form web',
            'work_duration_minutes' => 90,
            'root_cause'            => 'Baut longgar',
            'finding'               => 'Getaran',
            'final_status'          => 'completed',
            'collaborator_ids'      => [$this->rekan->id],
        ])
        ->assertRedirect();

    $laporan = Report::query()->where('technician_id', $this->teknisi->id)->firstOrFail();

    expect($laporan->work_description)->toBe('Pekerjaan area dari form web')
        ->and($laporan->status)->toBe('completed')
        ->and($laporan->area_id)->toBe($this->area->id)
        ->and($laporan->report_code)->not->toBeNull();

    $salinan = Report::query()
        ->where('collaborator_of', $laporan->id)
        ->where('technician_id', $this->rekan->id)
        ->first();

    expect($salinan)->not->toBeNull();

    if (class_exists(KirimBroadcastKolaborasi::class)) {
        Queue::assertPushed(KirimBroadcastKolaborasi::class);
    }
});

test('laporan equipment wajib memilih alat', function () {
    $this->actingAs($this->akun)
        ->post(route('laporan.saya.store'), [
            'report_date'           => '2026-10-20',
            'report_type'           => 'equipment_repair',
            'work_description'      => 'Perbaikan tanpa alat',
            'work_duration_minutes' => 30,
            'final_status'          => 'completed',
        ])
        ->assertSessionHasErrors('asset_id');
});

test('dCM wajib mengisi sesuai rencana', function () {
    $this->actingAs($this->akun)
        ->post(route('laporan.saya.store'), [
            'report_date'           => '2026-10-20',
            'report_type'           => 'area_work',
            'jenis_pekerjaan'       => 'dCM',
            'area_id'               => $this->area->id,
            'work_description'      => 'dCM tanpa rencana',
            'work_duration_minutes' => 30,
            'final_status'          => 'completed',
        ])
        ->assertSessionHasErrors('sesuai_rencana');
});

test('foto dokumentasi tersimpan pada laporan', function () {
    Storage::fake(config('telegram.photo_disk', 'public'));

    $this->actingAs($this->akun)
        ->post(route('laporan.saya.store'), [
            'report_date'           => '2026-10-20',
            'report_type'           => 'area_work',
            'area_id'               => $this->area->id,
            'work_description'      => 'Pekerjaan dengan foto',
            'work_duration_minutes' => 60,
            'final_status'          => 'completed',
            'photos_documentation'  => [UploadedFile::fake()->image('dokumentasi.jpg')],
        ])
        ->assertRedirect();

    $laporan = Report::query()->where('technician_id', $this->teknisi->id)->firstOrFail();

    expect($laporan->photo_documentation)->toHaveCount(1);
});

test('akun tanpa teknisi tidak bisa memakai form laporan', function () {
    $tanpaTeknisi = User::create([
        'name'     => 'Akun Tanpa Teknisi Form',
        'username' => 'form-tanpa-teknisi',
        'email'    => 'form-tanpa-teknisi@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($tanpaTeknisi)
        ->get(route('laporan.saya.create'))
        ->assertForbidden();
});
