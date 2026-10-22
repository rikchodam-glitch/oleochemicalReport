<?php

use App\Jobs\KirimNotifikasiLaporanDiterima;
use App\Models\Area;
use App\Models\CollaborationGroup;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\ReportToken;
use App\Models\Technician;
use App\Models\User;
use App\Services\ReportFormService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ============================================================
// FORM LAPORAN WEB BERBASIS TOKEN /laporan
// ============================================================

beforeEach(function () {
    $this->seed();

    $perusahaan = Company::firstOrCreate(['code' => 'FORM'], ['name' => 'PT Form Uji']);
    $departemen = Department::firstOrCreate(
        ['company_id' => $perusahaan->id, 'code' => 'FORM-D'],
        ['name' => 'Dept Form Uji']
    );
    $area = Area::firstOrCreate(
        ['department_id' => $departemen->id, 'code' => 'FORM-A'],
        ['name' => 'Area Form Uji']
    );

    $pt = FunctionalLocation::create([
        'code' => 'FORM-PT', 'segment' => 'FORM-PT', 'name' => 'PT Form', 'level' => 0,
        'company_id' => $perusahaan->id, 'is_active' => true,
    ]);
    $dept = FunctionalLocation::create([
        'code' => 'FORM-DEPT', 'segment' => 'FORM-DEPT', 'name' => 'Dept Form', 'level' => 1,
        'parent_id' => $pt->id, 'company_id' => $perusahaan->id,
        'department_id' => $departemen->id, 'is_active' => true,
    ]);
    $areaNode = FunctionalLocation::create([
        'code' => 'FORM-AREA', 'segment' => 'FORM-AREA', 'name' => 'Area Form', 'level' => 2,
        'parent_id' => $dept->id, 'company_id' => $perusahaan->id,
        'department_id' => $departemen->id, 'area_id' => $area->id, 'is_active' => true,
    ]);
    $this->section = FunctionalLocation::create([
        'code' => 'FORM-SEC', 'segment' => 'FORM-SEC', 'name' => 'Section Form', 'level' => 3,
        'parent_id' => $areaNode->id, 'company_id' => $perusahaan->id,
        'department_id' => $departemen->id, 'area_id' => $area->id, 'is_active' => true,
    ]);

    $this->aset = Asset::create([
        'tech_ident_no'  => 'FORM-PUMP-01',
        'description'    => 'Pompa Form Uji',
        'equipment_type' => 'PUMP',
        'funcloc_id'     => $this->section->id,
        'company_id'     => $perusahaan->id,
        'department_id'  => $departemen->id,
        'area_id'        => $area->id,
        'status'         => 'active',
    ]);

    $this->teknisi = Technician::create([
        'name' => 'Teknisi Form Token', 'nik' => 'FORM-TK-01',
        'group' => 'reguler', 'section' => 'mekanik', 'status' => 'active',
    ]);

    $this->rekan = Technician::create([
        'name' => 'Rekan Form Token', 'nik' => 'FORM-TK-02',
        'group' => 'reguler', 'section' => 'mekanik', 'status' => 'active',
    ]);

    $this->token = app(ReportFormService::class)->buatToken($this->teknisi, 'Deskripsi dari Telegram')['plain'];

    Queue::fake();
});

test('form laporan terbuka dari token tanpa login', function () {
    $this->get(route('laporan.form', ['token' => $this->token]))
        ->assertOk()
        ->assertSee('Form Laporan Teknisi')
        ->assertSee('Teknisi Form Token')
        ->assertSee('Deskripsi dari Telegram');
});

