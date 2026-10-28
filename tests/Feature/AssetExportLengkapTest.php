<?php

use App\Models\Asset;
use App\Models\Company;
use App\Models\FunctionalLocation;
use App\Models\ItAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    Storage::fake('public');
    Storage::fake('cctv_snapshots');

    $this->company = Company::create(['code' => 'EXP', 'name' => 'PT Export Uji']);

    $this->funcLoc = FunctionalLocation::create([
        'code'       => 'EXP-PT',
        'segment'    => 'EXP-PT',
        'name'       => 'PT Export Uji',
        'level'      => 0,
        'company_id' => $this->company->id,
        'is_active'  => true,
    ]);

    $snapshot = UploadedFile::fake()->image('snapshot.jpg', 160, 90);
    Storage::disk('cctv_snapshots')->put('snapshots/cctv/501/snapshot.jpg', $snapshot->getContent());

    $this->itCctv = ItAsset::create([
        'device_type'        => 'CCTV',
        'tag_name'           => 'CCTV-EXP-01',
        'name'               => 'Kamera Gudang Export',
        'brand'              => 'Hikvision',
        'model'              => 'DS-2CD',
        'serial_number'      => 'SN-CCTV-01',
        'ip_address'         => '10.10.10.21',
        'plant'              => 'Plant Export',
        'department'         => 'Produksi',
        'location'           => 'Gudang',
        'status'             => 'active',
        'spec'               => [
            'show'       => 'NVR EXP 1',
            'channel'    => 'D21',
            'pointing'   => 'Pintu Gudang',
            'header'     => 'CCTV-EXP-01',
            'pic'        => 'Security',
            'connect_to' => 'Switch Gudang',
        ],
        'last_ping_status'   => 'online',
        'last_ping_latency'  => 12,
        'last_ping_at'       => now(),
        'last_snapshot_path' => 'snapshots/cctv/501/snapshot.jpg',
        'last_snapshot_at'   => now(),
    ]);

    $this->assetCctv = Asset::create([
        'description'     => 'Kamera Gudang Export',
        'tech_ident_no'   => 'CCTV-EXP-01',
        'object_type'     => 'ZPM-CTV',
        'equipment_type'  => 'CCTV',
        'functional_loc'  => $this->funcLoc->code,
        'funcloc_id'      => $this->funcLoc->id,
        'company_id'      => $this->company->id,
        'status'          => 'active',
        'data_source'     => 'it_sync',
        'it_asset_id'     => $this->itCctv->id,
    ]);

    $this->itSwitch = ItAsset::create([
        'device_type'   => 'SWITCH_POE_UNMANAGED',
        'tag_name'      => 'SWITCH-EXP-01',
        'name'          => 'Switch Unmanaged Export',
        'brand'         => 'TP-Link',
        'model'         => 'TL-SF',
        'serial_number' => 'SN-SW-01',
        'ip_address'    => '10.10.10.22',
        'plant'         => 'Plant Export',
        'department'    => 'Produksi',
        'location'      => 'Gudang',
        'status'        => 'active',
        'spec'          => [
            'connect_to' => 'Switch Utama',
            'vlan'       => 'VLAN 20',
            'port'       => '8 port',
        ],
        'last_ping_status' => 'online',
    ]);

    $this->assetSwitch = Asset::create([
        'description'    => 'Switch Unmanaged Export',
        'tech_ident_no'  => 'SWITCH-EXP-01',
        'object_type'    => 'ZPM-SWU',
        'equipment_type' => 'SWITCH POE',
        'functional_loc' => $this->funcLoc->code,
        'funcloc_id'     => $this->funcLoc->id,
        'company_id'     => $this->company->id,
        'status'         => 'active',
        'data_source'    => 'it_sync',
        'it_asset_id'    => $this->itSwitch->id,
    ]);

    $foto = UploadedFile::fake()->image('foto.jpg', 120, 120);
    Storage::disk('public')->put('assets/photos/999/foto.jpg', $foto->getContent());

    $this->assetPompa = Asset::create([
        'description'    => 'Pompa Export Uji',
        'tech_ident_no'  => 'PUMP-EXP-01',
        'equipment_type' => 'PUMP',
        'functional_loc' => $this->funcLoc->code,
        'funcloc_id'     => $this->funcLoc->id,
        'company_id'     => $this->company->id,
        'status'         => 'active',
        'data_source'    => 'manual',
        'photos'         => ['assets/photos/999/foto.jpg'],
    ]);
});

/**
 * Ambil header dan nilai kolom dari sheet hasil export.
 *
 * @param  string $isi File xlsx
 * @return array{sheet: \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet, headers: array<int, string>}
 */
function bacaExportAssets(string $isi): array
{
    $path = tempnam(sys_get_temp_dir(), 'asset-export-') . '.xlsx';
    file_put_contents($path, $isi);

    $spreadsheet = IOFactory::load($path);
    $sheet       = $spreadsheet->getActiveSheet();
    $jumlahKolom = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $headers     = [];

    for ($i = 1; $i <= $jumlahKolom; $i++) {
        $headers[$i] = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($i) . '7')->getValue();
    }

    unlink($path);

    return ['sheet' => $sheet, 'headers' => $headers];
}

