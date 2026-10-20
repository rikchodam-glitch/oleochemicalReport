<?php

use App\Models\CutiTeknisi;
use App\Models\CutiToken;
use App\Models\Technician;
use App\Models\User;
use App\Services\CutiService;
use App\Services\Shift\ManHourService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// FITUR CUTI: TOKEN TELEGRAM, FORM TANPA LOGIN, MAN HOUR
// ============================================================

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Cuti Uji',
        'nik'         => 'CUTI001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '999000111',
    ]);

    $this->tanggal = '2026-10-15';
});

afterEach(function () {
    Carbon::setTestNow();
});

test('token cuti menyimpan hash, alasan, dan form menampilkan identitas teknisi', function () {
    $hasil = app(CutiService::class)->buatTautan($this->teknisi, 'mesin rusak');

    expect($hasil['plain'])->not->toBe('')
        ->and($hasil['token']->token_hash)->not->toBe($hasil['plain'])
        ->and($hasil['token']->alasan)->toBe('mesin rusak')
        ->and($hasil['url'])->toContain($hasil['plain']);

    $this->get(route('cuti.form', ['token' => $hasil['plain']]))
        ->assertOk()
        ->assertSee('Form Cuti Teknisi')
        ->assertSee('Teknisi Cuti Uji')
        ->assertSee('mesin rusak');
});

test('pengajuan cuti tersimpan pending dan token menjadi sekali pakai', function () {
    $hasil = app(CutiService::class)->buatTautan($this->teknisi, 'keperluan keluarga');

    $this->get(route('cuti.form', ['token' => $hasil['plain']]))->assertOk();

    $this->post(route('cuti.simpan', ['token' => $hasil['plain']]), [
        'tanggal_mulai'   => $this->tanggal,
        'tanggal_selesai' => $this->tanggal,
        'jenis'           => 'tahunan',
        'alasan'          => 'keperluan keluarga',
    ])->assertRedirect(route('cuti.selesai'));

    $tersimpan = CutiTeknisi::query()->latest('id')->firstOrFail();

    expect($tersimpan->technician_id)->toBe($this->teknisi->id)
        ->and($tersimpan->tanggal_mulai->toDateString())->toBe($this->tanggal)
        ->and($tersimpan->tanggal_selesai->toDateString())->toBe($this->tanggal)
        ->and($tersimpan->status)->toBe(CutiTeknisi::STATUS_PENDING)
        ->and($tersimpan->diajukan_via)->toBe('telegram')
        ->and($tersimpan->alasan)->toBe('keperluan keluarga');

    expect($hasil['token']->refresh()->used_at)->not->toBeNull();

    $this->get(route('cuti.selesai'))
        ->assertOk()
        ->assertSee('Pengajuan cuti terkirim')
        ->assertSee('Teknisi Cuti Uji');
});

test('tautan kedaluwarsa dan tautan yang sudah dipakai ditolak', function () {
    $hasil = app(CutiService::class)->buatTautan($this->teknisi);
    $hasil['token']->update(['expires_at' => now()->subMinute()]);

    $this->get(route('cuti.form', ['token' => $hasil['plain']]))
        ->assertStatus(410)
        ->assertSee('Tautan tidak berlaku');

    $baru = app(CutiService::class)->buatTautan($this->teknisi);
    $baru['token']->update(['used_at' => now()]);

    $this->get(route('cuti.form', ['token' => $baru['plain']]))
        ->assertStatus(410);
});

test('tautan yang sudah dibuka di perangkat lain tidak bisa dipakai lagi', function () {
    $hasil = app(CutiService::class)->buatTautan($this->teknisi);

    $this->get(route('cuti.form', ['token' => $hasil['plain']]))->assertOk();
    $this->flushSession();

    $this->get(route('cuti.form', ['token' => $hasil['plain']]))
        ->assertStatus(410)
        ->assertSee('perangkat lain');
});

test('pengajuan yang beririsan ditolak dan tidak membuat baris baru', function () {
    CutiTeknisi::create([
        'technician_id'   => $this->teknisi->id,
        'tanggal_mulai'   => $this->tanggal,
        'tanggal_selesai' => $this->tanggal,
        'jenis'           => 'tahunan',
        'status'          => CutiTeknisi::STATUS_PENDING,
    ]);

    $hasil = app(CutiService::class)->buatTautan($this->teknisi);
    $this->get(route('cuti.form', ['token' => $hasil['plain']]))->assertOk();

    $this->post(route('cuti.simpan', ['token' => $hasil['plain']]), [
        'tanggal_mulai'   => $this->tanggal,
        'tanggal_selesai' => $this->tanggal,
        'jenis'           => 'tahunan',
        'alasan'          => 'bentrok',
    ])->assertSessionHasErrors('tanggal_mulai');

    expect(CutiTeknisi::count())->toBe(1);
});