test('laporan equipment tersimpan dengan durasi jam dan salinan kolaborator', function () {
    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [[
            'report_date'           => '2026-10-20',
            'work_classification'   => 'zpm1',
            'jenis_pekerjaan'       => 'Mekanik',
            'work_description'      => 'Perbaikan pompa form',
            'start_time'            => '08:00',
            'end_time'              => '09:30',
            'root_cause'            => 'Bearing aus',
            'finding'               => 'Getaran tinggi',
            'final_status'          => 'completed',
            'items'                 => [[
                'funcloc_id'     => $this->section->id,
                'asset_id'       => $this->aset->id,
                'equipment_type' => 'PUMP',
            ]],
            'collaborator_ids'      => [$this->rekan->id],
        ]],
    ])->assertRedirect(route('laporan.selesai'));

    $laporan = Report::query()->where('technician_id', $this->teknisi->id)->firstOrFail();

    expect($laporan->work_duration_minutes)->toBe(90)
        ->and($laporan->start_time)->toBe('08:00')
        ->and($laporan->end_time)->toBe('09:30')
        ->and($laporan->report_type)->toBe('equipment_repair')
        ->and($laporan->asset_id)->toBe($this->aset->id)
        ->and($laporan->status)->toBe('completed')
        ->and(Report::where('collaborator_of', $laporan->id)->where('technician_id', $this->rekan->id)->exists())->toBeTrue();

    expect(ReportToken::latest('id')->first()?->used_at)->not->toBeNull();

    Queue::assertPushed(
        KirimNotifikasiLaporanDiterima::class,
        fn (KirimNotifikasiLaporanDiterima $job) => in_array($laporan->id, $job->reportIds, true)
    );
});

test('laporan area tanpa alat dan multi alat membuat salinan multi', function () {
    $asetKedua = Asset::create([
        'tech_ident_no'  => 'FORM-PUMP-02',
        'description'    => 'Pompa Form Uji 2',
        'equipment_type' => 'PUMP',
        'funcloc_id'     => $this->section->id,
        'company_id'     => $this->aset->company_id,
        'department_id'  => $this->aset->department_id,
        'area_id'        => $this->aset->area_id,
        'status'         => 'active',
    ]);

    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [[
            'report_date'         => '2026-10-20',
            'work_classification' => 'zpm1',
            'jenis_pekerjaan'     => 'Mekanik',
            'work_description'    => 'Pekerjaan dua alat',
            'start_time'          => '10:00',
            'end_time'            => '11:00',
            'final_status'        => 'completed',
            'items'               => [
                ['funcloc_id' => $this->section->id, 'asset_id' => $this->aset->id, 'equipment_type' => 'PUMP'],
                ['funcloc_id' => $this->section->id, 'asset_id' => $asetKedua->id, 'equipment_type' => 'PUMP'],
            ],
        ]],
    ])->assertRedirect(route('laporan.selesai'));

    $induk = Report::query()->whereNull('multi_of')->firstOrFail();

    expect(Report::where('multi_of', $induk->id)->count())->toBe(1)
        ->and(Report::where('multi_of', $induk->id)->first()->asset_id)->toBe($asetKedua->id);
});

test('banyak laporan dalam satu kirim disimpan sekaligus', function () {
    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [
            [
                'report_date' => '2026-10-20', 'work_classification' => 'zpm1',
                'jenis_pekerjaan' => 'Mekanik', 'work_description' => 'Laporan pertama',
                'start_time' => '08:00', 'end_time' => '09:00', 'final_status' => 'completed',
                'items' => [['funcloc_id' => $this->section->id, 'asset_id' => $this->aset->id, 'equipment_type' => 'PUMP']],
            ],
            [
                'report_date' => '2026-10-20', 'work_classification' => 'umum',
                'jenis_pekerjaan' => 'UMUM', 'work_description' => 'Laporan kedua',
                'start_time' => '09:00', 'end_time' => '10:00', 'final_status' => 'continue',
                'items' => [['funcloc_id' => $this->section->id]],
            ],
        ],
    ])->assertRedirect(route('laporan.selesai'));

    $daftar = Report::orderBy('id')->get();

    expect($daftar)->toHaveCount(2)
        ->and($daftar[0]->report_type)->toBe('equipment_repair')
        ->and($daftar[1]->report_type)->toBe('area_work')
        ->and($daftar[1]->status)->toBe('continue')
        ->and($daftar[1]->completed_at)->toBeNull();
});

