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
 * Aturan jam kerja 13/10/2026: jam ISTIRAHAT bukan lembur (menitnya hanya
 * terpotong dari durasi), sedangkan jam di LUAR jendela kerja tetap lembur dan
 * aturan itu berlaku untuk pekerja shift juga.
 *
 * Berkas ini sekaligus menjaga BUG DOBEL yang dilaporkan pemilik sistem:
 * laporan lama masih menyimpan durasi PENUH di kolom work_duration_minutes,
 * sehingga bila kolom itu dipakai bersama lembur yang dihitung dari jam, menit
 * yang sama terhitung dua kali (6 jam pada hari Minggu tampak 12 jam). Semua
 * angka man hour kini dihitung dari JAM laporan; kolom durasi hanya cadangan
 * untuk laporan yang tidak punya jam mulai/selesai.
 *
 * Tanggal uji dipatok supaya hasilnya tidak bergantung hari saat suite jalan:
 * 2026-09-27 Minggu (libur reguler), 2026-09-29 Selasa, 2026-10-02 Jumat.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->reguler = Technician::create([
        'name'   => 'Teknisi Aturan Lembur Baru',
        'nik'    => 'LB001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->shift = Technician::create([
        'name'   => 'Teknisi Shift Aturan Lembur Baru',
        'nik'    => 'LB002',
        'group'  => 'grub_a',
        'status' => 'active',
    ]);
});

/**
 * Buat laporan uji berjam dengan nomor urut sendiri.
 *
 * @param  Technician  $teknisi  Teknisi pemilik laporan
 * @param  string      $tanggal  Tanggal laporan (Y-m-d)
 * @param  string|null $mulai    Jam mulai
 * @param  string|null $selesai  Jam selesai
 * @param  int|null    $durasi   Durasi tersimpan sebelum perbaikan
 * @return Report                Laporan yang dibuat
 */
function laporanLemburBaru(Technician $teknisi, string $tanggal, ?string $mulai, ?string $selesai, ?int $durasi): Report
{
    static $urut = 0;

    $urut++;

    return Report::create([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => $tanggal,
        'report_code'           => 'LBB-' . str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
        'work_description'      => 'Uji aturan lembur baru',
        'work_duration_minutes' => $durasi,
        'start_time'            => $mulai,
        'end_time'              => $selesai,
        'status'                => 'completed',
    ]);
}

test('jam istirahat hanya terpotong dari durasi, tidak dihitung lembur', function () {
    $jam = app(JamKerjaService::class);

    // Selasa reguler 10:00-16:00: 360 menit rentang, istirahat 60 menit
    // (12:00-13:00) -> jam kerja 300 menit dan LEMBUR 0.
    $selasa = $jam->ringkasJamKerja(null, 'reguler', Carbon::parse('2026-09-29'), '10:00', '16:00');

    expect($selasa['total'])->toBe(360)
        ->and($selasa['istirahat'])->toBe(60)
        ->and($selasa['normal'])->toBe(300)
        ->and($selasa['lembur'])->toBe(0);

    // Jumat reguler 09:00-12:00: istirahat 11:00-13:00 yang masuk rentang hanya
    // 60 menit -> jam kerja 120 menit, lembur 0.
    $jumat = $jam->ringkasJamKerja(null, 'reguler', Carbon::parse('2026-10-02'), '09:00', '12:00');

    expect($jumat['istirahat'])->toBe(60)
        ->and($jumat['normal'])->toBe(120)
        ->and($jumat['lembur'])->toBe(0);
});

test('jam sesudah jam kerja tetap lembur', function () {
    $ringkas = app(JamKerjaService::class)
        ->ringkasJamKerja(null, 'reguler', Carbon::parse('2026-09-29'), '17:00', '19:00');

    expect($ringkas['normal'])->toBe(0)
        ->and($ringkas['lembur'])->toBe(120);
});

