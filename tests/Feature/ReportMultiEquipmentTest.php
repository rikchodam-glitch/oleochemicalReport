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
 * Pekerjaan multi equipment/lokasi (mekanisme seperti kolaborasi):
 * laporan INDUK menyimpan detail + foto, setiap alat/lokasi tambahan mendapat
 * baris salinan sendiri (multi_of) agar riwayat alat & halaman lokasi ikut
 * menampilkan pekerjaan tersebut, sedangkan halaman laporan tetap menampilkan
 * satu baris induk dengan daftar alat terkait.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTMUL'], ['name' => 'PT Multi']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPMUL'],
        ['name' => 'Departemen Multi']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAMUL'],
        ['name' => 'Area Multi']
    );

    $this->flSection = FunctionalLocation::create([
        'code'         => 'PTMUL-DEPMUL-AREAMUL-01',
        'segment'      => '01',
        'name'         => 'Section Multi',
        'level'        => FunctionalLocation::LEVEL_SECTION,
        'company_id'   => $this->company->id,
        'department_id'=> $this->dept->id,
        'is_active'    => true,
    ]);

    $this->teknisi = Technician::create([
        'name' => 'Teknisi Multi', 'nik' => 'MUL001', 'group' => 'reguler',
        'status' => 'active', 'telegram_id' => '55001',
    ]);

    $this->chatId = '55001';

    $this->asetUtama = Asset::create([
        'equipment_no'   => 'EQ-MUL-001',
        'description'    => 'Pompa Multi Utama',
        'tech_ident_no'  => 'MUL-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->asetTambahan = Asset::create([
        'equipment_no'   => 'EQ-MUL-002',
        'description'    => 'Motor Multi Kedua',
        'tech_ident_no'  => 'MUL-M-02',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);
});

/**
 * State wizard pada langkah alat/lokasi tambahan.
 *
 * @return array  State siap dipakai handleTextInput/handleCallback
 */
function stateMultiEquipment(string $chatId, int $asetUtamaId): array
{
    return [
        'chat_id'                 => $chatId,
        'text'                    => 'Overhaul pompa dan motor multi',
        'step'                    => ReportWizardService::STEP_MULTI_EQUIPMENT,
        'step_history'            => [],
        'work_classification'     => 'zpm3',
        'jenis_pekerjaan'         => 'CM/PM',
        'equipment_id'            => $asetUtamaId,
        'equipment_ident'         => 'MUL-P-01',
        'is_area_work'            => false,
        'area_id'                 => null,
        'funcloc_id'              => null,
        'work_duration_minutes'   => 120,
        'start_time'              => '08:00',
        'end_time'                => '10:00',
        'root_cause'              => 'Bearing aus',
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => ['reports/2026/09/26/55001/foto.jpg'],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => [],
        'collaborator_names'      => [],
        'created_at'              => now()->toIso8601String(),
    ];
}

test('mengetik kode alat tambahan menambahkannya ke daftar pekerjaan', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));
    $respons = $wizard->handleTextInput($this->chatId, 'MUL-M-02');
    $state   = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($state['multi_items'][0]['asset_id'])->toBe($this->asetTambahan->id)
        ->and($state['multi_items'][0]['label'])->toBe('MUL-M-02')
        ->and($respons['message'])->toContain('Ditambahkan (1)')
        // Pilihan yang dilihat teknisi: Tidak / Pilih Hirarki (plus bisa mengetik).
        ->and(json_encode($respons['keyboard']))->toContain('wizard:confirm:multi_done')
        ->and(json_encode($respons['keyboard']))->toContain('multi_hirarki')
        ->and($respons['message'])->toContain('Pilih Hirarki');

    // Kode yang tidak dikenal tetap memberi tahu teknisi.
    $gagal = $wizard->handleTextInput($this->chatId, 'KODE-NGAWUR-99');

    expect($gagal['message'])->toContain('Tidak ditemukan: KODE-NGAWUR-99');

    $wizard->destroyWizard($this->chatId);
});