test('kolaborator otomatis dari group ikut dibuatkan salinan', function () {
    $group = CollaborationGroup::create([
        'name' => 'Grup Form Uji', 'code' => 'FORM-GRP',
        'is_active' => true, 'auto_collaborate' => true,
    ]);
    $group->members()->attach([$this->teknisi->id, $this->rekan->id]);

    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [[
            'report_date' => '2026-10-20', 'work_classification' => 'zpm1',
            'jenis_pekerjaan' => 'Mekanik', 'work_description' => 'Laporan kolaborasi otomatis',
            'start_time' => '08:00', 'end_time' => '09:00', 'final_status' => 'completed',
            'items' => [['funcloc_id' => $this->section->id]],
        ]],
    ])->assertRedirect(route('laporan.selesai'));

    $induk = Report::whereNull('collaborator_of')->firstOrFail();

    expect(Report::where('collaborator_of', $induk->id)->where('technician_id', $this->rekan->id)->exists())->toBeTrue();
});

test('lokasi yang berhenti di PT tetap tersimpan sebagai pekerjaan area', function () {
    $pt = FunctionalLocation::where('code', 'FORM-PT')->firstOrFail();

    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [[
            'report_date' => '2026-10-20', 'work_classification' => 'umum',
            'jenis_pekerjaan' => 'UMUM', 'work_description' => 'Pekerjaan area level PT',
            'start_time' => '08:00', 'end_time' => '09:00', 'final_status' => 'completed',
            'items' => [['funcloc_id' => $pt->id]],
        ]],
    ])->assertRedirect(route('laporan.selesai'));

    $laporan = Report::firstOrFail();

    expect($laporan->report_type)->toBe('area_work')
        ->and($laporan->funcloc_id)->toBe($pt->id)
        ->and($laporan->asset_id)->toBeNull();
});

test('alat di luar lokasi yang dipilih ditolak', function () {
    $lain = FunctionalLocation::create([
        'code' => 'FORM-LAIN', 'segment' => 'FORM-LAIN', 'name' => 'Lokasi Lain',
        'level' => 3, 'is_active' => true,
    ]);

    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [[
            'report_date' => '2026-10-20', 'work_classification' => 'zpm1',
            'jenis_pekerjaan' => 'Mekanik', 'work_description' => 'Alat salah lokasi',
            'start_time' => '08:00', 'end_time' => '09:00', 'final_status' => 'completed',
            'items' => [['funcloc_id' => $lain->id, 'asset_id' => $this->aset->id, 'equipment_type' => 'PUMP']],
        ]],
    ])->assertSessionHasErrors();

    expect(Report::count())->toBe(0);
});

test('klasifikasi dan jenis yang tidak cocok ditolak', function () {
    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [[
            'report_date'         => '2026-10-20',
            'work_classification' => 'zpm3',
            'jenis_pekerjaan'     => 'Mekanik',
            'work_description'    => 'Tidak cocok',
            'start_time'          => '08:00',
            'end_time'            => '09:00',
            'final_status'        => 'completed',
            'items'               => [['funcloc_id' => $this->section->id]],
        ]],
    ])->assertSessionHasErrors('reports.0.jenis_pekerjaan');

    expect(Report::count())->toBe(0);
});

test('satu token hanya bisa dipakai sekali', function () {
    $payload = [
        'reports' => [[
            'report_date'         => '2026-10-20',
            'work_classification' => 'umum',
            'jenis_pekerjaan'     => 'UMUM',
            'work_description'    => 'Laporan umum',
            'start_time'          => '08:00',
            'end_time'            => '08:30',
            'final_status'        => 'continue',
            'items'               => [['funcloc_id' => $this->section->id]],
        ]],
    ];

    $this->post(route('laporan.simpan', ['token' => $this->token]), $payload)->assertRedirect(route('laporan.selesai'));
    $this->get(route('laporan.form', ['token' => $this->token]))->assertStatus(410);
});

test('foto dokumentasi lebih dari satu tersimpan', function () {
    Storage::fake(config('telegram.photo_disk', 'public'));

    $this->post(route('laporan.simpan', ['token' => $this->token]), [
        'reports' => [[
            'report_date'         => '2026-10-20',
            'work_classification' => 'zpm1',
            'jenis_pekerjaan'     => 'Mekanik',
            'work_description'    => 'Laporan dengan dua foto',
            'start_time'          => '08:00',
            'end_time'            => '09:00',
            'final_status'        => 'completed',
            'items'               => [['funcloc_id' => $this->section->id]],
            'photos_documentation' => [
                UploadedFile::fake()->image('foto-1.jpg'),
                UploadedFile::fake()->image('foto-2.jpg'),
            ],
        ]],
    ])->assertRedirect(route('laporan.selesai'));

    expect(Report::firstOrFail()->photo_documentation)->toHaveCount(2);
});