test('pekerja shift dihitung penuh di jendela dan lembur di luarnya', function () {
    // Jadwal eksplisit Shift 1 (08:00-16:00) pada 2026-09-29.
    TechnicianShift::create([
        'technician_id' => $this->shift->id,
        'nik'           => 'LB002',
        'nama'          => $this->shift->name,
        'shift_date'    => '2026-09-29',
        'shift_code'    => '1',
    ]);

    $jam    = app(JamKerjaService::class);
    $shift  = $this->shift->fresh();
    $selasa = Carbon::parse('2026-09-29');

    $dalam = $jam->ringkasJamKerja($jam->jadwalHari($shift, $selasa), 'grub_a', $selasa, '08:00', '16:00');

    expect($dalam['normal'])->toBe(480)
        ->and($dalam['lembur'])->toBe(0)
        ->and($dalam['istirahat'])->toBe(0);

    $luar = $jam->ringkasJamKerja($jam->jadwalHari($shift, $selasa), 'grub_a', $selasa, '16:00', '18:00');

    expect($luar['normal'])->toBe(0)
        ->and($luar['lembur'])->toBe(120);
});


test('laporan lama pada hari libur tidak dihitung dua kali', function () {
    // Minggu 2026-09-27 (libur reguler). Durasi tersimpan masih 360 menit
    // (angka lama yang belum pernah dipotong) dengan jam 08:00-14:00.
    $laporan = laporanLemburBaru($this->reguler, '2026-09-27', '08:00', '14:00', 360);

    $manHour = app(ManHourService::class);
    $harian  = $manHour->harian($this->reguler, Carbon::parse('2026-09-27'), Carbon::parse('2026-09-27'));

    // Seluruh 6 jam menjadi LEMBUR (bukan 6 jam normal + 6 jam lembur).
    expect($harian[0]['menit'])->toBe(0)
        ->and($harian[0]['lembur'])->toBe(360);

    // Halaman detail menampilkan angka yang sama, tanpa dobel.
    $this->actingAs($this->admin)
        ->get('/reports/' . $laporan->id)
        ->assertStatus(200)
        ->assertSee('lembur 6 Jam (di luar jam kerja)', false)
        ->assertDontSee('value="6 Jam"', false);
});

test('laporan lama di dalam jam kerja memakai angka dari jamnya', function () {
    // Selasa 10:00-16:00 dengan durasi tersimpan 360 (angka lama): jam kerja
    // sebenarnya 300 menit setelah dipotong istirahat, lembur 0.
    laporanLemburBaru($this->reguler, '2026-09-29', '10:00', '16:00', 360);

    $manHour = app(ManHourService::class);
    $selasa  = Carbon::parse('2026-09-29');
    $harian  = $manHour->harian($this->reguler, $selasa, $selasa);

    expect($harian[0]['menit'])->toBe(300)
        ->and($harian[0]['lembur'])->toBe(0);

    // Kolom ringkas daftar teknisi memakai aturan yang sama.
    $menit = $manHour->menitPerTeknisi([$this->reguler->id], $selasa, $selasa);

    expect($menit[$this->reguler->id])->toBe(300);
});

test('halaman detail menampilkan durasi dari jam laporan', function () {
    $laporan = laporanLemburBaru($this->reguler, '2026-09-29', '10:00', '16:00', 360);

    $this->actingAs($this->admin)
        ->get('/reports/' . $laporan->id)
        ->assertStatus(200)
        // Isian durasi read-only memakai hasil hitungan jam: 300 menit = 5 Jam.
        ->assertSee('value="5 Jam"', false)
        ->assertSee('>5 Jam<', false)
        // Angka lama (6 Jam dari kolom durasi) tidak lagi ditampilkan.
        ->assertDontSee('value="6 Jam"', false)
        ->assertDontSee('>6 Jam<', false);
});

test('laporan tanpa jam tetap memakai durasi yang tersimpan', function () {
    // Laporan lama dari form web: durasi diketik manual, tanpa jam.
    laporanLemburBaru($this->reguler, '2026-09-29', null, null, 240);

    $manHour = app(ManHourService::class);
    $selasa  = Carbon::parse('2026-09-29');
    $harian  = $manHour->harian($this->reguler, $selasa, $selasa);

    expect($harian[0]['menit'])->toBe(240)
        ->and($harian[0]['lembur'])->toBe(0);
});