test('alat utama dan item duplikat tidak ditambahkan dua kali', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));

    // Alat utama laporan tidak boleh masuk daftar tambahan.
    $wizard->handleTextInput($this->chatId, 'MUL-P-01');
    expect($wizard->getState($this->chatId)['multi_items'] ?? [])->toBe([]);

    // Item yang sama dua kali juga hanya tercatat sekali.
    $wizard->handleTextInput($this->chatId, 'MUL-M-02');
    $wizard->handleTextInput($this->chatId, 'MUL-M-02');

    expect($wizard->getState($this->chatId)['multi_items'])->toHaveCount(1);

    // Tombol hapus membuang item dari daftar.
    $hapus = $wizard->handleCallback($this->chatId, 'multi_hapus:0');

    expect($wizard->getState($this->chatId)['multi_items'] ?? [])->toBe([])
        ->and($hapus['message'])->toContain('Dihapus: MUL-M-02');

    $wizard->destroyWizard($this->chatId);
});

test('menyimpan laporan membuat baris salinan per alat dengan dokumentasi sama', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));
    $wizard->handleTextInput($this->chatId, 'MUL-M-02');

    // Tekan "Tidak (Lanjut Isi Jam)" → barulah jam kerja ditampilkan/diminta.
    $lanjutJam = $wizard->handleCallback($this->chatId, 'wizard:confirm:multi_done');

    expect($lanjutJam['message'])->toContain('Durasi pekerjaan terdeteksi')
        ->and($wizard->getState($this->chatId)['step'])->toBe(ReportWizardService::STEP_WORK_DURATION);

    // Konfirmasi jam kerja, lalu jalani sisa langkah sampai ringkasan konfirmasi.
    $wizard->handleCallback($this->chatId, 'wizard:confirm:duration_ok');
    $wizard->handleCallback($this->chatId, 'wizard:confirm:rootcause_ok');
    $wizard->handleCallback($this->chatId, 'wizard:confirm:photo_doc_skip');
    $wizard->handleCallback($this->chatId, 'wizard:confirm:photo_hygiene_skip');
    $ringkasan = $wizard->handleCallback($this->chatId, 'wizard:confirm:collab_skip');

    expect($ringkasan['message'])->toContain('Alat Tambahan')
        ->and($ringkasan['message'])->toContain('MUL-M-02');

    $wizard->handleCallback($this->chatId, 'wizard:confirm:save_done');

    $induk = Report::where('asset_id', $this->asetUtama->id)->whereNull('multi_of')->latest('id')->first();

    expect($induk)->not->toBeNull()
        ->and($induk->photo_documentation)->toHaveCount(1)
        ->and($induk->status)->toBe('completed');

    $salinan = Report::where('multi_of', $induk->id)->get();

    expect($salinan)->toHaveCount(1)
        ->and($salinan[0]->asset_id)->toBe($this->asetTambahan->id)
        ->and($salinan[0]->work_description)->toBe('Overhaul pompa dan motor multi')
        // Dokumentasi & HC disamakan dengan laporan induk (path foto yang sama,
        // bukan salinan file baru) supaya bukti pekerjaan terlihat di tiap alat.
        ->and($salinan[0]->photo_documentation)->toBe($induk->photo_documentation)
        ->and($salinan[0]->photo_documentation)->toHaveCount(1)
        ->and($salinan[0]->status)->toBe('completed')
        ->and($induk->multiItems)->toHaveCount(1);

    $wizard->destroyWizard($this->chatId);
});

test('tombol Pilih Hirarki menambahkan lokasi dari penelusuran hirarki', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));

    $layar = $wizard->handleCallback($this->chatId, 'multi_hirarki');

    expect($wizard->getState($this->chatId)['funcloc_picker_mode'])->toBe('multi')
        ->and(json_encode($layar['keyboard']))->toContain('funcloc_');

    // Pilih lokasi lewat tombol Konfirmasi pada node Section.
    $hasil = $wizard->handleCallback($this->chatId, 'funcloc_confirm:' . $this->flSection->id);
    $state = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($state['multi_items'][0]['funcloc_id'])->toBe($this->flSection->id)
        ->and($state['multi_items'][0]['label'])->toBe('PTMUL-DEPMUL-AREAMUL-01')
        // Picker ditutup kembali ke prompt alat/lokasi tambahan.
        ->and($state['funcloc_picker_active'] ?? false)->toBeFalse()
        ->and($hasil['message'])->toContain('Ditambahkan dari hirarki');

    $wizard->destroyWizard($this->chatId);
});

