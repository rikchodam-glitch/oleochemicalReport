<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Jam kerja (jam mulai & jam selesai) pada mode edit halaman DETAIL laporan.
 *
 * Aturan yang dijaga di sini:
 *   1. Mode edit halaman detail menampilkan kolom jam mulai & jam selesai
 *      (kolom start_time / end_time sudah ada di database tapi sebelumnya
 *      tidak bisa diisi admin).
 *   2. Durasi pengerjaan dihitung OTOMATIS dari rentang jam bila kolom durasi
 *      dibiarkan kosong.
 *   3. Jam selesai lebih kecil dari jam mulai = pekerjaan melewati tengah
 *      malam, durasinya ditambah 24 jam (mis. 22:00 - 06:00 = 480 menit).
 *   4. Durasi laporan TIDAK pernah ikut hilang/tertimpa saat jam dikosongkan
 *      (angka man hour lama tetap dipertahankan).
 *   5. Format jam tidak valid ditolak validasi dan data lama tidak berubah.
 *   6. Halaman detail mode edit HANYA meminta jam mulai & jam selesai: tidak
 *      ada isian durasi manual, kotak centang hitung otomatis, maupun kolom
 *      tersembunyi durasi. Angka man hour dihitung server dari rentang jam.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Jam Kerja',
        'nik'    => 'JAM001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'JAM-LAPORAN-01',
        'work_description'      => 'Pemeriksaan pompa uji jam kerja',
        'work_duration_minutes' => 120,
        'start_time'            => null,
        'end_time'              => null,
        'status'                => 'completed',
    ]);
});

test('halaman detail mode edit menampilkan kolom jam mulai dan jam selesai', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('name="start_time"', false)
        ->assertSee('name="end_time"', false)
        ->assertSee('Jam Mulai', false)
        ->assertSee('Jam Selesai', false);
});

test('update menyimpan jam mulai dan jam selesai', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pemeriksaan pompa uji jam kerja',
            'report_date'           => now()->toDateString(),
            'work_duration_minutes' => 90,
            'start_time'            => '08:00',
            'end_time'              => '09:30',
        ])
        ->assertRedirect();

    $tersimpan = $this->laporan->fresh();

    expect($tersimpan->start_time)->toBe('08:00')
        ->and($tersimpan->end_time)->toBe('09:30')
        // Durasi manual yang dikirim tidak ditimpa perhitungan jam.
        ->and($tersimpan->work_duration_minutes)->toBe(90)
        ->and($tersimpan->time_range)->toBe('08:00 - 09:30');
});

test('durasi dihitung otomatis dari jam bila kolom durasi dikosongkan', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pemeriksaan pompa uji jam kerja',
            'report_date'           => now()->toDateString(),
            'work_duration_minutes' => null,
            'start_time'            => '08:00',
            'end_time'              => '10:15',
        ])
        ->assertRedirect();

    expect($this->laporan->fresh()->work_duration_minutes)->toBe(135);
});

test('jam lintas tengah malam dihitung ditambah 24 jam', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pekerjaan shift malam melewati tengah malam',
            'report_date'           => now()->toDateString(),
            'work_duration_minutes' => null,
            'start_time'            => '22:00',
            'end_time'              => '06:00',
        ])
        ->assertRedirect();

    expect($this->laporan->fresh()->work_duration_minutes)->toBe(480);
});

test('durasi tersimpan tidak berubah bila jam dikosongkan', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pemeriksaan pompa uji jam kerja',
            'report_date'           => now()->toDateString(),
            // Durasi dan jam sama-sama kosong: nilai lama (120) dipertahankan.
            'work_duration_minutes' => null,
            'start_time'            => null,
            'end_time'              => null,
        ])
        ->assertRedirect();

    $tersimpan = $this->laporan->fresh();

    expect($tersimpan->work_duration_minutes)->toBe(120)
        ->and($tersimpan->start_time)->toBeNull()
        ->and($tersimpan->end_time)->toBeNull();
});

test('durasi 0 dihormati sebagai nol, bukan dianggap kosong', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pemeriksaan singkat tanpa durasi terukur',
            'report_date'           => now()->toDateString(),
            'work_duration_minutes' => 0,
        ])
        ->assertRedirect();

    expect($this->laporan->fresh()->work_duration_minutes)->toBe(0);
});

test('format jam tidak valid ditolak validasi dan data lama tidak berubah', function () {
    $this->actingAs($this->admin)
        ->from('/reports/' . $this->laporan->id)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pemeriksaan pompa uji jam kerja',
            'report_date'           => now()->toDateString(),
            'work_duration_minutes' => 60,
            'start_time'            => '25:00',
            'end_time'              => '09:00',
        ])
        ->assertSessionHasErrors('start_time');

    expect($this->laporan->fresh()->start_time)->toBeNull();
});

test('pembantu durasiDariJam dan lintasTengahMalam mengikuti aturan yang sama', function () {
    // Rentang biasa.
    expect(Report::durasiDariJam('08:00', '09:30'))->toBe(90)
        // Lintas tengah malam.
        ->and(Report::durasiDariJam('22:00', '06:00'))->toBe(480)
        // Jam sama = 0 menit.
        ->and(Report::durasiDariJam('08:00', '08:00'))->toBe(0)
        // Tidak lengkap / format ngawur = tidak dihitung.
        ->and(Report::durasiDariJam('08:00', null))->toBeNull()
        ->and(Report::durasiDariJam(null, '08:00'))->toBeNull()
        ->and(Report::durasiDariJam('24:00', '08:00'))->toBeNull()
        ->and(Report::durasiDariJam('08:60', '09:00'))->toBeNull();

    expect(Report::lintasTengahMalam('22:00', '06:00'))->toBeTrue()
        ->and(Report::lintasTengahMalam('08:00', '09:00'))->toBeFalse()
        ->and(Report::lintasTengahMalam(null, '09:00'))->toBeFalse();
});

test('halaman detail mode edit hanya meminta jam mulai dan jam selesai, tanpa isian durasi', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        // Jam kerja tetap diminta.
        ->assertSee('name="start_time"', false)
        ->assertSee('name="end_time"', false)
        // Tidak ada lagi isian durasi manual, kotak centang hitung otomatis,
        // maupun kolom tersembunyi durasi: form tidak mengirim kolom durasi,
        // dan angka man hour dihitung server dari rentang jam.
        ->assertDontSee('work_duration_minutes', false)
        ->assertDontSee('Durasi Pengerjaan (menit)', false)
        ->assertDontSee('durasi_otomatis', false)
        ->assertDontSee('durasi_teks', false);
});

test('update menghitung durasi dari jam walau form tidak mengirim kolom durasi', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description' => 'Pekerjaan malam tanpa kolom durasi',
            'report_date'      => now()->toDateString(),
            'start_time'       => '22:00',
            'end_time'         => '06:00',
        ])
        ->assertRedirect();

    // Rentang 22:00 - 06:00 melewati tengah malam: 8 jam = 480 menit.
    expect($this->laporan->fresh()->work_duration_minutes)->toBe(480);
});

test('jam dikosongkan tanpa kolom durasi: durasi lama dipertahankan', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description' => 'Pemeriksaan pompa uji jam kerja',
            'report_date'      => now()->toDateString(),
            'start_time'       => null,
            'end_time'         => null,
        ])
        ->assertRedirect();

    expect($this->laporan->fresh()->work_duration_minutes)->toBe(120);
});
