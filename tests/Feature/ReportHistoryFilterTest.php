<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Daftar laporan pada halaman detail LOKASI dan detail EQUIPMENT:
 *   - halaman detail LOKASI: daftar laporan dikelompokkan per hari (ada judul
 *     hari + tombol "Fokus hari ini"), bisa difilter per teknisi/tanggal/alat;
 *   - halaman detail EQUIPMENT: daftar perbaikan berupa TABEL dengan SATU BARIS
 *     PER PERBAIKAN (bukan per teknisi) + tombol Detail ke halaman laporan,
 *     bisa difilter per tanggal/rentang, teknisi, dan pencarian.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTFLT'], ['name' => 'PT Filter']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPFLT'],
        ['name' => 'Departemen Filter']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAFLT'],
        ['name' => 'Area Filter']
    );

    // Node section tanpa area_id (kondisi nyata).
    $this->flSection = FunctionalLocation::create([
        'code'         => 'PTFLT-DEPFLT-AREAFLT-01',
        'segment'      => '01',
        'name'         => 'Section Filter',
        'level'        => FunctionalLocation::LEVEL_SECTION,
        'company_id'   => $this->company->id,
        'department_id'=> $this->dept->id,
        'is_active'    => true,
    ]);

    $this->teknisiA = Technician::create([
        'name' => 'Teknisi Alfa', 'nik' => 'FLT001', 'group' => 'reguler', 'status' => 'active',
    ]);
    $this->teknisiB = Technician::create([
        'name' => 'Teknisi Bravo', 'nik' => 'FLT002', 'group' => 'reguler', 'status' => 'active',
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-FLT-001',
        'description'    => 'Pompa Filter',
        'tech_ident_no'  => 'FLT-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->asetLain = Asset::create([
        'equipment_no'   => 'EQ-FLT-002',
        'description'    => 'Blower Filter',
        'tech_ident_no'  => 'FLT-B-02',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->hariIni     = now()->toDateString();
    $this->hariKemarin = now()->subDay()->toDateString();

    // Laporan area oleh teknisi B (kemarin).
    $this->laporanB = Report::create([
        'technician_id' => $this->teknisiB->id,
        'report_date'   => $this->hariKemarin,
        'report_code'   => 'FLT-AREA-B-KEMARIN',
        'work_description' => 'Pekerjaan area oleh teknisi Bravo',
        'funcloc_id'    => $this->flSection->id,
        'report_type'   => 'area_work',
        'status'        => 'submitted',
    ]);

    // Laporan area oleh teknisi A (hari ini).
    $this->laporanA = Report::create([
        'technician_id' => $this->teknisiA->id,
        'report_date'   => $this->hariIni,
        'report_code'   => 'FLT-AREA-A-HARI-INI',
        'work_description' => 'Pekerjaan area oleh teknisi Alfa',
        'funcloc_id'    => $this->flSection->id,
        'report_type'   => 'area_work',
        'status'        => 'submitted',
    ]);

    // Laporan perbaikan alat oleh teknisi A (hari ini, lewat data alat).
    $this->laporanAlat = Report::create([
        'technician_id' => $this->teknisiA->id,
        'report_date'   => $this->hariIni,
        'report_code'   => 'FLT-ALAT-A-HARI-INI',
        'work_description' => 'Perbaikan pompa filter oleh teknisi Alfa',
        'asset_id'      => $this->aset->id,
        'report_type'   => 'equipment_repair',
        'status'        => 'submitted',
    ]);
});

/**
 * Label hari Bahasa Indonesia untuk tanggal tertentu (dipakai di assertion).
 *
 * @param  string  $tanggal  Tanggal Y-m-d
 * @return string  Contoh: "Selasa, 23 September 2026"
 */
function labelHariUji(string $tanggal): string
{
    $hari = [
        'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
    ];
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $obj = Carbon::parse($tanggal);

    return $hari[$obj->format('l')] . ', ' . $obj->format('d') . ' '
        . $bulan[(int) $obj->format('n')] . ' ' . $obj->format('Y');
}

test('daftar laporan halaman lokasi dikelompokkan per hari', function () {
    $this->actingAs($this->admin)
        ->get('/locations/' . $this->flSection->id)
        ->assertStatus(200)
        ->assertSee(labelHariUji($this->hariIni), false)
        ->assertSee(labelHariUji($this->hariKemarin), false)
        ->assertSee('Fokus hari ini', false)
        ->assertSee('FLT-AREA-A-HARI-INI', false)
        ->assertSee('FLT-ALAT-A-HARI-INI', false)
        ->assertSee('FLT-AREA-B-KEMARIN', false);
});

