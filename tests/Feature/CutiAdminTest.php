<?php

use App\Models\CutiTeknisi;
use App\Models\Technician;
use App\Models\User;
use App\Services\Shift\ManHourService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// CUTI DIINPUT ADMIN REGULER
// ============================================================

beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'    => 'Teknisi Cuti Admin',
        'nik'     => 'CUTIADM01',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->adminReguler = User::create([
        'name'     => 'Admin Reguler Uji',
        'username' => 'admin-reguler-uji',
        'email'    => 'admin-reguler-uji@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => User::ROLE_ADMIN_REGULER,
    ]);
});

test('admin reguler hanya mendapat akses dashboard, teknisi, dan cuti', function () {
    expect($this->adminReguler->bolehKelolaCuti())->toBeTrue()
        ->and($this->adminReguler->bolehKelolaUser())->toBeFalse()
        ->and($this->adminReguler->bolehMenulis())->toBeFalse()
        ->and($this->adminReguler->punyaAkses('dashboard'))->toBeTrue()
        ->and($this->adminReguler->punyaAkses('technicians'))->toBeTrue()
        ->and($this->adminReguler->punyaAkses('cuti'))->toBeTrue()
        ->and($this->adminReguler->punyaAkses('reports'))->toBeFalse();
});

test('admin reguler mencatat cuti dan target man hour langsung menjadi nol', function () {
    $this->actingAs($this->adminReguler)
        ->post(route('cuti.kelola.store', $this->teknisi), [
            'tanggal_mulai'   => '2026-10-15',
            'tanggal_selesai' => '2026-10-15',
            'jenis'           => 'tahunan',
            'alasan'          => 'Keperluan keluarga',
        ])
        ->assertRedirect(route('cuti.kelola.index', $this->teknisi));

    $cuti = CutiTeknisi::query()->firstOrFail();

    expect($cuti->technician_id)->toBe($this->teknisi->id)
        ->and($cuti->status)->toBe(CutiTeknisi::STATUS_DISETUJUI)
        ->and($cuti->diajukan_via)->toBe('admin')
        ->and($cuti->alasan)->toBe('Keperluan keluarga');

    $manHour = app(ManHourService::class);
    $harian  = $manHour->harian($this->teknisi, Carbon::parse('2026-10-15'), Carbon::parse('2026-10-15'));
    $ringkas = $manHour->ringkasan($harian);

    expect($harian[0]['target_menit'])->toBe(0)
        ->and($harian[0]['cuti'])->toBeTrue()
        ->and($ringkas['hari_cuti'])->toBe(1);
});

test('pengajuan cuti yang beririsan ditolak', function () {
    $payload = [
        'tanggal_mulai'   => '2026-10-15',
        'tanggal_selesai' => '2026-10-15',
        'jenis'           => 'tahunan',
    ];

    $this->actingAs($this->adminReguler)
        ->post(route('cuti.kelola.store', $this->teknisi), $payload);

    $this->actingAs($this->adminReguler)
        ->post(route('cuti.kelola.store', $this->teknisi), $payload)
        ->assertSessionHasErrors('tanggal_mulai');

    expect(CutiTeknisi::count())->toBe(1);
});

test('admin reguler dapat mengedit dan menghapus cuti', function () {
    $cuti = CutiTeknisi::create([
        'technician_id'   => $this->teknisi->id,
        'tanggal_mulai'   => '2026-10-15',
        'tanggal_selesai' => '2026-10-15',
        'jenis'           => 'tahunan',
        'status'          => CutiTeknisi::STATUS_DISETUJUI,
        'disetujui_at'    => now(),
        'diajukan_via'    => 'admin',
    ]);

    $this->actingAs($this->adminReguler)
        ->put(route('cuti.kelola.update', $cuti), [
            'tanggal_mulai'   => '2026-10-15',
            'tanggal_selesai' => '2026-10-16',
            'jenis'           => 'sakit',
            'alasan'          => 'Demam',
        ])
        ->assertRedirect(route('cuti.kelola.index', $this->teknisi));

    expect($cuti->refresh()->jenis)->toBe('sakit')
        ->and($cuti->tanggal_selesai->toDateString())->toBe('2026-10-16');

    $this->actingAs($this->adminReguler)
        ->delete(route('cuti.kelola.destroy', $cuti))
        ->assertRedirect(route('cuti.kelola.index', $this->teknisi));

    expect(CutiTeknisi::find($cuti->id))->toBeNull();
});

test('teknisi biasa tidak bisa membuka kelola cuti', function () {
    $akunTeknisi = User::create([
        'name'          => 'Teknisi Biasa',
        'username'      => 'teknisi-cuti-biasa',
        'email'         => 'teknisi-cuti-biasa@oleochemical.pro',
        'password'      => bcrypt('rahasia123'),
        'role'          => 'mekanik',
        'technician_id' => $this->teknisi->id,
    ]);

    $this->actingAs($akunTeknisi)
        ->get(route('cuti.kelola.index', $this->teknisi))
        ->assertForbidden();
});
