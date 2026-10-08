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
        ->and($respons['message'])->toContain('Equipment Tambahan :')
        ->and($respons['message'])->toContain('1. MUL-M-02 — Motor Multi Kedua')
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
    // Urutan langkah opsional: temuan (5a) dulu, baru root cause (5b).
    $wizard->handleCallback($this->chatId, 'wizard:confirm:duration_ok');
    $wizard->handleCallback($this->chatId, 'wizard:confirm:finding_skip');
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
        // Picker TETAP aktif: teknisi bisa langsung menambah alat/lokasi lain
        // tanpa menelusuri ulang, lalu menutupnya lewat "Selesai Menambah".
        ->and($state['funcloc_picker_active'] ?? false)->toBeTrue()
        ->and(json_encode($hasil['keyboard']))->toContain('multi_hirarki_selesai')
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
        // Kode laporan tidak lagi tampil di tabel, baris dikenali lewat penanda
        // data-laporan-id (id laporan).
        ->assertSee('data-laporan-id="' . $induk->id . '"', false)
        // Cukup penanda jumlah kolaborasi yang bisa diklik untuk membuka popup.
        ->assertSee('1 KOLABORASI', false)
        ->assertSee('tampilkanKolaborasi(' . $induk->id . ')', false)
        ->assertSee('modal-kolaborasi', false)
        ->assertSee('MUL-M-02', false)
        // Kolaborasi satu kesatuan pekerjaan: hanya tautan detail alat, tanpa
        // tautan ke halaman laporan salinan maupun tombol "Lihat Laporan".
        ->assertSee(route('assets.show', $this->asetTambahan), false)
        ->assertDontSee('Lihat Laporan', false)
        ->assertDontSee('/reports/' . $salinan->id, false)
        ->assertDontSee('data-laporan-id="' . $salinan->id . '"', false);

    // Detail laporan induk mencantumkan alat lain (tautan ke detail alat, bukan
    // ke halaman laporan salinan); detail salinan menautkan induk.
    $this->actingAs($this->admin)
        ->get('/reports/' . $induk->id)
        ->assertStatus(200)
        ->assertSee('Mencakup 1 alat/lokasi lain', false)
        ->assertSee(route('assets.show', $this->asetTambahan), false)
        ->assertDontSee('/reports/' . $salinan->id, false);

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

/**
 * Susun state wizard pada Step 8b lalu buka penelusuran hirarki mode multi
 * sampai layar node Section yang punya alat terdaftar.
 *
 * @param  ReportWizardService  $wizard
 * @return array  State wizard setelah hierarki dibuka di node Section
 */
function bukaHirarkiMultiSampaiSection(ReportWizardService $wizard, string $chatId, array $state, int $sectionId): array
{
    $wizard->saveState($chatId, $state);
    $wizard->handleCallback($chatId, 'multi_hirarki');
    $wizard->handleCallback($chatId, 'funcloc_pick:' . FunctionalLocation::LEVEL_SECTION . ':' . $sectionId);

    return $wizard->getState($chatId) ?? [];
}

test('menambah alat dari hirarki tidak menutup hirarki dan menampilkan daftar item', function () {
    $wizard = app(ReportWizardService::class);

    bukaHirarkiMultiSampaiSection(
        $wizard,
        $this->chatId,
        stateMultiEquipment($this->chatId, $this->asetUtama->id),
        $this->flSection->id
    );

    $hasil = $wizard->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetTambahan->id);
    $state = $wizard->getState($this->chatId);
    $papan = json_encode($hasil['keyboard']);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($state['multi_items'][0]['asset_id'])->toBe($this->asetTambahan->id)
        // Hirarki TIDAK hilang: layar yang sama masih menawarkan daftar alat
        // lokasi ini, daftar item, hapus terakhir, dan tombol selesai.
        ->and($state['funcloc_picker_active'] ?? false)->toBeTrue()
        ->and($papan)->toContain('funcloc_assets:' . $this->flSection->id)
        ->and($papan)->toContain('multi_hirarki_hapus')
        ->and($papan)->toContain('multi_hirarki_selesai')
        ->and($hasil['message'])->toContain('Ditambahkan dari hirarki')
        ->and($hasil['message'])->toContain('Equipment Tambahan :')
        ->and($hasil['message'])->toContain('1. MUL-M-02 — Motor Multi Kedua');

    $wizard->destroyWizard($this->chatId);
});

