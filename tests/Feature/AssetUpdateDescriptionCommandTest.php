<?php

use App\Models\Asset;
use App\Models\Company;
use App\Models\FunctionalLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Tulis berkas Excel Master List sementara untuk menguji command
 * assets:perbarui-deskripsi (kolom mengikuti berkas asli: Kode Alat dan
 * Description).
 *
 * @param  array<int, array{kode: string, deskripsi: string, eq_no?: string, funcloc?: string}>  $baris  Baris data
 * @return string  Path berkas sementara
 */
function tulisMasterListUji(array $baris): string
{
    $buku   = new Spreadsheet();
    $lembar = $buku->getActiveSheet();
    $lembar->setTitle('List Equipment');
    $lembar->fromArray(['No', 'Kode Alat', 'Functional Location', 'Equipment No.', 'Description'], null, 'A1');

    $r = 2;

    foreach ($baris as $no => $data) {
        $lembar->setCellValue('A' . $r, $no + 1);
        $lembar->setCellValue('B' . $r, $data['kode']);
        $lembar->setCellValue('C' . $r, $data['funcloc'] ?? '');
        $lembar->setCellValueExplicit('D' . $r, (string) ($data['eq_no'] ?? ''), DataType::TYPE_STRING);
        $lembar->setCellValue('E' . $r, $data['deskripsi']);
        $r++;
    }

    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uji-master-list-' . uniqid() . '.xlsx';
    (new Xlsx($buku))->save($path);
    $buku->disconnectWorksheets();

    return $path;
}

/**
 * Kode Functional Location uji yang dipakai semua aset pada berkas ini.
 *
 * @return int  Id Functional Location uji
 */
function funclocUjiId(): int
{
    return (int) FunctionalLocation::query()->where('code', 'NPA-PROD-BE01-7811')->value('id');
}

/**
 * Buat aset uji dengan atribut baku.
 *
 * @param  array<string, mixed>  $data  Atribut aset
 * @return Asset
 */
function asetUji(array $data): Asset
{
    return Asset::create(array_merge([
        'funcloc_id'  => funclocUjiId(),
        'status'      => 'active',
        'data_source' => 'import_excel',
    ], $data));
}

beforeEach(function () {
    $this->npa = Company::firstOrCreate(['code' => 'NPA'], ['name' => 'Nutripro Prima Asia']);
    $this->epe = Company::firstOrCreate(['code' => 'EPE'], ['name' => 'EPE']);

    $this->funcloc = FunctionalLocation::create([
        'code'       => 'NPA-PROD-BE01-7811',
        'segment'    => '7811',
        'name'       => 'Blending',
        'level'      => FunctionalLocation::LEVEL_SECTION,
        'company_id' => $this->npa->id,
        'is_active'  => true,
    ]);
});

afterEach(function () {
    foreach ($this->berkasUji ?? [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

test('mode simulasi hanya melaporkan perubahan dan tidak menulis deskripsi', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'AGT02A',
        'equipment_no'  => '70000038',
        'description'   => 'BLENDING',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'AGT02A', 'deskripsi' => 'Agitator BHT', 'eq_no' => '70000038'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', ['berkas' => $berkas, '--pt' => 'NPA'])
        ->expectsOutputToContain('MODE SIMULASI')
        ->assertSuccessful();

    expect($aset->fresh()->description)->toBe('BLENDING');
});

test('flag execute memperbarui deskripsi berdasarkan Kode Alat', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'AGT02A',
        'equipment_no'  => '70000038',
        'description'   => 'BLENDING',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'AGT02A', 'deskripsi' => 'Agitator BHT', 'eq_no' => '70000038'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', ['berkas' => $berkas, '--pt' => 'NPA', '--execute' => true])
        ->expectsOutputToContain('MODE SIMPAN')
        ->assertSuccessful();

    expect($aset->fresh()->description)->toBe('Agitator BHT');
});

test('Kode Alat dibandingkan tanpa peduli besar kecil huruf dan spasi tepi', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'FNCY01',
        'equipment_no'  => '70000091',
        'description'   => 'MILLING',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => '  fncy01 ', 'deskripsi' => 'Centrifuge', 'eq_no' => '70000091'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', ['berkas' => $berkas, '--pt' => 'NPA', '--execute' => true])
        ->assertSuccessful();

    expect($aset->fresh()->description)->toBe('Centrifuge');
});

test('Kode Alat yang tidak ada dicocokkan lewat cadangan Equipment No.', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKALCY01',
        'equipment_no'  => '70000092',
        'description'   => 'MILLING&PACKAGING',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'ALCY01', 'deskripsi' => 'Centrifuge Separator', 'eq_no' => '70000092'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', ['berkas' => $berkas, '--pt' => 'NPA', '--execute' => true])
        ->expectsOutputToContain('MODE SIMPAN')
        ->assertSuccessful();

    expect($aset->fresh()->description)->toBe('Centrifuge Separator');
});