test('api tipe dan pencarian alat memakai token dan lokasi', function () {
    $this->get(route('laporan.api.tipe', ['token' => $this->token, 'funcloc_id' => $this->section->id]))
        ->assertOk()
        ->assertJsonFragment(['PUMP']);

    $this->get(route('laporan.api.alat', [
        'token' => $this->token,
        'funcloc_id' => $this->section->id,
        'type' => 'PUMP',
        'q' => 'pompa',
    ]))
        ->assertOk()
        ->assertJsonFragment(['tech_ident_no' => 'FORM-PUMP-01']);
});

test('perintah /laporan di poller membuat token dengan deskripsi', function () {
    $handler = new class {
        use \App\Console\Commands\Traits\TelegramMessageHandlerTrait;

        public array $terkirim = [];

        public function proses(array $update): void
        {
            $this->processUpdate($update);
        }

        private function line(string $pesan): void
        {
        }

        private function sendMessage(int|string $chatId, string $text, array $extra = []): void
        {
            $this->terkirim[] = $text;
        }
    };

    $this->teknisi->update(['telegram_id' => '999000123']);

    $handler->proses([
        'message' => [
            'chat' => ['id' => 12345],
            'text' => '/laporan pompa bergetar',
            'from' => ['id' => 999000123, 'username' => 'form_uji', 'first_name' => 'Form'],
        ],
    ]);

    $token = ReportToken::latest('id')->first();

    expect($token)->not->toBeNull()
        ->and($token->description)->toBe('pompa bergetar')
        ->and($handler->terkirim[0] ?? '')->toContain('/laporan/form/')
        ->and(Report::count())->toBe(0);
});

test('perintah /laporan lewat webhook juga membuat token', function () {
    $this->teknisi->update(['telegram_id' => '999000124']);

    $this->post(route('telegram.webhook'), [
        'message' => [
            'chat' => ['id' => 12346],
            'text' => '/laporan kompresor panas',
            'from' => ['id' => 999000124, 'username' => 'form_webhook', 'first_name' => 'Form'],
        ],
    ])->assertOk();

    $token = ReportToken::latest('id')->first();

    expect($token)->not->toBeNull()
        ->and($token->description)->toBe('kompresor panas')
        ->and(Report::count())->toBe(0);
});

test('job notifikasi mengirim pesan laporan diterima ke telegram pelapor', function () {
    $this->teknisi->update(['telegram_id' => '999000125']);

    $laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => '2026-10-20',
        'report_code'           => 'RPT-NOTIF-01',
        'work_description'      => 'Laporan notifikasi',
        'work_duration_minutes' => 30,
        'status'                => 'completed',
    ]);

    config(['services.telegram.bot_token' => '123:notif-uji']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    (new KirimNotifikasiLaporanDiterima([$laporan->id]))
        ->handle(app(TelegramService::class));

    Http::assertSent(function ($request) use ($laporan) {
        return (string) $request['chat_id'] === '999000125'
            && str_contains((string) $request['text'], 'Laporan diterima')
            && str_contains((string) $request['text'], 'RPT-NOTIF-01')
            && str_contains((string) $request['text'], '/reports/' . $laporan->id);
    });
});

test('akun tanpa teknisi tidak bisa memakai route login form', function () {
    $tanpaTeknisi = User::create([
        'name' => 'Akun Tanpa Teknisi Form', 'username' => 'form-tanpa-teknisi-2',
        'email' => 'form-tanpa-teknisi-2@oleochemical.pro', 'password' => bcrypt('rahasia123'),
        'role' => 'mekanik',
    ]);

    $this->actingAs($tanpaTeknisi)
        ->get(route('laporan.saya.create'))
        ->assertForbidden();
});
