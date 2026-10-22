<?php

use App\Models\CmEquipment;
use App\Models\CmMonthlyTracking;
use App\Models\User;
use App\Services\CmMasterEquipmentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga sinkronisasi daftar equipment CM dengan sheet "Master Equipment".
 *
 * Latar (permintaan user 21/10/2026): CT PUMP 1 & 2 di PT. CES sudah tidak
 * perlu dimonitoring. User terus memperbarui sheet "Master Equipment" di
 * spreadsheet Data CM (tagging baru ditambah, tagging lama dihapus), sehingga
 * setiap sync wajib ikut memperbarui `cm_equipment`: tag baru dibuat, tag yang
 * hilang dinonaktifkan — tetapi histori reading/finding tidak boleh terhapus.
 */

/**
 * Baris sheet Master Equipment dari daftar tag.
 *
 * @param  array<int, array{tag:string, pt?:string, plant?:string}> $items
 * @return array<int, array<int, string>>
 */
function cmBarisMaster(array $items): array
{
    $rows = [['PT Location', 'Plant', 'Equipment Tag']];

    foreach ($items as $item) {
        $rows[] = [
            $item['pt'] ?? 'PT UJI',
            $item['plant'] ?? 'PLANT UJI',
            $item['tag'],
        ];
    }

    return $rows;
}

/**
 * Sepuluh equipment pengisi supaya jumlah baris master melewati batas minimal
 * deaktivasi (MIN_ROWS_FOR_DEACTIVATION) — tanpa ini, guard sengaja tidak
 * menonaktifkan apa pun.
 *
 * @return array<int, array{tag:string, pt:string, plant:string}>
 */
function cmTagPengisi(): array
{
    $items = [];

    for ($i = 1; $i <= 10; $i++) {
        $items[] = [
            'tag'   => 'FILLER-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'pt'    => 'PT UJI',
            'plant' => 'PLANT UJI',
        ];
    }

    return $items;
}

/**
 * Buat equipment pengisi di database agar tidak dihitung sebagai "dibuat".
 */
function cmBuatPengisi(): void
{
    foreach (cmTagPengisi() as $item) {
        CmEquipment::create([
            'equipment_tag' => $item['tag'],
            'pt_location'   => $item['pt'],
            'plant'         => $item['plant'],
        ]);
    }
}

test('tag yang dihapus dari Master Equipment dinonaktifkan tanpa menghapus histori', function () {
    $dihapus = CmEquipment::create([
        'equipment_tag' => 'CT PUMP 1',
        'pt_location'   => 'PT. CES',
        'plant'         => 'CES 2',
    ]);

    $tetap = CmEquipment::create([
        'equipment_tag' => '6842CT01',
        'pt_location'   => 'PT. CES',
        'plant'         => 'CES 2',
    ]);

    cmBuatPengisi();

    $rows = cmBarisMaster(array_merge(cmTagPengisi(), [
        ['tag' => '6842CT01', 'pt' => 'PT. CES', 'plant' => 'CES 2'],
        ['tag' => 'TAG-BARU', 'pt' => 'PT. CES', 'plant' => 'CES 2'],
    ]));

    $summary = app(CmMasterEquipmentSyncService::class)->syncRows($rows);

    expect($summary['dinonaktifkan'])->toBe(1)
        ->and($summary['tags_dinonaktifkan'])->toContain('CT PUMP 1')
        ->and($summary['dibuat'])->toBe(1)
        ->and($summary['tags_dibuat'])->toContain('TAG-BARU');

    $dihapus->refresh();
    expect($dihapus->is_active)->toBeFalse()
        ->and($dihapus->in_master)->toBeFalse()
        ->and($dihapus->deactivated_at)->not->toBeNull();

    // Histori tetap ada: equipment tidak dihapus dari tabel.
    expect(CmEquipment::where('equipment_tag', 'CT PUMP 1')->exists())->toBeTrue();

    expect($tetap->refresh()->is_active)->toBeTrue();

    $baru = CmEquipment::where('equipment_tag', 'TAG-BARU')->firstOrFail();
    expect($baru->is_active)->toBeTrue()
        ->and($baru->in_master)->toBeTrue()
        ->and($baru->pt_location)->toBe('PT. CES');
});