test('alat kedua dan seterusnya tidak perlu menelusuri ulang dari PT', function () {
    $wizard = app(ReportWizardService::class);

    bukaHirarkiMultiSampaiSection(
        $wizard,
        $this->chatId,
        stateMultiEquipment($this->chatId, $this->asetUtama->id),
        $this->flSection->id
    );

    // Buka daftar alat lalu tambahkan alat tambahan (daftar tetap ingat lokasi).
    $daftar = $wizard->handleCallback($this->chatId, 'funcloc_assets:' . $this->flSection->id);
    expect(json_encode($daftar['keyboard']))->toContain('funcloc_asset_pick:' . $this->asetTambahan->id);

    $wizard->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetTambahan->id);

    // Daftar item & hierarki tetap tersedia tanpa menyentuh tombol PT lagi.
    $layar = $wizard->handleCallback($this->chatId, 'funcloc_assets:' . $this->flSection->id);
    $state = $wizard->getState($this->chatId);

    expect($state['funcloc_picker_active'] ?? false)->toBeTrue()
        ->and($state['funcloc_path'])->not->toBeEmpty()
        ->and($layar['message'])->toContain('Equipment Tambahan :')
        ->and($layar['message'])->toContain('1. MUL-M-02 — Motor Multi Kedua')
        ->and(json_encode($layar['keyboard']))->toContain('funcloc_asset_pick:' . $this->asetTambahan->id);

    $wizard->destroyWizard($this->chatId);
});


test('kode alat yang diketik di dalam hirarki langsung ditambahkan tanpa keluar', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));
    $wizard->handleCallback($this->chatId, 'multi_hirarki');

    // Teknisi hanya hafal kode alat, bukan kode lokasi: ketik di dalam hirarki.
    $hasil = $wizard->handleTextInput($this->chatId, 'MUL-M-02');
    $state = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($state['multi_items'][0]['asset_id'])->toBe($this->asetTambahan->id)
        ->and($state['funcloc_picker_active'] ?? false)->toBeTrue()
        ->and($hasil['message'])->toContain('Ditambahkan dari hirarki')
        ->and(json_encode($hasil['keyboard']))->toContain('multi_hirarki_selesai');

    $wizard->destroyWizard($this->chatId);
});

test('kata kunci tak dikenal di dalam hirarki tetap menyediakan jalan kembali', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));
    $wizard->handleCallback($this->chatId, 'multi_hirarki');

    $hasil = $wizard->handleTextInput($this->chatId, 'QQQQ-ZZZZ-9999');
    $papan = json_encode($hasil['keyboard']);

    expect($hasil['message'])->toContain('tidak ditemukan')
        ->and($papan)->toContain('funcloc_kembali')
        // Tanpa tombol ini teknisi hanya bisa membatalkan laporan (nyangkut).
        ->and($papan)->toContain('wizard:cancel_wizard');

    // Tombol kembali benar-benar membuka layar hierarki lagi.
    $layar = $wizard->handleCallback($this->chatId, 'funcloc_kembali');

    expect(json_encode($layar['keyboard']))->toContain('funcloc_pick:0:')
        ->and($layar['message'])->toContain('Pilih PT');

    $wizard->destroyWizard($this->chatId);
});

