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
 * Step Temuan (finding) pada wizard laporan Telegram + format daftar alat.
 *
 * Tiga hal yang dijaga di sini:
 *   1. Temuan adalah step BARU setelah root cause dan bersifat OPSIONAL:
 *      root cause maupun temuan sama-sama punya tombol lewati.
 *   2. Daftar alat ditampilkan dengan alat utama lebih dulu ("Equipment :")
 *      lalu daftar bernomor "Equipment Tambahan :".
 *   3. Label tombol daftar alat HANYA kode alat + deskripsi (tanpa kode
 *      Functional Location) supaya deskripsi tidak terpotong.
 *
 * Beberapa method yang diuji bersifat protected, jadi dipanggil lewat
 * Reflection (pola yang sama dipakai WizardLocationCaptureTest).
 */

/**
 * Panggil method protected milik ReportWizardService dari test.
 *
 * @param  ReportWizardService  $layanan  Instance layanan wizard
 * @param  string               $metode   Nama method
 * @param  array<int, mixed>    $argumen  Argumen method
 * @return mixed  Hasil pemanggilan method
 */
function panggilMetodeTemuan(ReportWizardService $layanan, string $metode, array $argumen)
{
    return (new ReflectionMethod($layanan, $metode))->invokeArgs($layanan, $argumen);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTFIND'], ['name' => 'PT Temuan']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPFIND'],
        ['name' => 'Departemen Temuan']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAFIND'],
        ['name' => 'Area Temuan']
    );

    $this->flSection = FunctionalLocation::create([
        'code'          => 'PTFIND-DEPFIND-AREAFIND-01',
        'segment'       => '01',
        'name'          => 'Section Temuan',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->teknisi = Technician::create([
        'name' => 'Teknisi Temuan', 'nik' => 'FIN001', 'group' => 'reguler',
        'status' => 'active', 'telegram_id' => '56001',
    ]);

    $this->chatId = '56001';

    // Deskripsi sengaja panjang (37 karakter) supaya pemotongan label 64
    // karakter benar-benar teruji.
    $this->asetUtama = Asset::create([
        'equipment_no'   => 'EQ-FIN-001',
        'description'    => 'Pompa Transfer Lini 2 Kebocoran Minor',
        'tech_ident_no'  => 'TMF-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->asetTambahan = Asset::create([
        'equipment_no'   => 'EQ-FIN-002',
        'description'    => 'Motor Penggerak Pompa Lini 2',
        'tech_ident_no'  => 'TMF-M-02',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->layanan = app(ReportWizardService::class);
});
/**
 * State wizard pada step tertentu untuk pengujian alur temuan.
 *
 * @param  string  $chatId     Chat ID Telegram
 * @param  string  $step       Step awal (konstanta ReportWizardService::STEP_*)
 * @param  int     $asetUtama  ID alat utama
 * @return array   State wizard siap pakai
 */
function stateTemuan(string $chatId, string $step, int $asetUtama): array
{
    return [
        'chat_id'                 => $chatId,
        'text'                    => 'Perbaikan pompa transfer lini 2',
        'step'                    => $step,
        'step_history'            => ['work_duration'],
        'work_classification'     => 'zpm1',
        'jenis_pekerjaan'         => 'CM',
        'equipment_id'            => $asetUtama,
        'equipment_ident'         => 'TMF-P-01',
        'equipment_desc'          => 'Pompa Transfer Lini 2 Kebocoran Minor',
        'equipment_funcloc'       => 'PTFIND-DEPFIND-AREAFIND-01',
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
// STEP ROOT CAUSE — OPSIONAL
// =========================================================

test('root cause bisa dilewati lewat tombol dan lanjut ke step temuan', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_ROOT_CAUSE,
        $this->asetUtama->id
    ));

    $respons = $this->layanan->handleCallback($this->chatId, 'wizard:confirm:rootcause_skip');
    $state   = $this->layanan->getState($this->chatId);

    expect($state['step'])->toBe(ReportWizardService::STEP_FINDING)
        ->and($state['root_cause'])->toBeNull()
        ->and($respons['message'])->toContain('Temuan')
        ->and(json_encode($respons['keyboard']))->toContain('wizard:confirm:finding_skip');

    // Kembali dari step temuan harus membuka root cause lagi (riwayat berisi
    // step itu karena tombol lewati tetap mencatat riwayat).
    $kembali = $this->layanan->handleCallback($this->chatId, 'wizard:back');

    expect($kembali['message'])->toContain('Root Cause');

    $this->layanan->destroyWizard($this->chatId);
});

test('root cause dari deteksi AI (rootcause_ok) juga lanjut ke step temuan', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_ROOT_CAUSE,
        $this->asetUtama->id
    ));

    $respons = $this->layanan->handleCallback($this->chatId, 'wizard:confirm:rootcause_ok');
    $state   = $this->layanan->getState($this->chatId);

    expect($state['step'])->toBe(ReportWizardService::STEP_FINDING)
        ->and($respons['message'])->toContain('Step 5b/8');

    $this->layanan->destroyWizard($this->chatId);
});
test('teks root cause normal mengisi state lalu maju ke temuan', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_ROOT_CAUSE,
        $this->asetUtama->id
    ));

    $respons = $this->layanan->handleTextInput($this->chatId, 'Seal mekanikal aus');
    $state   = $this->layanan->getState($this->chatId);

    expect($state['root_cause'])->toBe('Seal mekanikal aus')
        ->and($state['step'])->toBe(ReportWizardService::STEP_FINDING)
        ->and($respons['message'])->toContain('Temuan (Opsional)');

    $this->layanan->destroyWizard($this->chatId);
});

