<?php

use App\Models\Asset;
use App\Models\Company;
use App\Models\FunctionalLocation;
use App\Models\ItAsset;
use App\Models\ItServiceRecord;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Halaman Service IT:
 *   - laporan continue membuka kasus, laporan done menutupnya;
 *   - tanggal memakai report_date;
 *   - done tanpa continue tidak membuat kasus;
 *   - entri manual, catatan, dan status rusak wajib tanggal selesai.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Service',
        'nik'    => 'SRV001',
        'status' => 'active',
    ]);

    $this->company = Company::create(['code' => 'SRV', 'name' => 'PT Service Uji']);

    $this->funcLoc = FunctionalLocation::create([
        'code'       => 'SRV-PT',
        'segment'    => 'SRV-PT',
        'name'       => 'PT Service Uji',
        'level'      => FunctionalLocation::LEVEL_SITE,
        'company_id' => $this->company->id,
        'is_active'  => true,
    ]);

    $this->itAsset = ItAsset::create([
        'device_type' => 'PC',
        'tag_name'    => 'PC-SRV-01',
        'name'        => 'PC Service Uji',
        'ip_address'  => '10.20.30.40',
        'plant'       => 'PT Service Uji',
        'department'  => 'IT',
        'location'    => 'Ruang Server',
        'status'      => 'active',
        'funcloc_id'  => $this->funcLoc->id,
    ]);

    // Baris cermin di assets: penghubung laporan ke it_asset.
    $this->asset = Asset::create([
        'description'    => 'PC Service Uji',
        'tech_ident_no'  => 'PC-SRV-01',
        'object_type'    => 'ZPM-COM',
        'equipment_type' => 'PC',
        'functional_loc' => $this->funcLoc->code,
        'funcloc_id'     => $this->funcLoc->id,
        'company_id'     => $this->company->id,
        'status'         => 'active',
        'data_source'    => 'it_sync',
        'it_asset_id'    => $this->itAsset->id,
    ]);
});

/**
 * Buat laporan untuk aset cermin IT.
 *
 * @param  string  $status  continue atau completed
 * @param  string  $tanggal Tanggal laporan (Y-m-d)
 * @param  array   $atribut Atribut tambahan
 * @return Report
 */
function laporanServiceIt(Asset $asset, Technician $technician, string $status, string $tanggal, array $atribut = []): Report
{
    return Report::create(array_merge([
        'technician_id'    => $technician->id,
        'report_date'      => $tanggal,
        'report_code'      => 'RPT-SRV-' . uniqid(),
        'work_description' => 'Service PC',
        'jenis_pekerjaan'  => 'IT',
        'status'           => $status,
        'asset_id'         => $asset->id,
    ], $atribut));
}

test('halaman service IT dapat dibuka', function () {
    $this->actingAs($this->admin)
        ->get(route('it-service.index'))
        ->assertOk()
        ->assertSee('Service IT');
});

test('laporan continue membuka kasus dan laporan done menutupnya memakai tanggal laporan', function () {
    $continue = laporanServiceIt($this->asset, $this->teknisi, 'continue', '2026-11-01');

    $this->actingAs($this->admin)->get(route('it-service.index'))->assertOk();

    $kasus = ItServiceRecord::firstOrFail();

    expect($kasus->it_asset_id)->toBe($this->itAsset->id)
        ->and($kasus->source)->toBe(ItServiceRecord::SOURCE_REPORT)
        ->and($kasus->report_id)->toBe($continue->id)
        ->and($kasus->entry_date->toDateString())->toBe('2026-11-01')
        ->and($kasus->finish_date)->toBeNull()
        ->and($kasus->status)->toBe(ItServiceRecord::STATUS_PROSES);

    $done = laporanServiceIt($this->asset, $this->teknisi, 'completed', '2026-11-03', [
        'continued_from_id' => $continue->id,
    ]);

    $this->get(route('it-service.index'))->assertOk();

    $kasus->refresh();

    expect($kasus->finish_date?->toDateString())->toBe('2026-11-03')
        ->and($kasus->status)->toBe(ItServiceRecord::STATUS_SELESAI)
        ->and($kasus->closed_by_report_id)->toBe($done->id)
        ->and($kasus->entry_date->toDateString())->toBe('2026-11-01');

    // Done diubah kembali menjadi continue: kasus dibuka lagi.
    $done->update(['status' => 'continue']);

    $this->get(route('it-service.index'))->assertOk();

    $kasus->refresh();

    expect($kasus->finish_date)->toBeNull()
        ->and($kasus->status)->toBe(ItServiceRecord::STATUS_PROSES)
        ->and($kasus->closed_by_report_id)->toBeNull()
        ->and($kasus->entry_date->toDateString())->toBe('2026-11-01');
});

test('laporan done tanpa laporan continue tidak membuat kasus', function () {
    laporanServiceIt($this->asset, $this->teknisi, 'completed', '2026-11-01');

    $this->actingAs($this->admin)->get(route('it-service.index'))->assertOk();

    expect(ItServiceRecord::count())->toBe(0);
});