test('opsi kode-saja melewati baris yang Kode Alat-nya tidak ada', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKALCY01',
        'equipment_no'  => '70000092',
        'description'   => 'MILLING&PACKAGING',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'ALCY01', 'deskripsi' => 'Centrifuge Separator', 'eq_no' => '70000092'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'     => $berkas,
        '--pt'       => 'NPA',
        '--kode-saja' => true,
        '--execute'  => true,
    ])
        ->expectsOutputToContain('Kode alat tidak ditemukan')
        ->assertSuccessful();

    expect($aset->fresh()->description)->toBe('MILLING&PACKAGING');
});

test('Kode Alat dobel di database diputuskan lewat Equipment No.', function () {
    $satu = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'AGT02A',
        'equipment_no'  => '70000021',
        'description'   => 'BLENDING',
    ]);

    $dua = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'AGT02A',
        'equipment_no'  => '70000038',
        'description'   => 'BLENDING',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'AGT02A', 'deskripsi' => 'Agitator BHT', 'eq_no' => '70000038'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', ['berkas' => $berkas, '--pt' => 'NPA', '--execute' => true])
        ->expectsOutputToContain('via Equipment No.')
        ->assertSuccessful();

    expect($satu->fresh()->description)->toBe('BLENDING')
        ->and($dua->fresh()->description)->toBe('Agitator BHT');
});

test('aset PT lain tidak tersentuh karena dibatasi opsi pt', function () {
    $npa = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'AGT02A',
        'equipment_no'  => '70000038',
        'description'   => 'BLENDING',
    ]);

    $epe = asetUji([
        'company_id'    => $this->epe->id,
        'tech_ident_no' => 'AGT02A',
        'equipment_no'  => '10000038',
        'description'   => 'Deskripsi lama EPE',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'AGT02A', 'deskripsi' => 'Agitator BHT', 'eq_no' => '70000038'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', ['berkas' => $berkas, '--pt' => 'NPA', '--execute' => true])
        ->assertSuccessful();

    expect($npa->fresh()->description)->toBe('Agitator BHT')
        ->and($epe->fresh()->description)->toBe('Deskripsi lama EPE');
});

test('Kode Alat ganda di berkas diputuskan lewat Equipment No.', function () {
    $esk = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKSC04',
        'equipment_no'  => '70000101',
        'description'   => 'MILLING&PACKAGING',
    ]);

    $sc = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'SC04',
        'equipment_no'  => '70000247',
        'description'   => 'REACTIONSYSTEM',
    ]);

    // Dua mesin berbeda ditulis dengan Kode Alat yang sama di berkas.
    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'SC04', 'deskripsi' => 'Screw Conveyor 04', 'eq_no' => '70000101'],
        ['kode' => 'SC04', 'deskripsi' => 'Screw Conveyor H06', 'eq_no' => '70000247'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', ['berkas' => $berkas, '--pt' => 'NPA', '--execute' => true])
        ->expectsOutputToContain('Kode Alat ganda di berkas')
        ->assertSuccessful();

    expect($esk->fresh()->description)->toBe('Screw Conveyor 04')
        ->and($sc->fresh()->description)->toBe('Screw Conveyor H06');
});

test('opsi laporan menulis berkas CSV berisi baris perubahan', function () {
    asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'AGT02A',
        'equipment_no'  => '70000038',
        'description'   => 'BLENDING',
    ]);

    $this->berkasUji[] = $csv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uji-laporan-' . uniqid() . '.csv';

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'AGT02A', 'deskripsi' => 'Agitator BHT', 'eq_no' => '70000038'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'    => $berkas,
        '--pt'      => 'NPA',
        '--laporan' => $csv,
        '--execute' => true,
    ])->assertSuccessful();

    expect(is_file($csv))->toBeTrue();

    $isi = (string) file_get_contents($csv);

    expect($isi)->toContain('diperbarui')
        ->and($isi)->toContain('Agitator BHT')
        ->and($isi)->toContain('BLENDING');
});

test('opsi perbarui-kode-alat menyamakan Kode Alat di database dengan berkas', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKALCY01',
        'equipment_no'  => '70000092',
        'description'   => 'Centrifuge Separator',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'ALCY01', 'deskripsi' => 'Centrifuge Separator', 'eq_no' => '70000092'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'               => $berkas,
        '--pt'                 => 'NPA',
        '--perbarui-kode-alat' => true,
        '--execute'            => true,
    ])->assertSuccessful();

    expect($aset->fresh()->tech_ident_no)->toBe('ALCY01');
});