test('root cause terlalu pendek menawarkan tombol lewati', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_ROOT_CAUSE,
        $this->asetUtama->id
    ));

    $respons = $this->layanan->handleTextInput($this->chatId, 'ab');

    expect($respons['message'])->toContain('terlalu pendek')
        ->and(json_encode($respons['keyboard']))->toContain('wizard:confirm:rootcause_skip');

    $this->layanan->destroyWizard($this->chatId);
});

// =========================================================
// STEP TEMUAN — OPSIONAL
// =========================================================

test('temuan yang diketik tersimpan di state lalu lanjut ke foto dokumentasi', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_FINDING,
        $this->asetUtama->id
    ));

    $respons = $this->layanan->handleTextInput($this->chatId, 'Ada kebocoran minor di seal');
    $state   = $this->layanan->getState($this->chatId);

    expect($state['finding'])->toBe('Ada kebocoran minor di seal')
        ->and($state['step'])->toBe(ReportWizardService::STEP_PHOTO_DOCUMENTATION)
        ->and($respons['message'])->toContain('Foto Dokumentasi');

    $this->layanan->destroyWizard($this->chatId);
});

test('temuan bisa dilewati lewat tombol dan laporan tetap bisa disimpan', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_FINDING,
        $this->asetUtama->id
    ));

    $respons = $this->layanan->handleCallback($this->chatId, 'wizard:confirm:finding_skip');
    $state   = $this->layanan->getState($this->chatId);

    expect($state['finding'])->toBeNull()
        ->and($state['step'])->toBe(ReportWizardService::STEP_PHOTO_DOCUMENTATION)
        ->and($respons['message'])->toContain('Foto Dokumentasi');

    $this->layanan->destroyWizard($this->chatId);
});

test('kata skip pada step temuan diperlakukan sebagai lewati', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_FINDING,
        $this->asetUtama->id
    ));

    $this->layanan->handleTextInput($this->chatId, 'skip');
    $state = $this->layanan->getState($this->chatId);

    expect($state['finding'])->toBeNull()
        ->and($state['step'])->toBe(ReportWizardService::STEP_PHOTO_DOCUMENTATION);

    $this->layanan->destroyWizard($this->chatId);
});

test('tombol Kembali dari step temuan membuka kembali step root cause', function () {
    $state                 = stateTemuan($this->chatId, ReportWizardService::STEP_FINDING, $this->asetUtama->id);
    $state['step_history'] = ['work_duration', 'root_cause'];

    $this->layanan->saveState($this->chatId, $state);

    $respons = $this->layanan->handleCallback($this->chatId, 'wizard:back');
    $setelah = $this->layanan->getState($this->chatId);

    expect($setelah['step'])->toBe(ReportWizardService::STEP_ROOT_CAUSE)
        ->and($respons['message'])->toContain('Root Cause');

    $this->layanan->destroyWizard($this->chatId);
});
// =========================================================
// PENYIMPANAN TEMUAN KE LAPORAN
// =========================================================

test('temuan tersimpan di laporan induk dan di baris salinan multi alat', function () {
    $state                = stateTemuan($this->chatId, ReportWizardService::STEP_CONFIRMATION, $this->asetUtama->id);
    $state['finding']     = 'Ada kebocoran minor di seal';
    $state['multi_items'] = [[
        'asset_id'   => $this->asetTambahan->id,
        'funcloc_id' => $this->flSection->id,
        'label'      => 'TMF-M-02',
        'sub'        => 'Motor Penggerak Pompa Lini 2',
    ]];

    // Ringkasan konfirmasi wajib menampilkan temuan sebelum disimpan.
    $ringkasan = panggilMetodeTemuan($this->layanan, 'buildConfirmationSummary', [$state]);

    expect($ringkasan['message'])->toContain('Temuan')
        ->and($ringkasan['message'])->toContain('Ada kebocoran minor di seal');

    $this->layanan->saveState($this->chatId, $state);
    $this->layanan->handleCallback($this->chatId, 'wizard:confirm:save_done');

    $induk = Report::where('asset_id', $this->asetUtama->id)->whereNull('multi_of')->latest('id')->first();

    expect($induk)->not->toBeNull()
        ->and($induk->root_cause)->toBe('Seal mekanikal aus')
        ->and($induk->finding)->toBe('Ada kebocoran minor di seal');

    $salinan = Report::where('multi_of', $induk->id)->get();

    expect($salinan)->toHaveCount(1)
        ->and($salinan[0]->finding)->toBe('Ada kebocoran minor di seal');
});