test('admin menyetujui cuti dan target man hour hari itu menjadi nol', function () {
    $cuti = CutiTeknisi::create([
        'technician_id'   => $this->teknisi->id,
        'tanggal_mulai'   => $this->tanggal,
        'tanggal_selesai' => $this->tanggal,
        'jenis'           => 'tahunan',
        'alasan'          => 'izin keluarga',
        'status'          => CutiTeknisi::STATUS_PENDING,
    ]);

    $this->actingAs($this->admin)
        ->post(route('cuti.admin.setujui', $cuti), ['catatan' => 'Disetujui'])
        ->assertRedirect();

    $cuti->refresh();

    expect($cuti->status)->toBe(CutiTeknisi::STATUS_DISETUJUI)
        ->and($cuti->disetujui_oleh)->toBe($this->admin->id)
        ->and($cuti->catatan_approver)->toBe('Disetujui');

    $manHour = app(ManHourService::class);
    $harian  = $manHour->harian($this->teknisi, Carbon::parse($this->tanggal), Carbon::parse($this->tanggal));
    $ringkas = $manHour->ringkasan($harian);

    expect($harian[0]['target_menit'])->toBe(0)
        ->and($harian[0]['status'])->toBe('cuti')
        ->and($harian[0]['cuti'])->toBeTrue()
        ->and($ringkas['hari_cuti'])->toBe(1)
        ->and($ringkas['hari_kerja'])->toBe(0);

    $target = $manHour->targetPerTeknisi(
        [$this->teknisi->id],
        Carbon::parse($this->tanggal),
        Carbon::parse($this->tanggal)
    );

    expect($target[$this->teknisi->id] ?? null)->toBe(0);
});

test('cuti pada hari OFF tidak menambah hari cuti atau mengubah target', function () {
    $this->travelTo(Carbon::parse('2026-10-18 09:00:00'));

    CutiTeknisi::create([
        'technician_id'   => $this->teknisi->id,
        'tanggal_mulai'   => '2026-10-18',
        'tanggal_selesai' => '2026-10-18',
        'jenis'           => 'tahunan',
        'status'          => CutiTeknisi::STATUS_DISETUJUI,
    ]);

    $manHour = app(ManHourService::class);
    $harian  = $manHour->harian($this->teknisi, Carbon::parse('2026-10-18'), Carbon::parse('2026-10-18'));
    $ringkas = $manHour->ringkasan($harian);

    expect($harian[0]['target_menit'])->toBe(0)
        ->and($harian[0]['cuti'])->toBeFalse()
        ->and($harian[0]['status'])->toBe('libur')
        ->and($ringkas['hari_cuti'])->toBe(0);
});

test('cuti pending belum mengurangi target man hour', function () {
    CutiTeknisi::create([
        'technician_id'   => $this->teknisi->id,
        'tanggal_mulai'   => $this->tanggal,
        'tanggal_selesai' => $this->tanggal,
        'jenis'           => 'tahunan',
        'status'          => CutiTeknisi::STATUS_PENDING,
    ]);

    $harian = app(ManHourService::class)->harian(
        $this->teknisi,
        Carbon::parse($this->tanggal),
        Carbon::parse($this->tanggal)
    );

    expect($harian[0]['target_menit'])->toBeGreaterThan(0)
        ->and($harian[0]['cuti'])->toBeFalse();
});

test('perintah /cuti dengan alasan membuat token berisi alasan tanpa membuat laporan', function () {
    $this->post(route('telegram.webhook'), [
        'message' => [
            'chat' => ['id' => 12345],
            'text' => '/cuti mesin rusak',
            'from' => [
                'id'       => (int) $this->teknisi->telegram_id,
                'username' => 'teknisi_uji',
                'first_name' => 'Teknisi',
            ],
        ],
    ])->assertOk();

    $token = CutiToken::latest('id')->first();

    expect($token)->not->toBeNull()
        ->and($token->technician_id)->toBe($this->teknisi->id)
        ->and($token->alasan)->toBe('mesin rusak')
        ->and($token->used_at)->toBeNull()
        ->and(\App\Models\Report::count())->toBe(0);
});

test('halaman cuti admin hanya untuk administrator', function () {
    $this->actingAs($this->admin)
        ->get(route('cuti.admin.index'))
        ->assertOk()
        ->assertSee('Cuti Teknisi');

    $bagian = User::create([
        'name'     => 'Akun Bagian Uji',
        'username' => 'akun-cuti-bagian',
        'email'    => 'akun-cuti-bagian@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($bagian)
        ->get(route('cuti.admin.index'))
        ->assertForbidden();
});
