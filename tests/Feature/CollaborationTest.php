<?php

use App\Models\CollaborationGroup;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\CollaborationService;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin     = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->kolaborasi = app(CollaborationService::class);

    // Tiga teknisi: dua di Grub D (satu di antaranya pelapor) dan satu reguler.
    $this->pelapor = Technician::create([
        'name'        => 'Pelapor Grub D',
        'nik'         => 'KD001',
        'group'       => 'grub_d',
        'telegram_id' => '9001',
        'status'      => 'active',
    ]);

    $this->rekan = Technician::create([
        'name'        => 'Rekan Grub D',
        'nik'         => 'KD002',
        'group'       => 'grub_d',
        'telegram_id' => '9002',
        'status'      => 'active',
    ]);

    $this->orangReguler = Technician::create([
        'name'   => 'Teknisi Reguler',
        'nik'    => 'KR001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->groupD = $this->kolaborasi->buatGrup([
        'name'             => 'Grub D',
        'code'             => 'grub_d',
        'is_active'        => true,
        'auto_collaborate' => true,
    ]);

    $this->kolaborasi->tambahAnggota($this->groupD, (int) $this->pelapor->id);
    $this->kolaborasi->tambahAnggota($this->groupD, (int) $this->rekan->id);
});

// ============================================================
// SERVICE: KOLABORATOR OTOMATIS
// ============================================================

test('rekan satu kelompok otomatis jadi kolaborator, pelapor sendiri dikecualikan', function () {
    expect($this->kolaborasi->autoCollaboratorsFor($this->pelapor)->pluck('nik')->all())->toBe(['KD002']);
});

test('teknisi di luar kelompok tidak ikut otomatis', function () {
    expect($this->kolaborasi->autoCollaboratorsFor($this->orangReguler))->toBeEmpty();
});

test('kelompok nonaktif atau otomasi mati tidak mengikutkan anggota', function () {
    $this->groupD->update(['auto_collaborate' => false]);
    expect($this->kolaborasi->autoCollaboratorsFor($this->pelapor))->toBeEmpty();

    $this->groupD->update(['auto_collaborate' => true, 'is_active' => false]);
    expect($this->kolaborasi->autoCollaboratorsFor($this->pelapor))->toBeEmpty();
});

test('penggabungan kolaborator manual dan otomatis tanpa duplikat', function () {
    $gabungan = $this->kolaborasi->gabungKolaborator(
        $this->pelapor,
        ['KR001', 'KD002'],
        ['Teknisi Reguler', 'Rekan Grub D']
    );

    // Manual lebih dulu; otomatis hanya menambah yang belum ada.
    expect($gabungan['niks'])->toBe(['KR001', 'KD002'])
        ->and($gabungan['auto_niks'])->toBe([]);
});

test('jumlah kolaborator dibatasi lima nama', function () {
    foreach (range(1, 7) as $nomor) {
        $anggota = Technician::create([
            'name'   => 'Anggota Tambahan ' . $nomor,
            'nik'    => 'KT00' . $nomor,
            'group'  => 'grub_d',
            'status' => 'active',
        ]);

        $this->kolaborasi->tambahAnggota($this->groupD, (int) $anggota->id);
    }

    expect($this->kolaborasi->gabungKolaborator($this->pelapor, [], [])['niks'])
        ->toHaveCount(CollaborationService::MAX_KOLABORATOR);
});

test('perintah sinkronisasi membuat kelompok grub tanpa reguler', function () {
    CollaborationGroup::query()->delete();

    $hasil = $this->kolaborasi->sinkronkanKelompokGrupTeknisi();

    expect(collect($hasil)->pluck('code')->all())->toBe(['grub_a', 'grub_b', 'grub_c', 'grub_d'])
        ->and(CollaborationGroup::where('code', 'reguler')->exists())->toBeFalse();

    $grubD = CollaborationGroup::where('code', 'grub_d')->firstOrFail();

    expect($grubD->members()->pluck('nik')->sort()->values()->all())->toBe(['KD001', 'KD002'])
        ->and($grubD->auto_collaborate)->toBeTrue();
});


// ============================================================
// HALAMAN KOLABORASI
// ============================================================

test('halaman kolaborasi menampilkan kelompok dan anggotanya', function () {
    $this->actingAs($this->admin)
        ->get('/collaboration')
        ->assertStatus(200)
        ->assertSee('Grub D', false)
        ->assertSee('Pelapor Grub D', false)
        ->assertSee('Otomatis ikut andil', false)
        ->assertSee('Selaraskan dari grup teknisi', false);
});

test('kelompok baru bisa dibuat dari halaman kolaborasi', function () {
    $this->actingAs($this->admin)->post('/collaboration/groups', [
        'name'             => 'Grub Malam',
        'code'             => 'grub_malam',
        'is_active'        => '1',
        'auto_collaborate' => '1',
        'sort_order'       => 3,
    ])->assertRedirect(route('collaboration.index'));

    $group = CollaborationGroup::where('code', 'grub_malam')->firstOrFail();

    expect($group->name)->toBe('Grub Malam')
        ->and($group->is_active)->toBeTrue()
        ->and($group->auto_collaborate)->toBeTrue();
});