test('laporan tanpa temuan tersimpan dengan kolom finding kosong', function () {
    $state            = stateTemuan($this->chatId, ReportWizardService::STEP_CONFIRMATION, $this->asetUtama->id);
    $state['finding'] = null;

    $this->layanan->saveState($this->chatId, $state);
    $this->layanan->handleCallback($this->chatId, 'wizard:confirm:save_done');

    $induk = Report::where('asset_id', $this->asetUtama->id)->whereNull('multi_of')->latest('id')->first();

    expect($induk)->not->toBeNull()
        ->and($induk->finding)->toBeNull();
});
// =========================================================
// DAFTAR ALAT: ALAT UTAMA DI ATAS, TAMBAHAN BERNOMOR
// =========================================================

test('prompt alat tambahan menampilkan alat utama lebih dulu lalu daftar bernomor', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_MULTI_EQUIPMENT,
        $this->asetUtama->id
    ));

    $respons = $this->layanan->handleTextInput($this->chatId, 'TMF-M-02');
    $pesan   = $respons['message'];

    $posisiUtama    = strpos($pesan, 'Pompa Transfer Lini 2 Kebocoran Minor');
    $posisiTambahan = strpos($pesan, 'Equipment Tambahan :');
    $posisiNomor    = strpos($pesan, '1. TMF-M-02 — Motor Penggerak Pompa Lini 2');

    expect($pesan)->toContain('*Equipment :*')
        ->and($posisiUtama)->not->toBeFalse()
        ->and($posisiTambahan)->not->toBeFalse()
        ->and($posisiNomor)->not->toBeFalse()
        ->and($posisiUtama)->toBeLessThan($posisiTambahan)
        ->and($posisiTambahan)->toBeLessThan($posisiNomor);

    $this->layanan->destroyWizard($this->chatId);
});

test('layar hirarki mode multi memuat blok daftar alat yang sama', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_MULTI_EQUIPMENT,
        $this->asetUtama->id
    ));

    $this->layanan->handleCallback($this->chatId, 'multi_hirarki');

    $hasil = $this->layanan->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetTambahan->id);

    expect($hasil['message'])->toContain('*Equipment :*')
        ->and($hasil['message'])->toContain('Pompa Transfer Lini 2 Kebocoran Minor')
        ->and($hasil['message'])->toContain('Equipment Tambahan :')
        ->and($hasil['message'])->toContain('1. TMF-M-02 — Motor Penggerak Pompa Lini 2');

    $this->layanan->destroyWizard($this->chatId);
});

// =========================================================
// LABEL ALAT TANPA KODE FUNC LOC
// =========================================================

test('daftar alat di hirarki memakai kode alat + deskripsi utuh tanpa kode funcloc', function () {
    $this->layanan->saveState($this->chatId, stateTemuan(
        $this->chatId,
        ReportWizardService::STEP_MULTI_EQUIPMENT,
        $this->asetUtama->id
    ));

    $this->layanan->handleCallback($this->chatId, 'multi_hirarki');

    $layar = $this->layanan->handleCallback($this->chatId, 'funcloc_assets:' . $this->flSection->id);

    $labelUtama = collect($layar['keyboard'])->firstWhere('callback_data', 'funcloc_asset_pick:' . $this->asetUtama->id);

    expect($labelUtama)->not->toBeNull()
        ->and($labelUtama['text'])->toBe('TMF-P-01 — Pompa Transfer Lini 2 Kebocoran Minor')
        ->and($labelUtama['text'])->not->toContain($this->flSection->code)
        ->and(mb_strlen($labelUtama['text']))->toBeLessThanOrEqual(64);

    $this->layanan->destroyWizard($this->chatId);
});

test('label kandidat alat tidak lagi memuat kode funcloc sehingga deskripsi tidak terpotong', function () {
    // Deskripsi 37 karakter: dengan pola lama ("kode | funcloc — deskripsi")
    // label ini melewati 64 karakter sehingga deskripsinya terpotong.
    $kandidat = [[
        'id'             => $this->asetUtama->id,
        'tech_ident_no'  => 'TMF-P-01',
        'functional_loc' => 'PTFIND-DEPFIND-AREAFIND-01',
        'description'    => 'Pompa Transfer Lini 2 Kebocoran Minor',
    ]];

    $respons = panggilMetodeTemuan($this->layanan, 'buildCandidateKeyboard', [
        $kandidat,
        ['confidence' => 88],
        ['step_history' => [], 'equipment_id' => null],
    ]);

    $label = $respons['keyboard'][0]['text'];

    expect($label)->toBe('TMF-P-01 — Pompa Transfer Lini 2 Kebocoran Minor')
        ->and($label)->not->toContain('PTFIND-DEPFIND-AREAFIND-01')
        ->and($label)->not->toContain('...');
});
