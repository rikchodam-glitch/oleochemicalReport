<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Shift\ManHourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Alat/lokasi tambahan (pekerjaan multi item) pada halaman edit laporan.
 *
 * Aturan yang dijaga di sini:
 *   1. Setiap item tambahan menjadi baris salinan laporan sendiri (multi_of)
 *      dengan detail pekerjaan meniru laporan induk.
 *   2. Alat induk dan item yang sudah tercatat tidak bisa ditambahkan ulang.
 *   3. Area item dihitung dari alat/lokasi tambahan, bukan menyalin area induk.
 *   4. Baris salinan multi item TIDAK dihitung pada man hour (aturan yang sama
 *      dengan salinan buatan wizard Telegram).
 *   5. Laporan salinan tidak boleh menambah item sendiri; dikelola dari induk.
 *   6. Panel alat tambahan berada tepat di bawah alat/lokasi utama pada halaman
 *      detail (dulu jauh di bawah, setelah Kolaborasi Teknisi).
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTMULTI'], ['name' => 'PT Multi']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPMULTI'],
        ['name' => 'Departemen Multi']
    );
    $this->areaInduk = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAMULTI'],
        ['name' => 'Area Multi']
    );
    $this->areaLain = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREALAIN'],
        ['name' => 'Area Lain']
    );

    $this->flInduk = FunctionalLocation::create([
        'code'          => 'PTMULTI-DEPMULTI-AREAMULTI-01',
        'segment'       => '01',
        'name'          => 'Section Multi',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'area_id'       => $this->areaInduk->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->flLain = FunctionalLocation::create([
        'code'          => 'PTMULTI-DEPMULTI-AREALAIN-01',
        'segment'       => '01',
        'name'          => 'Section Lain',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'area_id'       => $this->areaLain->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->alatInduk = assetAlatTambahan([
        'equipment_no'  => '90010001',
        'description'   => 'Pompa Induk',
        'tech_ident_no' => 'MUL-P01',
        'area_id'       => $this->areaInduk->id,
        'funcloc_id'    => $this->flInduk->id,
        'functional_loc' => $this->flInduk->code,
    ]);

    $this->alatLain = assetAlatTambahan([
        'equipment_no'  => '90010002',
        'description'   => 'Pompa Cadangan',
        'tech_ident_no' => 'MUL-P02',
        'area_id'       => $this->areaLain->id,
        'funcloc_id'    => $this->flLain->id,
        'functional_loc' => $this->flLain->code,
    ]);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Multi',
        'nik'    => 'MUL001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

        // Tanggal tetap hari kerja (Kamis) supaya man hour normal tidak
        // berubah menjadi lembur saat suite dijalankan pada hari Minggu.
        $this->tanggal = '2026-10-15';

    $this->induk = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'MULTI-INDUK-01',
        'work_description'      => 'Pemeriksaan pompa beberapa alat',
        'work_duration_minutes' => 120,
        'area_id'               => $this->areaInduk->id,
        'funcloc_id'            => $this->flInduk->id,
        'asset_id'              => $this->alatInduk->id,
        'report_type'           => 'equipment_repair',
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'completed',
    ]);
});

// Helper pembuat aset uji pekerjaan multi item.
function assetAlatTambahan(array $data): Asset
{
    return Asset::create(array_merge([
        'equipment_no'   => '90019999',
        'description'    => 'Alat Uji Multi',
        'tech_ident_no'  => 'MUL-UJI',
        'functional_loc' => null,
        'funcloc_id'     => FunctionalLocation::query()->value('id'),
        'company_id'     => null,
        'department_id'  => null,
        'area_id'        => null,
        'status'         => 'active',
    ], $data));
}

test('halaman detail menampilkan bagian alat tambahan dengan satu jalur tambah', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->induk->id)
        ->assertStatus(200)
        ->assertSee('Alat / Lokasi Tambahan', false)
        // Sejak 08/10/2026 penambahan item memakai satu jalur hierarki
        // (PT -> Departemen -> Area -> Section -> Alat) lewat widget bersama
        // data-pilih-hirarki; dropdown Functional Location tunggal dan tombol
        // Tambah Lokasi yang lama sudah tidak ada.
        ->assertSee('data-pilih-hirarki', false)
        ->assertSee('data-url-tambah', false)
        ->assertSee('Tambah dari alat', false)
        ->assertDontSee('item_tambah_lokasi', false);
});