test('tombol Selesai Menambah menutup hirarki tanpa membuang item', function () {
    $wizard = app(ReportWizardService::class);

    bukaHirarkiMultiSampaiSection(
        $wizard,
        $this->chatId,
        stateMultiEquipment($this->chatId, $this->asetUtama->id),
        $this->flSection->id
    );

    $wizard->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetTambahan->id);

    $hasil = $wizard->handleCallback($this->chatId, 'multi_hirarki_selesai');
    $state = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($state['funcloc_picker_active'] ?? false)->toBeFalse()
        ->and($state['funcloc_picker_mode'] ?? null)->toBeNull()
        ->and($state['step'])->toBe(ReportWizardService::STEP_MULTI_EQUIPMENT)
        ->and($hasil['message'])->toContain('Selesai menambah')
        ->and($hasil['message'])->toContain('MUL-M-02')
        ->and(json_encode($hasil['keyboard']))->toContain('multi_done');

    $wizard->destroyWizard($this->chatId);
});

test('tombol Hapus Terakhir bekerja tanpa keluar dari hirarki', function () {
    $wizard = app(ReportWizardService::class);

    bukaHirarkiMultiSampaiSection(
        $wizard,
        $this->chatId,
        stateMultiEquipment($this->chatId, $this->asetUtama->id),
        $this->flSection->id
    );

    $wizard->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetTambahan->id);
    $layar = $wizard->handleCallback($this->chatId, 'multi_hirarki_hapus');
    $state = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toBeEmpty()
        ->and($state['funcloc_picker_active'] ?? false)->toBeTrue()
        ->and($layar['message'])->toContain('Dihapus: MUL-M-02')
        ->and(json_encode($layar['keyboard']))->not->toContain('multi_hirarki_hapus');

    $wizard->destroyWizard($this->chatId);
});

test('lokasi yang sudah ada tidak ditambahkan dua kali dan diberi tahu apa adanya', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));
    $wizard->handleCallback($this->chatId, 'multi_hirarki');

    $pertama = $wizard->handleCallback($this->chatId, 'funcloc_confirm:' . $this->flSection->id);
    $kedua   = $wizard->handleCallback($this->chatId, 'funcloc_confirm:' . $this->flSection->id);
    $state   = $wizard->getState($this->chatId);

    expect($state['multi_items'])->toHaveCount(1)
        ->and($pertama['message'])->toContain('Ditambahkan dari hirarki')
        ->and($kedua['message'])->toContain('Sudah ada di daftar')
        ->and($kedua['message'])->toContain('Equipment Tambahan :');

    $wizard->destroyWizard($this->chatId);
});