test('anggota bisa ditambah dan dihapus dari kelompok', function () {
    $this->actingAs($this->admin)->post('/collaboration/groups/' . $this->groupD->id . '/members', [
        'technician_id' => $this->orangReguler->id,
    ])->assertRedirect(route('collaboration.index'));

    expect($this->groupD->fresh()->members()->pluck('nik'))->toContain('KR001');

    $this->actingAs($this->admin)->delete(
        '/collaboration/groups/' . $this->groupD->id . '/members/' . $this->orangReguler->id
    )->assertRedirect(route('collaboration.index'));

    expect($this->groupD->fresh()->members()->pluck('nik'))->not->toContain('KR001');
});

test('tombol selaraskan mengisi anggota dari grup teknisi', function () {
    $groupA = $this->kolaborasi->buatGrup(['name' => 'Grub A', 'code' => 'grub_a']);

    $anggotaA = Technician::create([
        'name'   => 'Anggota Grub A',
        'nik'    => 'KA001',
        'group'  => 'grub_a',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->post('/collaboration/groups/' . $groupA->id . '/sync')
        ->assertRedirect(route('collaboration.index'));

    expect($groupA->fresh()->members()->pluck('nik'))->toContain($anggotaA->nik);
});

test('pengaturan kelompok bisa diubah dan kelompok bisa dihapus', function () {
    $this->actingAs($this->admin)->put('/collaboration/groups/' . $this->groupD->id, [
        'name'        => 'Grub D Revisi',
        'description' => 'Shift malam',
        'sort_order'  => 5,
        // checkbox tidak dikirim = nonaktif + otomasi mati
    ])->assertRedirect(route('collaboration.index'));

    $group = $this->groupD->fresh();

    expect($group->name)->toBe('Grub D Revisi')
        ->and($group->description)->toBe('Shift malam')
        ->and($group->is_active)->toBeFalse()
        ->and($group->auto_collaborate)->toBeFalse();

    $this->actingAs($this->admin)
        ->delete('/collaboration/groups/' . $this->groupD->id)
        ->assertRedirect(route('collaboration.index'));

    expect(CollaborationGroup::where('code', 'grub_d')->exists())->toBeFalse();
});


// ============================================================
// WIZARD: TIM OTOMATIS MUNCUL DI TAHAP KOLABORASI
// ============================================================

test('tahap kolaborasi wizard otomatis mencantumkan rekan satu grub', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = '9001';

    $wizard->saveState($chatId, [
        'chat_id'                 => $chatId,
        'text'                    => 'Perbaikan motor',
        'step'                    => ReportWizardService::STEP_PHOTO_HYGIENE,
        'step_history'            => [],
        'equipment_id'            => null,
        'equipment_ident'         => '6010MO01',
        'work_duration_minutes'   => 30,
        'root_cause'              => 'Bearing aus',
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => [],
        'collaborator_names'      => [],
        'created_at'              => now()->toIso8601String(),
    ]);

    $respons = $wizard->handleCallback($chatId, 'wizard:confirm:photo_hygiene_done');

    expect($respons['message'])->toContain('ikut andil otomatis')
        ->and($respons['message'])->toContain('Rekan Grub D');

    $state = $wizard->getState($chatId);

    expect($state['collaborator_niks'])->toBe(['KD002'])
        ->and($state['collaborator_auto_niks'])->toBe(['KD002'])
        ->and($state['step'])->toBe(ReportWizardService::STEP_COLLABORATOR);

    $wizard->destroyWizard($chatId);
});

test('menu Sesuaikan bisa menghapus satu nama dan tidak muncul lagi', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = '9001';

    $wizard->saveState($chatId, [
        'chat_id'                => $chatId,
        'text'                   => 'Perbaikan motor',
        'step'                   => ReportWizardService::STEP_COLLABORATOR,
        'step_history'           => [],
        'collaborator_niks'      => ['KD002', 'KR001'],
        'collaborator_names'     => ['Rekan Grub D', 'Teknisi Reguler'],
        'collaborator_auto_niks' => ['KD002'],
        'collab_auto_applied'    => true,
        'created_at'             => now()->toIso8601String(),
    ]);

    // Menu Sesuaikan menampilkan tombol hapus per nama.
    $menu = $wizard->handleCallback($chatId, 'collab_adjust');

    expect($menu['message'])->toContain('Sesuaikan Kolaborator')
        ->and(collect($menu['keyboard'])->pluck('callback_data'))->toContain('collab_hapus:0')
        ->and(collect($menu['keyboard'])->pluck('text')->implode(' | '))->toContain('Rekan Grub D');

    // Hapus nama pertama (berasal dari tim otomatis).
    $hapus = $wizard->handleCallback($chatId, 'collab_hapus:0');

    expect($hapus['message'])->toContain('dihapus');

    $state = $wizard->getState($chatId);

    expect($state['collaborator_niks'])->toBe(['KR001'])
        ->and($state['collaborator_auto_niks'])->toBe([])
        ->and($state['collab_auto_applied'])->toBeTrue();

    // Kembali ke prompt kolaborator: nama yang dihapus tidak muncul lagi.
    $prompt = $wizard->handleCallback($chatId, 'collab_adjust_selesai');

    expect($prompt['message'])->toContain('Teknisi Reguler')
        ->and($prompt['message'])->not->toContain('Rekan Grub D');

    $wizard->destroyWizard($chatId);
});