test('halaman laporan hanya menampilkan baris induk dengan daftar alatnya', function () {
    $induk = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->toDateString(),
        'report_code'      => 'RPT-20260926-0011',
        'work_description' => 'Overhaul pompa dan motor multi',
        'asset_id'         => $this->asetUtama->id,
        'report_type'      => 'equipment_repair',
        'status'           => 'completed',
    ]);

    $salinan = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->toDateString(),
        'report_code'      => 'RPT-20260926-0012',
        'work_description' => 'Overhaul pompa dan motor multi',
        'asset_id'         => $this->asetTambahan->id,
        'multi_of'         => $induk->id,
        'report_type'      => 'equipment_repair',
        'status'           => 'completed',
    ]);

    // Halaman laporan: baris induk tampil dengan penanda kolaborasi, salinan tidak
    // tampil sebagai baris tersendiri. Daftar alat/lokasi kolaborasi ada di kolom
    // Lokasi / Alat dengan tombol "Lihat" (popup).
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString())
        ->assertStatus(200)
        ->assertSee('RPT-20260926-0011', false)
        ->assertSee('1 KOLABORASI', false)
        ->assertSee('Lihat', false)
        ->assertSee('tampilkanKolaborasi', false)
        ->assertSee('modal-kolaborasi', false)
        ->assertSee('MUL-M-02', false)
        ->assertDontSee('RPT-20260926-0012', false);

    // Detail laporan induk mencantumkan alat lain, detail salinan menautkan induk.
    $this->actingAs($this->admin)
        ->get('/reports/' . $induk->id)
        ->assertStatus(200)
        ->assertSee('Mencakup 1 alat/lokasi lain', false);

    $this->actingAs($this->admin)
        ->get('/reports/' . $salinan->id)
        ->assertStatus(200)
        ->assertSee('Bagian dari pekerjaan bersama', false)
        ->assertSee('RPT-20260926-0011', false);
});

test('hirarki mode multi menampilkan daftar equipment dan tidak mengunci lokasi utama', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));

    // Masuk mode hirarki untuk mencari alat/lokasi tambahan.
    $wizard->handleCallback($this->chatId, 'multi_hirarki');

    // Teknisi memilih node Section (level 3) yang punya 2 alat di bawahnya.
    $layar = $wizard->handleCallback($this->chatId, 'funcloc_pick:3:' . $this->flSection->id);
    $state = $wizard->getState($this->chatId);

    // Bukan konfirmasi lokasi utama: laporan tetap memakai alat utama, dan layar
    // menawarkan daftar equipment di lokasi tersebut.
    expect($state['equipment_id'])->toBe($this->asetUtama->id)
        ->and($state['funcloc_id'] ?? null)->toBeNull()
        ->and(json_encode($layar['keyboard']))->toContain('funcloc_assets:' . $this->flSection->id)
        ->and($layar['message'])->toContain('Pilih equipment di lokasi ini');

    // Memilih equipment menambahkannya sebagai item pekerjaan, bukan alat utama baru.
    $hasil = $wizard->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetTambahan->id);
    $state = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($state['multi_items'][0]['asset_id'])->toBe($this->asetTambahan->id)
        ->and($state['equipment_id'])->toBe($this->asetUtama->id)
        ->and($state['step'])->toBe(ReportWizardService::STEP_MULTI_EQUIPMENT)
        ->and($hasil['message'])->toContain('Ditambahkan dari hirarki');

    $wizard->destroyWizard($this->chatId);
});

test('hirarki mode multi: lokasi tanpa anak ditambahkan sebagai item, bukan lokasi utama', function () {
    $lokasiKosong = FunctionalLocation::create([
        'code'          => 'PTMUL-DEPMUL-AREAMUL-99',
        'segment'       => '99',
        'name'          => 'Section Kosong',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));
    $wizard->handleCallback($this->chatId, 'multi_hirarki');

    // Node tanpa anak dan tanpa equipment tidak boleh mengunci lokasi utama laporan.
    $hasil = $wizard->handleCallback($this->chatId, 'funcloc_pick:3:' . $lokasiKosong->id);
    $state = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($state['multi_items'][0]['funcloc_id'])->toBe($lokasiKosong->id)
        ->and($state['equipment_id'])->toBe($this->asetUtama->id)
        ->and($state['funcloc_id'] ?? null)->toBeNull()
        ->and($state['step'])->toBe(ReportWizardService::STEP_MULTI_EQUIPMENT)
        ->and($hasil['message'])->toContain('Ditambahkan dari hirarki');

    $wizard->destroyWizard($this->chatId);
});