test('menambah alat tambahan membuat baris salinan dengan area dari alat itu', function () {
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['asset_id' => $this->alatLain->id])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('item.0.label', 'MUL-P02 - Pompa Cadangan');

    $salinan = Report::where('multi_of', $this->induk->id)->firstOrFail();

    expect($salinan->asset_id)->toBe($this->alatLain->id)
        ->and($salinan->technician_id)->toBe($this->teknisi->id)
        ->and($salinan->work_description)->toBe($this->induk->work_description)
        ->and($salinan->work_duration_minutes)->toBe(120)
        ->and($salinan->report_type)->toBe('equipment_repair')
        // Area mengikuti alat tambahan (bukan menyalin area induk).
        ->and($salinan->area_id)->toBe($this->areaLain->id)
        ->and($salinan->funcloc_id)->toBe($this->flLain->id)
        ->and($salinan->report_code)->not->toBe('MULTI-INDUK-01')
        ->and($salinan->collaborator_of)->toBeNull();
});

test('menambah item berupa lokasi memakai node funcloc dan area lokasi itu', function () {
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['funcloc_id' => $this->flLain->id])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $salinan = Report::where('multi_of', $this->induk->id)->firstOrFail();

    expect($salinan->asset_id)->toBeNull()
        ->and($salinan->funcloc_id)->toBe($this->flLain->id)
        ->and($salinan->area_id)->toBe($this->areaLain->id)
        ->and($salinan->report_type)->toBe('area_work');
});

test('alat utama dan item kembar tidak bisa ditambahkan', function () {
    // Alat utama laporan induk.
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['asset_id' => $this->alatInduk->id])
        ->assertStatus(422);

    // Item tambahan yang sama dua kali.
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['asset_id' => $this->alatLain->id])
        ->assertStatus(200);

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['asset_id' => $this->alatLain->id])
        ->assertStatus(422);

    expect(Report::where('multi_of', $this->induk->id)->count())->toBe(1);

    // Permintaan tanpa alat maupun lokasi ditolak.
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', [])
        ->assertStatus(422);
});

test('menghapus item tambahan hanya menghapus baris salinannya', function () {
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['asset_id' => $this->alatLain->id])
        ->assertStatus(200);

    $salinan = Report::where('multi_of', $this->induk->id)->firstOrFail();

    $this->actingAs($this->admin)
        ->deleteJson('/reports/' . $this->induk->id . '/multi-items/' . $salinan->id)
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('item', []);

    expect(Report::find($salinan->id))->toBeNull()
        ->and(Report::find($this->induk->id))->not->toBeNull();
});

test('menghapus baris yang bukan item laporan ini ditolak', function () {
    $lain = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'MULTI-LAIN-01',
        'work_description'      => 'Laporan lain untuk uji',
        'work_duration_minutes' => 30,
        'status'                => 'completed',
    ]);

    $this->actingAs($this->admin)
        ->deleteJson('/reports/' . $this->induk->id . '/multi-items/' . $lain->id)
        ->assertStatus(422);

    expect(Report::find($lain->id))->not->toBeNull();
});

test('baris salinan multi item tidak bisa menambah item sendiri', function () {
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['asset_id' => $this->alatLain->id])
        ->assertStatus(200);

    $salinan = Report::where('multi_of', $this->induk->id)->firstOrFail();

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $salinan->id . '/multi-items', ['funcloc_id' => $this->flLain->id])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Report::where('multi_of', $salinan->id)->count())->toBe(0);
});

test('baris salinan multi item tidak ikut dihitung pada man hour', function () {
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/multi-items', ['asset_id' => $this->alatLain->id])
        ->assertStatus(200);

    $harian = collect(app(ManHourService::class)->harian(
        $this->teknisi,
        Carbon::parse($this->tanggal),
        Carbon::parse($this->tanggal)
    ))->first();

    // Hanya laporan induk yang dihitung, salinan multi alat tidak.
    expect($harian['jumlah_pekerjaan'])->toBe(1)
        ->and($harian['menit'])->toBe(120);
});

test('panel alat tambahan berada tepat di bawah alat/lokasi utama', function () {
    // Pemicu (08/10/2026): bagian alat/lokasi tambahan dulu berada jauh di bawah
    // (setelah Kolaborasi Teknisi) sehingga lokasi pekerjaan dan alat
    // tambahannya terpisah. Sekarang letaknya tepat setelah pemilih lokasi utama.
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->induk->id)
        ->assertStatus(200)
        ->assertSeeInOrder(['Telusuri Hierarki Lokasi', 'Alat / Lokasi Tambahan'], false)
        ->assertSeeInOrder(['Alat / Lokasi Tambahan', 'Root Cause</h3>'], false);
});

