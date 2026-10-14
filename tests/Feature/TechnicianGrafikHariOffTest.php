<?php

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
 * Grafik jam kerja pada hari libur/OFF (perbaikan 14/10/2026).
 *
 * Bug yang dijaga berkas ini: laporan TANPA jam mulai/selesai (durasi diketik
 * manual di form web) pada hari libur/OFF dulu dihitung sebagai jam kerja
 * NORMAL, sehingga grafik man hour halaman detail teknisi menampilkan batang
 * hijau "Jam kerja" pada hari Minggu, padahal aturannya seluruh jam pada hari
 * libur/OFF adalah LEMBUR (batang oranye).
 *
 * Tanggal dipatok supaya hasilnya tidak bergantung hari saat suite dijalankan:
 * 2026-09-27 Minggu (libur reguler) dan 2026-09-29 Selasa (hari kerja).
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->reguler = Technician::create([
        'name'   => 'Teknisi Grafik Hari Off',
        'nik'    => 'GMF001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);
});

/**
 * Buat laporan uji untuk grafik hari libur.
 *
 * @param  Technician  $teknisi  Teknisi pemilik laporan
 * @param  string      $tanggal  Tanggal laporan (Y-m-d)
 * @param  int         $durasi   Durasi man hour tersimpan (menit)
 * @param  string|null $mulai    Jam mulai (null = tanpa jam)
 * @param  string|null $selesai  Jam selesai (null = tanpa jam)
 * @return Report                Laporan yang dibuat
 */
function laporanGrafikOff(Technician $teknisi, string $tanggal, int $durasi, ?string $mulai = null, ?string $selesai = null): Report
{
    static $urut = 0;

    $urut++;

    return Report::create([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => $tanggal,
        'report_code'           => 'GMF-' . str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
        'work_description'      => 'Uji grafik hari libur',
        'work_duration_minutes' => $durasi,
        'start_time'            => $mulai,
        'end_time'              => $selesai,
        'status'                => 'completed',
    ]);
}

test('laporan tanpa jam pada hari libur dihitung lembur, bukan jam kerja normal', function () {
    laporanGrafikOff($this->reguler, '2026-09-27', 480);

    $ringkas = app(JamKerjaService::class)
        ->ringkasJamKerja(null, 'reguler', Carbon::parse('2026-09-27'), null, null);

    // Rentang jam tidak ada (total null) tetapi hari itu tetap dikenali libur.
    expect($ringkas['aturan'])->toBeFalse()
        ->and($ringkas['total'])->toBeNull()
        ->and($ringkas['libur'])->toBeTrue();

    $harian = app(ManHourService::class)->harian(
        $this->reguler,
        Carbon::parse('2026-09-27'),
        Carbon::parse('2026-09-27')
    );

    expect($harian[0]['menit'])->toBe(0)
        ->and($harian[0]['lembur'])->toBe(480)
        ->and($harian[0]['status'])->toBe('lebih')
        ->and(implode(' ', $harian[0]['catatan']))->toContain('hari OFF');
});

test('laporan tanpa jam pada hari kerja tetap memakai durasi tersimpan', function () {
    laporanGrafikOff($this->reguler, '2026-09-29', 240);

    $harian = app(ManHourService::class)->harian(
        $this->reguler,
        Carbon::parse('2026-09-29'),
        Carbon::parse('2026-09-29')
    );

    expect($harian[0]['menit'])->toBe(240)
        ->and($harian[0]['lembur'])->toBe(0);
});

test('pekerja shift pada hari OFF juga menghitung laporan tanpa jam sebagai lembur', function () {
    $shift = Technician::create([
        'name'   => 'Teknisi Shift Grafik Off',
        'nik'    => 'GMF002',
        'group'  => 'grub_a',
        'status' => 'active',
    ]);

    TechnicianShift::create([
        'technician_id' => $shift->id,
        'nik'           => 'GMF002',
        'nama'          => $shift->name,
        'group_code'    => 'A',
        'shift_date'    => '2026-09-27',
        'shift_code'    => 'x',
    ]);

    laporanGrafikOff($shift, '2026-09-27', 300);

    $harian = app(ManHourService::class)->harian(
        $shift->fresh(),
        Carbon::parse('2026-09-27'),
        Carbon::parse('2026-09-27')
    );

    expect($harian[0]['menit'])->toBe(0)
        ->and($harian[0]['lembur'])->toBe(300);
});

test('popup cek overtime memuat laporan hari libur yang jamnya kosong', function () {
    laporanGrafikOff($this->reguler, '2026-09-27', 180);

    $rincian = app(ManHourService::class)->lemburPerTanggal(
        $this->reguler,
        Carbon::parse('2026-09-27'),
        Carbon::parse('2026-09-27')
    );

    expect($rincian)->toHaveKey('2026-09-27')
        ->and($rincian['2026-09-27']['menit'])->toBe(180)
        ->and($rincian['2026-09-27']['laporan'][0]['menit'])->toBe(0)
        ->and($rincian['2026-09-27']['laporan'][0]['lembur'])->toBe(180);
});

test('data grafik halaman detail teknisi menandai hari libur dan lemburnya', function () {
    // Rentang man hour halaman teknisi = bulan berjalan (belum ada jadwal shift
    // pada database uji), jadi laporan ditaruh pada hari Minggu pertama bulan ini.
    $minggu = Carbon::now()->startOfMonth();

    while ($minggu->dayOfWeek !== Carbon::SUNDAY) {
        $minggu->addDay();
    }

    laporanGrafikOff($this->reguler, $minggu->toDateString(), 480);

    $this->actingAs($this->admin)
        ->get('/technicians/' . $this->reguler->id)
        ->assertStatus(200)
        // Data yang dibaca Chart.js: hari libur ditandai, seluruh jamnya lembur.
        ->assertSee('grafikManHour', false)
        ->assertSee('"libur":true', false)
        ->assertSee('"lembur":480', false)
        ->assertSee('"hari":"Min"', false);
});

test('partial grafik memakai target sebagai garis dan menandai hari libur', function () {
    laporanGrafikOff($this->reguler, '2026-09-27', 480);

    $this->actingAs($this->admin)
        ->get('/technicians/' . $this->reguler->id)
        ->assertStatus(200)
        // Target digambar sebagai garis supaya tidak ada dua kelompok batang.
        ->assertSee("type: 'line'", false)
        ->assertSee("stepped: 'middle'", false)
        // Batang tetap bertumpuk: jam kerja + lembur.
        ->assertSee("stack: 'realisasi'", false)
        ->assertSee('maintainAspectRatio: false', false)
        // Hari libur yang ada pekerjaannya ditandai merah pada sumbu X.
        ->assertSee("'#E11D48'", false)
        // Bentuk lama (target sebagai batang abu-abu + rumus satuan lama) tidak
        // boleh kembali: itulah yang membuat grafik hari OFF menyesatkan.
        ->assertDontSee("backgroundColor: '#CBD5E1'", false)
        ->assertDontSee('Math.round(baris.menit / 6) / 10', false);
});