test('laporan salinan kolaborasi atau multi alat tidak membuat kasus', function () {
    $induk = laporanServiceIt($this->asset, $this->teknisi, 'continue', '2026-11-01');
    laporanServiceIt($this->asset, $this->teknisi, 'continue', '2026-11-01', ['collaborator_of' => $induk->id]);
    laporanServiceIt($this->asset, $this->teknisi, 'continue', '2026-11-01', ['multi_of' => $induk->id]);

    $this->actingAs($this->admin)->get(route('it-service.index'))->assertOk();

    expect(ItServiceRecord::count())->toBe(1)
        ->and(ItServiceRecord::first()->report_id)->toBe($induk->id);
});

test('kasus manual bisa ditambah dan tidak boleh dobel selama masih terbuka', function () {
    $this->actingAs($this->admin)
        ->post(route('it-service.store'), [
            'it_asset_id' => $this->itAsset->id,
            'entry_date'  => '2026-11-02',
            'status'      => 'proses',
            'note'        => 'Menunggu spare part',
        ])
        ->assertRedirect(route('it-service.index'));

    $kasus = ItServiceRecord::firstOrFail();

    expect($kasus->source)->toBe(ItServiceRecord::SOURCE_MANUAL)
        ->and($kasus->entry_date->toDateString())->toBe('2026-11-02')
        ->and($kasus->finish_date)->toBeNull()
        ->and($kasus->note)->toBe('Menunggu spare part');

    $this->post(route('it-service.store'), [
        'it_asset_id' => $this->itAsset->id,
        'entry_date'  => '2026-11-03',
        'status'      => 'proses',
    ])->assertSessionHasErrors('it_asset_id');

    expect(ItServiceRecord::count())->toBe(1);
});

test('status rusak wajib tanggal selesai dan tanggal selesai tidak boleh sebelum tanggal masuk', function () {
    $kasus = ItServiceRecord::create([
        'it_asset_id' => $this->itAsset->id,
        'source'      => ItServiceRecord::SOURCE_MANUAL,
        'entry_date'  => '2026-11-02',
        'status'      => ItServiceRecord::STATUS_PROSES,
    ]);

    $this->actingAs($this->admin)
        ->put(route('it-service.update', $kasus), [
            'entry_date' => '2026-11-02',
            'status'     => 'rusak',
        ])
        ->assertSessionHasErrors('finish_date');

    expect($kasus->fresh()->status)->toBe(ItServiceRecord::STATUS_PROSES);

    $this->put(route('it-service.update', $kasus), [
        'entry_date'  => '2026-11-02',
        'finish_date' => '2026-11-05',
        'status'      => 'rusak',
        'note'        => 'Motherboard rusak',
    ])->assertRedirect(route('it-service.index'));

    $kasus->refresh();

    expect($kasus->status)->toBe(ItServiceRecord::STATUS_RUSAK)
        ->and($kasus->finish_date?->toDateString())->toBe('2026-11-05')
        ->and($kasus->note)->toBe('Motherboard rusak');

    $this->put(route('it-service.update', $kasus), [
        'entry_date'  => '2026-11-02',
        'finish_date' => '2026-11-01',
        'status'      => 'rusak',
    ])->assertSessionHasErrors('finish_date');
});

test('kasus dari laporan bisa ditandai rusak dengan tanggal selesai', function () {
    $continue = laporanServiceIt($this->asset, $this->teknisi, 'continue', '2026-11-01');

    $this->actingAs($this->admin)->get(route('it-service.index'))->assertOk();

    $kasus = ItServiceRecord::firstOrFail();

    $this->put(route('it-service.update', $kasus), [
        'status'      => 'rusak',
        'finish_date' => '2026-11-04',
        'note'        => 'Tidak bisa diperbaiki',
    ])->assertRedirect(route('it-service.index'));

    $kasus->refresh();

    expect($kasus->status)->toBe(ItServiceRecord::STATUS_RUSAK)
        ->and($kasus->finish_date?->toDateString())->toBe('2026-11-04')
        ->and($kasus->report_id)->toBe($continue->id)
        ->and($kasus->note)->toBe('Tidak bisa diperbaiki');
});

test('pencarian perangkat tidak menawarkan aset yang masih punya kasus terbuka', function () {
    $this->actingAs($this->admin)
        ->getJson(route('it-service.asset-search'))
        ->assertOk()
        ->assertJsonCount(1, 'assets')
        ->assertJsonFragment(['id' => $this->itAsset->id]);

    $kasus = ItServiceRecord::create([
        'it_asset_id' => $this->itAsset->id,
        'source'      => ItServiceRecord::SOURCE_MANUAL,
        'entry_date'  => '2026-11-02',
        'status'      => ItServiceRecord::STATUS_PROSES,
    ]);

    $this->getJson(route('it-service.asset-search'))
        ->assertOk()
        ->assertJsonCount(0, 'assets');

    $kasus->update([
        'finish_date' => '2026-11-04',
        'status'      => ItServiceRecord::STATUS_SELESAI,
    ]);

    $this->getJson(route('it-service.asset-search'))
        ->assertOk()
        ->assertJsonCount(1, 'assets');
});