test('filter teknisi pada halaman lokasi hanya menampilkan laporan teknisi itu', function () {
    $this->actingAs($this->admin)
        ->get('/locations/' . $this->flSection->id . '?teknisi=' . $this->teknisiB->id)
        ->assertStatus(200)
        ->assertSee('FLT-AREA-B-KEMARIN', false)
        ->assertDontSee('FLT-AREA-A-HARI-INI', false)
        ->assertDontSee('FLT-ALAT-A-HARI-INI', false);
});

test('filter tanggal pada halaman lokasi hanya menampilkan laporan hari itu', function () {
    $this->actingAs($this->admin)
        ->get('/locations/' . $this->flSection->id . '?tanggal=' . $this->hariKemarin)
        ->assertStatus(200)
        ->assertSee('FLT-AREA-B-KEMARIN', false)
        ->assertDontSee('FLT-AREA-A-HARI-INI', false);
});

test('pencarian alat menyaring daftar alat pada halaman lokasi', function () {
    $this->actingAs($this->admin)
        ->get('/locations/' . $this->flSection->id . '?alat=FLT-B-02')
        ->assertStatus(200)
        ->assertSee('FLT-B-02', false)
        ->assertDontSee('FLT-P-01', false);
});

test('tabel perbaikan pada detail equipment berisi satu baris per perbaikan dan tombol Detail', function () {
    $this->actingAs($this->admin)
        ->get('/assets/' . $this->aset->id)
        ->assertStatus(200)
        // Satu baris = satu perbaikan: kode laporan tampil + tombol Detail ke laporan itu.
        ->assertSee('Daftar Perbaikan', false)
        ->assertSee('FLT-ALAT-A-HARI-INI', false)
        ->assertSee(route('reports.show', $this->laporanAlat), false)
        // Grafik tren perbaikan ikut dirender.
        ->assertSee('Tren Perbaikan per Bulan', false)
        ->assertSee('trenPerbaikanChart', false)
        // Tabel lama yang mengelompokkan per teknisi sudah tidak dipakai.
        ->assertDontSee('Teknisi yang Pernah Memperbaiki', false);

    // Filter teknisi B tidak punya laporan untuk alat ini.
    $this->actingAs($this->admin)
        ->get('/assets/' . $this->aset->id . '?teknisi=' . $this->teknisiB->id)
        ->assertStatus(200)
        ->assertSee('Belum ada perbaikan untuk filter yang dipilih.', false)
        ->assertDontSee('FLT-ALAT-A-HARI-INI', false);

    // Filter teknisi A menampilkan laporannya.
    $this->actingAs($this->admin)
        ->get('/assets/' . $this->aset->id . '?teknisi=' . $this->teknisiA->id)
        ->assertStatus(200)
        ->assertSee('FLT-ALAT-A-HARI-INI', false);
});

test('pencarian tabel perbaikan detail equipment menyaring kode laporan', function () {
    $this->actingAs($this->admin)
        ->get('/assets/' . $this->aset->id . '?q=FLT-ALAT-A-HARI-INI')
        ->assertStatus(200)
        ->assertSee('FLT-ALAT-A-HARI-INI', false);

    $this->actingAs($this->admin)
        ->get('/assets/' . $this->aset->id . '?q=KODE-TIDAK-ADA')
        ->assertStatus(200)
        ->assertSee('Belum ada perbaikan untuk filter yang dipilih.', false)
        ->assertDontSee('FLT-ALAT-A-HARI-INI', false);
});

test('tabel perbaikan detail equipment dibatasi 15 baris per halaman', function () {
    // 15 laporan tambahan -> total 16 perbaikan untuk alat ini.
    for ($i = 1; $i <= 15; $i++) {
        Report::create([
            'technician_id'    => $this->teknisiA->id,
            'report_date'      => now()->subDays($i),
            'report_code'      => 'FLT-PAGI-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'work_description' => 'Perbaikan uji pagination ' . $i,
            'asset_id'         => $this->aset->id,
            'report_type'      => 'equipment_repair',
            'status'           => 'completed',
        ]);
    }

    expect(Report::where('asset_id', $this->aset->id)->count())->toBe(16);

    $penandaBaris = '<tr class="hover:bg-slate-50 transition-colors">';

    $halamanSatu = $this->actingAs($this->admin)->get('/assets/' . $this->aset->id);
    $halamanSatu->assertStatus(200)
        ->assertSee('Menampilkan 16 dari 16 perbaikan', false);
    expect(substr_count($halamanSatu->getContent(), $penandaBaris))->toBe(15);

    $halamanDua = $this->actingAs($this->admin)->get('/assets/' . $this->aset->id . '?page=2');
    $halamanDua->assertStatus(200);
    expect(substr_count($halamanDua->getContent(), $penandaBaris))->toBe(1);
});
