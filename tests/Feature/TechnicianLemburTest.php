<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Shift\ManHourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * LEMBUR (overtime) pada profil teknisi (13/10/2026).
 *
 * Aturan yang diputuskan user:
 *   - lembur = seluruh menit di luar jendela kerja bruto (sebelum jam masuk,
 *     sesudah jam pulang, JAM ISTIRAHAT) plus seluruh jam pada hari libur/OFF,
 *   - lembur DIKELUARKAN dari man hour normal (angkanya turun) dan ditampilkan
 *     sebagai penanda + segmen batang oranye,
 *   - ada tombol "Cek Overtime" berisi rincian per tanggal beserta laporannya
 *     dalam popup.
 *
 * Tanggal laporan dibuat dinamis (Senin pertama bulan berjalan) supaya hasilnya
 * tidak bergantung hari saat suite dijalankan, tetapi tetap berada di dalam
 * periode man hour halaman profil (bulan berjalan).
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Lembur',
        'nik'    => 'OVR001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    // Senin pertama bulan berjalan (jendela kerja reguler 08:00-16:00).
    $this->tanggal = Carbon::now()->startOfMonth();

    while ($this->tanggal->dayOfWeek !== Carbon::MONDAY) {
        $this->tanggal->addDay();
    }
});

/**
 * Buat laporan uji untuk perhitungan lembur.
 *
 * @param  Technician  $teknisi  Teknisi pemilik laporan
 * @param  string      $tanggal  Tanggal laporan (Y-m-d)
 * @param  string|null $mulai    Jam mulai (null = tanpa jam)
 * @param  string|null $selesai  Jam selesai (null = tanpa jam)
 * @param  int|null    $durasi   Durasi man hour normal yang tersimpan
 * @return Report                Laporan yang dibuat
 */
function laporanLembur(Technician $teknisi, string $tanggal, ?string $mulai, ?string $selesai, ?int $durasi): Report
{
    static $urut = 0;

    $urut++;

    return Report::create([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => $tanggal,
        'report_code'           => 'OVR-' . str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
        'work_description'      => 'Uji lembur teknisi',
        'work_duration_minutes' => $durasi,
        'start_time'            => $mulai,
        'end_time'              => $selesai,
        'status'                => 'completed',
    ]);
}

test('man hour memisahkan lembur dari jam kerja normal', function () {
    // 10:00-16:00 = 360 menit: 300 menit jam kerja + 60 menit lembur (istirahat).
    laporanLembur($this->teknisi, $this->tanggal->toDateString(), '10:00', '16:00', 300);
    // 17:00-19:00 di luar jam kerja: 120 menit lembur, jam kerja 0.
    laporanLembur($this->teknisi, $this->tanggal->toDateString(), '17:00', '19:00', 0);
    // 08:00-11:00 masih di dalam jam kerja: tidak ada lembur.
    laporanLembur($this->teknisi, $this->tanggal->toDateString(), '08:00', '11:00', 180);

    $manHour = app(ManHourService::class);
    $harian  = $manHour->harian($this->teknisi, $this->tanggal, $this->tanggal);

    expect($harian)->toHaveCount(1)
        ->and($harian[0]['menit'])->toBe(480)
        ->and($harian[0]['lembur'])->toBe(180);

    $ringkasan = $manHour->ringkasan($harian);

    expect($ringkasan['total_menit'])->toBe(480)
        ->and($ringkasan['total_lembur_menit'])->toBe(180);
});

test('lemburPerTanggal mengelompokkan lembur per tanggal dan laporan', function () {
    $tanggal = $this->tanggal->toDateString();

    laporanLembur($this->teknisi, $tanggal, '10:00', '16:00', 300);
    $kedua = laporanLembur($this->teknisi, $tanggal, '17:00', '19:00', 0);
    laporanLembur($this->teknisi, $tanggal, '08:00', '11:00', 180);

    $rincian = app(ManHourService::class)->lemburPerTanggal($this->teknisi, $this->tanggal, $this->tanggal);

    expect($rincian)->toHaveKey($tanggal)
        ->and($rincian[$tanggal]['menit'])->toBe(180)
        // Hanya laporan yang menyentuh lembur yang didaftarkan.
        ->and($rincian[$tanggal]['laporan'])->toHaveCount(2)
        ->and($rincian[$tanggal]['laporan'][0]['lembur'])->toBe(60)
        ->and($rincian[$tanggal]['laporan'][1]['lembur'])->toBe(120)
        ->and($rincian[$tanggal]['laporan'][1]['kode'])->toBe($kedua->report_code);
});

test('profil teknisi menampilkan penanda lembur dan tombol cek overtime', function () {
    $tanggal = $this->tanggal->toDateString();

    laporanLembur($this->teknisi, $tanggal, '10:00', '16:00', 300);
    laporanLembur($this->teknisi, $tanggal, '17:00', '19:00', 0);

    $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisi->id)
        ->assertStatus(200)
        // Penanda + tombol popup.
        ->assertSee('Cek Overtime', false)
        ->assertSee('bukaModalLembur()', false)
        ->assertSee('id="modal_lembur"', false)
        // 60 + 120 menit lembur = 3,0 jam.
        ->assertSee('Lembur: 3,0 j', false)
        // Segmen batang oranye + kolom lembur pada rincian harian.
        ->assertSee('bg-amber-500', false)
        // Wadah segmen oranye (teks penanda Blade tidak ikut ter-render, jadi
        // yang diperiksa adalah kelas markahnya).
        ->assertSee('mt-0.5 w-16 h-1.5 rounded-full bg-slate-100', false)
        ->assertSee('di luar jam kerja', false);
});

test('popup cek overtime memuat detail tanggal dan laporan penyebab lembur', function () {
    $tanggal = $this->tanggal->toDateString();

    laporanLembur($this->teknisi, $tanggal, '10:00', '16:00', 300);
    $lembur = laporanLembur($this->teknisi, $tanggal, '17:00', '19:00', 0);

    $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisi->id)
        ->assertStatus(200)
        // Judul popup + tanggal & hari dalam bahasa Indonesia.
        ->assertSee('Cek Overtime — ' . $this->teknisi->name, false)
        ->assertSee($this->tanggal->locale('id')->translatedFormat('d F Y'), false)
        ->assertSee($this->tanggal->locale('id')->translatedFormat('l'), false)
        // Laporan penyebab lembur terdaftar beserta tautan detailnya.
        ->assertSee($lembur->report_code, false)
        ->assertSee(route('reports.show', $lembur->id), false)
        ->assertSee('lembur 2,0 j', false);
});

test('laporan pada hari OFF reguler seluruhnya lembur', function () {
    $minggu = $this->tanggal->copy()->next(Carbon::SUNDAY);

    laporanLembur($this->teknisi, $minggu->toDateString(), '08:00', '16:00', 0);
    laporanLembur($this->teknisi, $minggu->toDateString(), '10:00', '12:00', 0);

    $harian = app(ManHourService::class)->harian($this->teknisi, $minggu, $minggu);

    expect($harian[0]['menit'])->toBe(0)
        ->and($harian[0]['lembur'])->toBe(480 + 120);
});