test('tanpa opsi perbarui-kode-alat Kode Alat di database tidak diubah', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKALCY01',
        'equipment_no'  => '70000092',
        'description'   => 'MILLING&PACKAGING',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'ALCY01', 'deskripsi' => 'Centrifuge Separator', 'eq_no' => '70000092'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'    => $berkas,
        '--pt'      => 'NPA',
        '--execute' => true,
    ])->assertSuccessful();

    expect($aset->fresh()->tech_ident_no)->toBe('ESKALCY01')
        ->and($aset->fresh()->description)->toBe('Centrifuge Separator');
});

test('mode simulasi perbarui-kode-alat tidak menulis Kode Alat', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKALCY01',
        'equipment_no'  => '70000092',
        'description'   => 'Centrifuge Separator',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'ALCY01', 'deskripsi' => 'Centrifuge Separator', 'eq_no' => '70000092'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'               => $berkas,
        '--pt'                 => 'NPA',
        '--perbarui-kode-alat' => true,
    ])
        ->expectsOutputToContain('MODE SIMULASI')
        ->assertSuccessful();

    expect($aset->fresh()->tech_ident_no)->toBe('ESKALCY01');
});

test('Kode Alat tetap disamakan walau deskripsi sudah sama', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKHM01',
        'equipment_no'  => '70000087',
        'description'   => 'Hammer Mill',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'HM01', 'deskripsi' => 'Hammer Mill', 'eq_no' => '70000087'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'               => $berkas,
        '--pt'                 => 'NPA',
        '--perbarui-kode-alat' => true,
        '--execute'            => true,
    ])->assertSuccessful();

    expect($aset->fresh()->tech_ident_no)->toBe('HM01')
        ->and($aset->fresh()->description)->toBe('Hammer Mill');
});

test('Kode Alat di database yang masih kosong diisi dari berkas', function () {
    $aset = asetUji([
        'company_id'    => $this->npa->id,
        'equipment_no'  => '70000177',
        'description'   => 'Valve Inlet H05B',
    ]);

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'SVH05A', 'deskripsi' => 'Valve Inlet H05B', 'eq_no' => '70000177'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'               => $berkas,
        '--pt'                 => 'NPA',
        '--perbarui-kode-alat' => true,
        '--execute'            => true,
    ])->assertSuccessful();

    expect($aset->fresh()->tech_ident_no)->toBe('SVH05A');
});

test('Kode Alat dibatalkan bila kode itu sudah dipakai aset lain di PT yang sama', function () {
    $esk = asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKSC04',
        'equipment_no'  => '70000101',
        'description'   => 'Screw Conveyor 04',
    ]);

    asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'SC04',
        'equipment_no'  => '70000247',
        'description'   => 'Screw Conveyor H06',
    ]);

    // Berkas nyata memakai "SC04" untuk dua mesin berbeda, sehingga Equipment
    // No. yang memutuskan baris mana masuk aset mana.
    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'SC04', 'deskripsi' => 'Screw Conveyor 04', 'eq_no' => '70000101'],
        ['kode' => 'SC04', 'deskripsi' => 'Screw Conveyor H06', 'eq_no' => '70000247'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'               => $berkas,
        '--pt'                 => 'NPA',
        '--perbarui-kode-alat' => true,
        '--execute'            => true,
    ])->assertSuccessful();

    expect($esk->fresh()->tech_ident_no)->toBe('ESKSC04');
});

test('laporan CSV memuat Kode Alat sesudah disamakan', function () {
    asetUji([
        'company_id'    => $this->npa->id,
        'tech_ident_no' => 'ESKFNCY01',
        'equipment_no'  => '70000091',
        'description'   => 'Centrifuge',
    ]);

    $this->berkasUji[] = $csv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uji-laporan-kode-' . uniqid() . '.csv';

    $this->berkasUji[] = $berkas = tulisMasterListUji([
        ['kode' => 'FNCY01', 'deskripsi' => 'Centrifuge', 'eq_no' => '70000091'],
    ]);

    $this->artisan('assets:perbarui-deskripsi', [
        'berkas'               => $berkas,
        '--pt'                 => 'NPA',
        '--perbarui-kode-alat' => true,
        '--execute'            => true,
        '--laporan'            => $csv,
    ])->assertSuccessful();

    $isi = (string) file_get_contents($csv);

    expect($isi)->toContain('kode_alat_baru')
        ->and($isi)->toContain('ESKFNCY01')
        ->and($isi)->toContain('kode_alat_disamakan=1');
});


