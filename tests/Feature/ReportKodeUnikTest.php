<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Kode laporan RPT-YYYYMMDD-XXXX wajib unik dan berurutan nomor.
 *
 * Insiden 07/10/2026 (produksi): satu laporan yang dibuat lewat form web
 * memakai generator lama sehingga kodenya berakhiran acak
 * (RPT-20261007-Q5UW). Karena kolom report_code diurutkan sebagai teks, kode
 * itu jadi nilai terbesar hari itu; generator nomor urut menghitung
 * (int) 'Q5UW' = 0 sehingga kode berikutnya selalu RPT-20261007-0001 yang
 * sudah terpakai. Akibatnya SEMUA tombol Done/Continue wizard Telegram gagal
 * menyimpan dengan galat duplikat (1062) dan teknisi seperti tidak direspon.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTKOD'], ['name' => 'PT Kode']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPKOD'],
        ['name' => 'Departemen Kode']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAKOD'],
        ['name' => 'Area Kode']
    );

    $this->flSection = FunctionalLocation::create([
        'code'          => 'PTKOD-DEPKOD-AREAKOD-01',
        'segment'       => '01',
        'name'          => 'Section Kode',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->teknisi = Technician::create([
        'name' => 'Teknisi Kode', 'nik' => 'KOD001', 'group' => 'reguler',
        'status' => 'active', 'telegram_id' => '57001',
    ]);

    $this->chatId = '57001';

    $this->asetUtama = Asset::create([
        'equipment_no'   => 'EQ-KOD-001',
        'description'    => 'Pompa Transfer Kode',
        'tech_ident_no'  => 'KOD-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->asetTambahan = Asset::create([
        'equipment_no'   => 'EQ-KOD-002',
        'description'    => 'Motor Penggerak Kode',
        'tech_ident_no'  => 'KOD-M-02',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->layanan = app(ReportWizardService::class);
});

/**
 * Buat baris laporan langsung ke database (menyiapkan kode yang sudah terpakai).
 *
 * @param  string      $kode     Kode laporan yang ingin disiapkan
 * @param  Technician  $teknisi  Pemilik laporan
 * @return Report                Laporan yang tersimpan
 */
function laporanKode(string $kode, Technician $teknisi): Report
{
    return Report::create([
        'technician_id'    => $teknisi->id,
        'creator_id'       => $teknisi->id,
        'report_date'      => now()->toDateString(),
        'report_code'      => $kode,
        'work_description' => 'Laporan penyiapan kode',
        'report_type'      => 'general',
        'status'           => 'completed',
    ]);
}

/**
 * State wizard pada Step 8 (konfirmasi & simpan) untuk pengujian kode laporan.
 *
 * @param  string $chatId     Chat ID Telegram
 * @param  int    $asetUtama  ID alat utama
 * @return array  State wizard siap pakai
 */
function stateKode(string $chatId, int $asetUtama): array
{
    return [
        'chat_id'                 => $chatId,
        'text'                    => 'Perbaikan pompa transfer',
        'step'                    => ReportWizardService::STEP_CONFIRMATION,
        'step_history'            => ['work_duration'],
        'work_classification'     => 'zpm1',
        'jenis_pekerjaan'         => 'CM',
        'equipment_id'            => $asetUtama,
        'equipment_ident'         => 'KOD-P-01',
        'equipment_desc'          => 'Pompa Transfer Kode',
        'equipment_funcloc'       => 'PTKOD-DEPKOD-AREAKOD-01',
        'is_area_work'            => false,
        'area_id'                 => null,
        'funcloc_id'              => null,
        'work_duration_minutes'   => 60,
        'start_time'              => '08:00',
        'end_time'                => '09:00',
        'root_cause'              => 'Seal mekanikal aus',
        'finding'                 => null,
        'multi_items'             => [],
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => [],
        'collaborator_names'      => [],
        'created_at'              => now()->toIso8601String(),
    ];
}

// =========================================================
// GENERATOR KODE LAPORAN
// =========================================================

test('nomor urut hari tanpa laporan dimulai dari 0001', function () {
    expect(Report::generateReportCode())->toBe('RPT-' . now()->format('Ymd') . '-0001');
});

test('kode berakhiran acak tidak membuat nomor urut kembali ke 0001', function () {
    $awalan = 'RPT-' . now()->format('Ymd') . '-';

    laporanKode($awalan . '0001', $this->teknisi);
    laporanKode($awalan . '0002', $this->teknisi);
    // Racun dari generator lama: akhiran bukan angka, dan karena diurutkan
    // sebagai teks kode ini selalu berada di atas kode bernomor.
    laporanKode($awalan . 'Q5UW', $this->teknisi);

    expect(Report::generateReportCode())->toBe($awalan . '0003')
        // Dipanggil lagi tanpa menyimpan tetap menghasilkan kode yang sama.
        ->and(Report::generateReportCode())->toBe($awalan . '0003');
});

test('nomor urut dihitung per hari, kode hari lain tidak dihitung', function () {
    laporanKode('RPT-' . now()->subDay()->format('Ymd') . '-0050', $this->teknisi);

    expect(Report::generateReportCode())->toBe('RPT-' . now()->format('Ymd') . '-0001');
});

// =========================================================
// SIMPAN LAPORAN DARI WIZARD (REGRESI INSIDEN 07/10/2026)
// =========================================================

test('wizard menyimpan laporan walau ada kode berakhiran acak hari ini', function () {
    $awalan = 'RPT-' . now()->format('Ymd') . '-';

    laporanKode($awalan . '0001', $this->teknisi);
    laporanKode($awalan . 'Q5UW', $this->teknisi);

    $this->layanan->saveState($this->chatId, stateKode($this->chatId, $this->asetUtama->id));
    $respons = $this->layanan->handleCallback($this->chatId, 'wizard:confirm:save_done');

    $laporan = Report::where('work_description', 'Perbaikan pompa transfer')->first();

    expect($respons['saved'] ?? false)->toBeTrue()
        ->and($laporan)->not->toBeNull()
        ->and($laporan->report_code)->toBe($awalan . '0002')
        ->and($laporan->status)->toBe('completed');
});

test('baris salinan multi alat mendapat kode unik berurutan', function () {
    $awalan = 'RPT-' . now()->format('Ymd') . '-';

    laporanKode($awalan . 'Q5UW', $this->teknisi);

    $state                = stateKode($this->chatId, $this->asetUtama->id);
    $state['multi_items'] = [[
        'asset_id'   => $this->asetTambahan->id,
        'funcloc_id' => $this->flSection->id,
        'label'      => 'KOD-M-02',
        'sub'        => 'Motor Penggerak Kode',
    ]];

    $this->layanan->saveState($this->chatId, $state);
    $respons = $this->layanan->handleCallback($this->chatId, 'wizard:confirm:save_done');

    $induk   = Report::whereNull('multi_of')->where('asset_id', $this->asetUtama->id)->latest('id')->first();
    $salinan = Report::where('multi_of', $induk?->id)->get();

    expect($respons['saved'] ?? false)->toBeTrue()
        ->and($induk)->not->toBeNull()
        ->and($induk->report_code)->toBe($awalan . '0001')
        ->and($salinan)->toHaveCount(1)
        ->and($salinan[0]->report_code)->toBe($awalan . '0002')
        ->and($salinan[0]->report_code)->not->toBe($induk->report_code);
});

test('simpan Continue juga memakai kode berurutan tanpa bentrok', function () {
    $awalan = 'RPT-' . now()->format('Ymd') . '-';

    laporanKode($awalan . '0001', $this->teknisi);
    laporanKode($awalan . 'Q5UW', $this->teknisi);

    $this->layanan->saveState($this->chatId, stateKode($this->chatId, $this->asetUtama->id));
    $respons = $this->layanan->handleCallback($this->chatId, 'wizard:confirm:save_continue');

    $laporan = Report::where('work_description', 'Perbaikan pompa transfer')->first();

    expect($respons['saved'] ?? false)->toBeTrue()
        ->and($laporan)->not->toBeNull()
        ->and($laporan->report_code)->toBe($awalan . '0002')
        ->and($laporan->status)->toBe('continue');
});
