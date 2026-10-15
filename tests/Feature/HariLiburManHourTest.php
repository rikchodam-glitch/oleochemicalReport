<?php

use App\Models\HariLibur;
use App\Models\Report;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\User;
use App\Services\Shift\JamKerjaService;
use App\Services\Shift\ManHourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Kalender teknisi + hari libur nasional (diputuskan user 15/10/2026):
 *   - Hari libur berlaku untuk SEMUA grup: target menit 0 dan seluruh jam yang
 *     dilaporkan pada tanggal itu dihitung lembur.
 *   - Jadwal shift tetap tampil (pekerja shift tetap wajib masuk sesuai rotasi).
 *   - Administrator mengelola hari libur dari kalender teknisi.
 */

/**
 * Bentuk teknisi uji.
 *
 * @param  string $nik NIK unik
 * @param  string $grup Group teknisi
 * @return Technician
 */
function hlTeknisi(string $nik, string $grup = 'reguler'): Technician
{
    return Technician::create([
        'name'   => 'Teknisi Libur Uji',
        'nik'    => $nik,
        'group'  => $grup,
        'status' => 'active',
    ]);
}

/**
 * Bentuk laporan uji pada satu tanggal dengan rentang jam.
 *
 * @param  Technician $teknisi Pemilik laporan
 * @param  string     $kode    Kode laporan unik
 * @param  string     $tanggal Tanggal laporan Y-m-d
 * @param  string     $mulai   Jam mulai HH:MM
 * @param  string     $selesai Jam selesai HH:MM
 * @return Report
 */
function hlLaporan(Technician $teknisi, string $kode, string $tanggal, string $mulai = '08:00', string $selesai = '16:00'): Report
{
    return Report::create([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => $tanggal,
        'report_code'           => $kode,
        'work_description'      => 'Pekerjaan uji libur',
        'work_duration_minutes' => 480,
        'start_time'            => $mulai,
        'end_time'              => $selesai,
        'report_type'           => 'general',
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'completed',
    ]);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // Hari libur uji: Senin, dan hari kontrol Selasa (bukan libur).
    $this->senin   = now()->startOfDay()->next('Monday');
    $this->selasa  = $this->senin->copy()->addDay();
    $this->libur   = $this->senin->toDateString();
    $this->kontrol = $this->selasa->toDateString();

    HariLibur::create(['tanggal' => $this->libur, 'nama' => 'Uji Libur']);
});

test('hari libur membuat jendela kerja hilang dan seluruh jam lembur', function () {
    $jam = app(JamKerjaService::class);

    expect($jam->apakahHariLibur(Carbon::parse($this->libur)))->toBeTrue()
        ->and($jam->namaHariLibur(Carbon::parse($this->libur)))->toBe('Uji Libur')
        ->and($jam->apakahHariLibur(Carbon::parse($this->kontrol)))->toBeFalse()
        ->and($jam->targetMenit('reguler', Carbon::parse($this->libur)))->toBe(0);

    $ringkas = $jam->ringkasJamKerja(null, 'reguler', Carbon::parse($this->libur), '08:00', '16:00');

    expect($ringkas['libur'])->toBeTrue()
        ->and($ringkas['normal'])->toBe(0)
        ->and($ringkas['lembur'])->toBe(480);
});

test('pekerja shift pada hari libur tetap punya jadwal tetapi target 0 dan lembur', function () {
    $shift = new TechnicianShift(['shift_date' => $this->libur, 'shift_code' => '1']);

    expect($shift->isOff())->toBeFalse()
        ->and($shift->targetMenit('grub_a'))->toBe(0)
        ->and($shift->jendela('grub_a'))->toBeNull();
});

test('man hour harian pada hari libur: target 0, normal 0, lembur penuh', function () {
    $teknisi = hlTeknisi('HL001');
    hlLaporan($teknisi, 'RPT-20260817-9001', $this->libur);

    $harian = app(ManHourService::class)->harian($teknisi, Carbon::parse($this->libur), Carbon::parse($this->libur));
    $hari   = $harian[0];

    expect($hari['target_menit'])->toBe(0)
        ->and($hari['menit'])->toBe(0)
        ->and($hari['lembur'])->toBe(480)
        ->and($hari['label_shift'])->toBe('Libur Nasional')
        ->and(implode(' ', $hari['catatan']))->toContain('Libur nasional');
});

test('hari kerja biasa tidak terpengaruh hari libur di tanggal lain', function () {
    $teknisi = hlTeknisi('HL002');
    hlLaporan($teknisi, 'RPT-20260818-9002', $this->kontrol);

    $harian = app(ManHourService::class)->harian($teknisi, Carbon::parse($this->kontrol), Carbon::parse($this->kontrol));
    $hari   = $harian[0];

    expect($hari['target_menit'])->toBeGreaterThan(0)
        ->and($hari['menit'])->toBeGreaterThan(0)
        ->and($hari['lembur'])->toBe(0)
        ->and($hari['label_shift'])->not->toBe('Libur Nasional');
});

test('targetPerTeknisi tidak menambah target pada hari libur', function () {
    $teknisi = hlTeknisi('HL003');

    $langsungLibur = app(ManHourService::class)->targetPerTeknisi(
        [$teknisi->id],
        Carbon::parse($this->libur),
        Carbon::parse($this->libur)
    );

    expect($langsungLibur[$teknisi->id] ?? 0)->toBe(0);
});

test('administrator bisa menambah, memperbarui, dan menghapus hari libur', function () {
    // Tanggal lain agar tidak menimpa hari libur pada beforeEach.
    $tanggal = $this->senin->copy()->addWeek()->toDateString();

    $this->actingAs($this->admin)
        ->post('/technicians/hari-libur', ['tanggal' => $tanggal, 'nama' => 'Hari Uji'])
        ->assertRedirect();

    expect(HariLibur::whereDate('tanggal', $tanggal)->where('nama', 'Hari Uji')->exists())->toBeTrue();

    // Tanggal sama -> nama diperbarui, bukan baris ganda.
    $this->actingAs($this->admin)
        ->post('/technicians/hari-libur', ['tanggal' => $tanggal, 'nama' => 'Hari Uji Diperbarui'])
        ->assertRedirect();

    expect(HariLibur::whereDate('tanggal', $tanggal)->count())->toBe(1)
        ->and(HariLibur::whereDate('tanggal', $tanggal)->first()->nama)->toBe('Hari Uji Diperbarui');

    $hari = HariLibur::whereDate('tanggal', $tanggal)->firstOrFail();

    $this->actingAs($this->admin)
        ->delete('/technicians/hari-libur/' . $hari->id)
        ->assertRedirect();

    expect(HariLibur::whereDate('tanggal', $tanggal)->exists())->toBeFalse();
});

test('akun hanya baca tidak boleh mengubah hari libur', function () {
    $pembaca = User::create([
        'name'     => 'Akun Baca',
        'username' => 'HLBACA',
        'role'     => 'mekanik',
        'password' => 'rahasia123',
    ]);

    $this->actingAs($pembaca)
        ->post('/technicians/hari-libur', ['tanggal' => $this->kontrol, 'nama' => 'Tidak Boleh'])
        ->assertStatus(403);
});

test('kalender teknisi menandai hari libur dan menyediakan pengelolaannya', function () {
    $bulan = $this->senin->format('Y-m');

    $this->actingAs($this->admin)
        ->get('/technicians?jadwal_bulan=' . $bulan)
        ->assertStatus(200)
        ->assertSee('Kalender Teknisi')
        ->assertSee('Hari Libur Nasional')
        ->assertSee('Uji Libur');
});