test('perbandingan tag tidak membedakan huruf besar/kecil dan spasi ganda', function () {
    $equipment = CmEquipment::create([
        'equipment_tag' => 'SC-Rework A',
        'pt_location'   => 'PT. NPA',
        'plant'         => 'NPA 2',
    ]);

    cmBuatPengisi();

    $rows = cmBarisMaster(array_merge(cmTagPengisi(), [
        ['tag' => 'SC-REWORK   A', 'pt' => 'PT. NPA', 'plant' => 'NPA 2'],
    ]));

    $summary = app(CmMasterEquipmentSyncService::class)->syncRows($rows);

    expect($summary['dinonaktifkan'])->toBe(0)
        ->and($summary['dibuat'])->toBe(0)
        ->and(CmEquipment::count())->toBe(11);

    $equipment->refresh();
    expect($equipment->is_active)->toBeTrue()
        ->and($equipment->in_master)->toBeTrue()
        ->and($equipment->equipment_tag)->toBe('SC-Rework A');
});

test('equipment baru dari reading yang belum ada di master tidak ikut dinonaktifkan', function () {
    $baru = CmEquipment::create([
        'equipment_tag' => 'TAG-INPUT-BARU',
        'pt_location'   => 'PT. CES',
        'plant'         => 'CES 2',
        'in_master'     => false,
    ]);

    cmBuatPengisi();

    $summary = app(CmMasterEquipmentSyncService::class)->syncRows(cmBarisMaster(cmTagPengisi()));

    expect($summary['dinonaktifkan'])->toBe(0);
    expect($baru->refresh()->is_active)->toBeTrue();
});

test('sheet master yang barisnya terlalu sedikit tidak menonaktifkan equipment', function () {
    $lain = CmEquipment::create([
        'equipment_tag' => 'CT PUMP 2',
        'pt_location'   => 'PT. CES',
        'plant'         => 'CES 2',
    ]);

    $rows = cmBarisMaster([['tag' => '6842CT01', 'pt' => 'PT. CES', 'plant' => 'CES 2']]);

    $summary = app(CmMasterEquipmentSyncService::class)->syncRows($rows);

    expect($summary['deaktivasi_dilewati'])->toBeTrue()
        ->and($summary['dinonaktifkan'])->toBe(0);
    expect($lain->refresh()->is_active)->toBeTrue();
});

test('equipment yang ditambahkan kembali ke master diaktifkan lagi', function () {
    $equipment = CmEquipment::create([
        'equipment_tag'  => 'CT PUMP 1',
        'pt_location'    => 'PT. CES',
        'plant'          => 'CES 2',
        'is_active'      => false,
        'in_master'      => false,
        'deactivated_at' => now()->subMonth(),
    ]);

    cmBuatPengisi();

    $rows = cmBarisMaster(array_merge(cmTagPengisi(), [
        ['tag' => 'CT PUMP 1', 'pt' => 'PT. CES', 'plant' => 'CES 2'],
    ]));

    $summary = app(CmMasterEquipmentSyncService::class)->syncRows($rows);

    expect($summary['diaktifkan'])->toBe(1)
        ->and($summary['tags_diaktifkan'])->toContain('CT PUMP 1');

    $equipment->refresh();
    expect($equipment->is_active)->toBeTrue()
        ->and($equipment->in_master)->toBeTrue()
        ->and($equipment->deactivated_at)->toBeNull();
});

test('halaman Monitoring Bulanan hanya menampilkan equipment yang masih aktif', function () {
    $admin = User::create([
        'name'     => 'Admin Uji Master CM',
        'username' => 'adminujimastercm',
        'email'    => 'adminujimastercm@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'admin',
    ]);

    $aktif = CmEquipment::create([
        'equipment_tag' => 'TAG-AKTIF-01',
        'pt_location'   => 'PT. CES',
        'plant'         => 'CES 2',
    ]);

    $nonaktif = CmEquipment::create([
        'equipment_tag'  => 'CT PUMP 1',
        'pt_location'    => 'PT. CES',
        'plant'          => 'CES 2',
        'is_active'      => false,
        'in_master'      => false,
        'deactivated_at' => now(),
    ]);

    CmMonthlyTracking::create([
        'cm_equipment_id' => $aktif->id,
        'tahun'           => 2026,
        'bulan'           => 10,
        'status'          => 'sudah',
    ]);

    CmMonthlyTracking::create([
        'cm_equipment_id' => $nonaktif->id,
        'tahun'           => 2026,
        'bulan'           => 10,
        'status'          => 'belum',
    ]);

    $response = $this->actingAs($admin)->get('/cm/monitoring?tahun=2026');

    $response->assertOk()
        ->assertSee('TAG-AKTIF-01')
        ->assertDontSee('CT PUMP 1');
});