test('layar hirarki mode multi di setiap level menyediakan tombol selesai dan hapus', function () {
    // Rantai PT -> Departemen -> Area dibuat lengkap supaya tiap level teruji
    // (pelajaran insiden #22: mode baru wajib diuji di SEMUA level hierarki).
    $flPt = FunctionalLocation::create([
        'code'         => 'PTUX9',
        'segment'      => 'PTUX9',
        'name'         => 'PT Uji Multi',
        'level'        => FunctionalLocation::LEVEL_SITE,
        'company_id'   => $this->company->id,
        'is_active'    => true,
    ]);

    $flDept = FunctionalLocation::create([
        'code'          => 'PTUX9-DEPUX9',
        'segment'       => 'DEPUX9',
        'name'          => 'Departemen Uji Multi',
        'level'         => FunctionalLocation::LEVEL_DEPARTMENT,
        'parent_id'     => $flPt->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $flArea = FunctionalLocation::create([
        'code'          => 'PTUX9-DEPUX9-AREAUX9',
        'segment'       => 'AREAUX9',
        'name'          => 'Area Uji Multi',
        'level'         => FunctionalLocation::LEVEL_AREA,
        'parent_id'     => $flDept->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->flSection->update(['parent_id' => $flArea->id]);

    $wizard = app(ReportWizardService::class);
    $wizard->saveState($this->chatId, stateMultiEquipment($this->chatId, $this->asetUtama->id));

    $root = $wizard->handleCallback($this->chatId, 'multi_hirarki');

    expect(json_encode($root['keyboard']))->toContain('multi_hirarki_selesai')
        ->and($root['message'])->toContain('Tambah alat/lokasi')
        ->and($root['message'])->toContain('Selesai Menambah bila tambahan sudah cukup');

    $levelPt     = $wizard->handleCallback($this->chatId, 'funcloc_pick:' . FunctionalLocation::LEVEL_SITE . ':' . $flPt->id);
    $levelDept   = $wizard->handleCallback($this->chatId, 'funcloc_pick:' . FunctionalLocation::LEVEL_DEPARTMENT . ':' . $flDept->id);
    $levelArea   = $wizard->handleCallback($this->chatId, 'funcloc_pick:' . FunctionalLocation::LEVEL_AREA . ':' . $flArea->id);
    $levelSection = $wizard->handleCallback($this->chatId, 'funcloc_pick:' . FunctionalLocation::LEVEL_SECTION . ':' . $this->flSection->id);

    foreach ([$levelPt, $levelDept, $levelArea, $levelSection] as $layar) {
        expect(json_encode($layar['keyboard']))->toContain('multi_hirarki_selesai')
            ->and($layar['message'])->toContain('Belum ada alat/lokasi tambahan');
    }

    // Alat utama laporan tidak boleh tergantikan oleh penelusuran hierarki.
    expect($wizard->getState($this->chatId)['equipment_id'])->toBe($this->asetUtama->id);

    $wizard->destroyWizard($this->chatId);
});


test('alat lain di lokasi yang SAMA tetap bisa ditambahkan sebagai kolaborasi alat', function () {
    $wizard = app(ReportWizardService::class);

    // State tiruan hasil penguncian alat: state['funcloc_id'] sudah terisi
    // lokasi alat utama (persis seperti lockEquipmentAndAdvance) sedangkan alat
    // tambahannya ada di lokasi yang SAMA — kasus paling sering di lapangan
    // (dua alat pada satu section). Sebelum diperbaiki, alat kedua ini dibuang
    // diam-diam sehingga teknisi merasa kolaborasi alat tidak jalan.
    $state = stateMultiEquipment($this->chatId, $this->asetUtama->id);
    $state['funcloc_id'] = $this->flSection->id;

    $wizard->saveState($this->chatId, $state);
    $wizard->handleCallback($this->chatId, 'multi_hirarki');
    $wizard->handleCallback(
        $this->chatId,
        'funcloc_pick:' . FunctionalLocation::LEVEL_SECTION . ':' . $this->flSection->id
    );

    $hasil   = $wizard->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetTambahan->id);
    $setelah = $wizard->getState($this->chatId);

    expect($setelah['multi_items'])->toHaveCount(1)
        ->and($setelah['multi_items'][0]['asset_id'])->toBe($this->asetTambahan->id)
        ->and($hasil['message'])->toContain('Ditambahkan dari hirarki')
        ->and($setelah['equipment_id'])->toBe($this->asetUtama->id);

    $wizard->destroyWizard($this->chatId);
});

test('alat utama dan lokasi utama tetap ditolak sebagai item tambahan', function () {
    $wizard = app(ReportWizardService::class);

    $state = stateMultiEquipment($this->chatId, $this->asetUtama->id);
    $state['funcloc_id'] = $this->flSection->id;

    $wizard->saveState($this->chatId, $state);
    $wizard->handleCallback($this->chatId, 'multi_hirarki');
    $wizard->handleCallback(
        $this->chatId,
        'funcloc_pick:' . FunctionalLocation::LEVEL_SECTION . ':' . $this->flSection->id
    );

    // Alat utama (asset_id sama dengan equipment_id) tidak dianggap alat tambahan.
    $hasilAlat = $wizard->handleCallback($this->chatId, 'funcloc_asset_pick:' . $this->asetUtama->id);

    expect($hasilAlat['message'])->toContain('acuan utama laporan')
        ->and($wizard->getState($this->chatId)['multi_items'])->toBeEmpty();

    // Lokasi utama laporan juga tidak perlu ditambahkan sebagai item.
    $hasilLokasi = $wizard->handleCallback($this->chatId, 'funcloc_confirm:' . $this->flSection->id);

    expect($hasilLokasi['message'])->toContain('acuan utama laporan')
        ->and($wizard->getState($this->chatId)['multi_items'])->toBeEmpty();

    $wizard->destroyWizard($this->chatId);
});