/**
 * Cari nomor kolom berdasarkan judul header.
 *
 * @param  array<int, string> $headers
 * @param  string             $judul
 * @return int|null
 */
function kolomExport(array $headers, string $judul): ?int
{
    $hasil = array_search($judul, $headers, true);

    return $hasil === false ? null : (int) $hasil;
}

test('export lengkap CCTV mengikuti filter dan menyertakan snapshot', function () {
    $isi = $this->actingAs($this->admin)
        ->get(route('assets.export-excel-lengkap', ['equipment_type' => 'CCTV']))
        ->assertOk()
        ->streamedContent();

    ['sheet' => $sheet, 'headers' => $headers] = bacaExportAssets($isi);

    $kolomTech   = kolomExport($headers, 'Tech Ident No');
    $kolomNvr    = kolomExport($headers, 'NVR (Show)');
    $kolomKanall = kolomExport($headers, 'Channel');
    $kolomGambar = kolomExport($headers, 'Snapshot CCTV');
    $kolomLink   = kolomExport($headers, 'Link Snapshot');
    $kolomAmbil  = kolomExport($headers, 'Snapshot Diambil');

    expect((string) $sheet->getCell('A1')->getValue())->toContain('CCTV')
        ->and((string) $sheet->getCell('A5')->getValue())->toContain('Jumlah: 1 asset')
        ->and($kolomTech)->not->toBeNull()
        ->and($kolomNvr)->not->toBeNull()
        ->and($kolomKanall)->not->toBeNull()
        ->and($kolomGambar)->not->toBeNull()
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomTech) . '8')->getValue())->toBe('CCTV-EXP-01')
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomNvr) . '8')->getValue())->toBe('NVR EXP 1')
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomKanall) . '8')->getValue())->toBe('D21')
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomLink) . '8')->getValue())->toContain('/storage/snapshots/cctv/501/snapshot.jpg')
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomAmbil) . '8')->getValue())->not->toBe('')
        ->and($sheet->getDrawingCollection())->toHaveCount(1)
        ->and($sheet->getRowDimension(8)->getRowHeight())->toBeGreaterThan(40)
        // Hanya CCTV yang masuk; switch dan pompa tidak terbawa.
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomTech) . '9')->getValue())->toBe('');
});

test('export lengkap switch unmanaged memakai kolom switch dan tidak membawa CCTV', function () {
    $isi = $this->actingAs($this->admin)
        ->get(route('assets.export-excel-lengkap', ['equipment_type' => 'SWITCH POE']))
        ->assertOk()
        ->streamedContent();

    ['sheet' => $sheet, 'headers' => $headers] = bacaExportAssets($isi);

    $kolomTech    = kolomExport($headers, 'Tech Ident No');
    $kolomConnect = kolomExport($headers, 'Connect To');
    $kolomVlan    = kolomExport($headers, 'VLAN');
    $kolomPort    = kolomExport($headers, 'Port');
    $kolomNvr     = kolomExport($headers, 'NVR (Show)');

    expect((string) $sheet->getCell('A1')->getValue())->toContain('SWITCH')
        ->and((string) $sheet->getCell('A5')->getValue())->toContain('Jumlah: 1 asset')
        ->and($kolomConnect)->not->toBeNull()
        ->and($kolomVlan)->not->toBeNull()
        ->and($kolomPort)->not->toBeNull()
        ->and($kolomNvr)->toBeNull()
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomTech) . '8')->getValue())->toBe('SWITCH-EXP-01')
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomConnect) . '8')->getValue())->toBe('Switch Utama')
        ->and((string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomVlan) . '8')->getValue())->toBe('VLAN 20')
        ->and($sheet->getDrawingCollection())->toHaveCount(0);
});

test('export lengkap tanpa filter ikut memuat baris cermin IT dan foto aset manual', function () {
    $isi = $this->actingAs($this->admin)
        ->get(route('assets.export-excel-lengkap'))
        ->assertOk()
        ->streamedContent();

    ['sheet' => $sheet, 'headers' => $headers] = bacaExportAssets($isi);

    $kolomTech = kolomExport($headers, 'Tech Ident No');
    $kode      = [];

    for ($baris = 8; $baris <= $sheet->getHighestRow(); $baris++) {
        $nilai = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($kolomTech) . $baris)->getValue();

        if ($nilai !== '') {
            $kode[] = $nilai;
        }
    }

    expect((string) $sheet->getCell('A5')->getValue())->toContain('Jumlah: 3 asset')
        ->and($kode)->toContain('CCTV-EXP-01')
        ->and($kode)->toContain('SWITCH-EXP-01')
        ->and($kode)->toContain('PUMP-EXP-01')
        ->and($sheet->getDrawingCollection())->toHaveCount(2);
});