// ============================================================
// PENGHAPUSAN KOLABORATOR OTOMATIS HARUS IKUT TERSIMPAN
// ============================================================

test('nama otomatis yang dihapus tidak ikut tersimpan, nama penggantinya masuk', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = '9001';

    $wizard->saveState($chatId, [
        'chat_id'                 => $chatId,
        'text'                    => 'Perbaikan pompa transfer',
        'step'                    => ReportWizardService::STEP_COLLABORATOR,
        'step_history'            => [],
        'work_classification'     => 'zpm3',
        'jenis_pekerjaan'         => 'CM/PM',
        'equipment_id'            => null,
        'is_area_work'            => true,
        'work_duration_minutes'   => 60,
        'start_time'              => '08:00',
        'end_time'                => '09:00',
        'root_cause'              => 'Impeller aus',
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => ['KD002'],
        'collaborator_names'      => ['Rekan Grub D'],
        'collaborator_auto_niks'  => ['KD002'],
        'collab_auto_applied'     => true,
        'created_at'              => now()->toIso8601String(),
    ]);

    // Rekan otomatis (tim) dihapus lewat menu Sesuaikan, lalu diganti orang lain.
    $wizard->handleCallback($chatId, 'collab_hapus:0');
    $wizard->handleTextInput($chatId, 'KR001');

    $state = $wizard->getState($chatId);

    expect($state['collaborator_niks'])->toBe(['KR001'])
        ->and($state['collaborator_dikecualikan'])->toBe(['KD002']);

    $wizard->handleCallback($chatId, 'wizard:confirm:collab_done');
    $wizard->handleCallback($chatId, 'wizard:confirm:save_done');

    $induk = Report::where('technician_id', $this->pelapor->id)
        ->whereNull('collaborator_of')
        ->latest('id')
        ->firstOrFail();

    // Hanya nama pengganti yang mendapat salinan kolaborasi.
    expect(Report::where('collaborator_of', $induk->id)->pluck('technician_id')->all())
        ->toBe([$this->orangReguler->id]);
});

test('tombol Lewati pada Step 8a menyimpan laporan tanpa salinan kolaborasi', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = '9001';

    $wizard->saveState($chatId, [
        'chat_id'                 => $chatId,
        'text'                    => 'Perbaikan pompa transfer',
        'step'                    => ReportWizardService::STEP_COLLABORATOR,
        'step_history'            => [],
        'work_classification'     => 'zpm3',
        'jenis_pekerjaan'         => 'CM/PM',
        'equipment_id'            => null,
        'is_area_work'            => true,
        'work_duration_minutes'   => 60,
        'start_time'              => '08:00',
        'end_time'                => '09:00',
        'root_cause'              => 'Impeller aus',
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => ['KD002'],
        'collaborator_names'      => ['Rekan Grub D'],
        'collaborator_auto_niks'  => ['KD002'],
        'collab_auto_applied'     => true,
        'created_at'              => now()->toIso8601String(),
    ]);

    $wizard->handleCallback($chatId, 'wizard:confirm:collab_skip');

    $state = $wizard->getState($chatId);

    expect($state['collaborator_niks'] ?? [])->toBe([])
        ->and($state['collaborator_dikecualikan'])->toBe(['KD002']);

    $wizard->handleCallback($chatId, 'wizard:confirm:save_done');

    $induk = Report::where('technician_id', $this->pelapor->id)
        ->whereNull('collaborator_of')
        ->latest('id')
        ->firstOrFail();

    expect(Report::where('collaborator_of', $induk->id)->count())->toBe(0);
});

test('rekan kelompok tetap otomatis ikut tersimpan bila tidak ada yang dihapus', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = '9001';

    $wizard->saveState($chatId, [
        'chat_id'                 => $chatId,
        'text'                    => 'Perbaikan pompa transfer',
        'step'                    => ReportWizardService::STEP_CONFIRMATION,
        'step_history'            => [],
        'work_classification'     => 'zpm3',
        'jenis_pekerjaan'         => 'CM/PM',
        'equipment_id'            => null,
        'is_area_work'            => true,
        'work_duration_minutes'   => 60,
        'start_time'              => '08:00',
        'end_time'                => '09:00',
        'root_cause'              => 'Impeller aus',
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => [],
        'collaborator_names'      => [],
        'created_at'              => now()->toIso8601String(),
    ]);

    $wizard->handleCallback($chatId, 'wizard:confirm:save_done');

    $induk = Report::where('technician_id', $this->pelapor->id)
        ->whereNull('collaborator_of')
        ->latest('id')
        ->firstOrFail();

    expect(Report::where('collaborator_of', $induk->id)->pluck('technician_id')->all())
        ->toBe([$this->rekan->id]);
});

