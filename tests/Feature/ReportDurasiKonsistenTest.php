<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Shift\JamKerjaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Rentang jam kerja + durasi man hour pada halaman detail laporan (08/10/2026).
 *
 * Aturan yang dijaga di sini:
 *   1. Yang bisa diedit tetap JAM (mulai & selesai); durasi man hour dihitung
 *      server dari rentang jam dan hanya DITAMPILKAN (read-only).
 *   2. Form edit tidak pernah mengirim kolom durasi, jadi angka man hour tidak
 *      bisa ditimpa manual dari halaman ini.
 *   3. Label durasi di mode lihat dan mode edit memakai format yang sama
 *      (accessor Report::duration_label: "X Jam Y Menit").
 *   4. Skrip tampilan memakai aturan yang sama dengan server: jam selesai lebih
 *      kecil dari jam mulai = melewati tengah malam (+24 jam).
 *   5. Rentang jam ikut tampil di mode lihat supaya konsisten dengan mode edit.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Durasi',
        'nik'    => 'DUR001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'RPT-DURASI-1',
        'work_description'      => 'Uji jam kerja dan durasi',
        'work_duration_minutes' => 90,
        'start_time'            => '08:00',
        'end_time'              => '09:30',
        'report_type'           => 'equipment_repair',
        'status'                => 'completed',
    ]);
});

test('mode edit menampilkan jam kerja dan durasi read-only', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('Waktu Pengerjaan', false)
        ->assertSee('name="start_time"', false)
        ->assertSee('name="end_time"', false)
        ->assertSee('id="durasi_kerja"', false)
        ->assertSee('readonly', false)
        ->assertSee('Dihitung otomatis dari jam di samping.', false)
        ->assertSee('Kosongkan Jam', false);
});

test('form edit tidak mengirim kolom durasi man hour', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertDontSee('name="work_duration_minutes"', false);
});

test('label durasi sama di mode lihat dan mode edit', function () {
    $balasan = $this->actingAs($this->admin)->get('/reports/' . $this->laporan->id);

    $balasan->assertStatus(200)
        // Format accessor duration_label, dipakai header (mode lihat) dan
        // isian durasi read-only (mode edit).
        ->assertSee('1 Jam 30 Menit', false)
        // Rentang jam ikut tampil di mode lihat.
        ->assertSee('08:00 - 09:30', false);
});

test('skrip tampilan memakai aturan lintas tengah malam yang sama dengan server', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('function labelDurasi(menit)', false)
        ->assertSee('if (lewatTengahMalam) {', false)
        ->assertSee('total += 24 * 60;', false)
        // Jam sama bukan rentang: skrip tampilan memakai aturan server
        // (Report::durasiDariJam()) sehingga durasi tidak dihitung dan admin
        // diberi peringatan, bukan ditampilkan 0 menit.
        ->assertSee('if (menitMulai === menitSelesai) {', false)
        ->assertSee('id="peringatan_jam_sama"', false)
        ->assertSee("bagian.push(jam + ' Jam')", false)
        ->assertSee("bagian.push(sisa + ' Menit')", false);
});

test('menyimpan jam saja menghitung ulang durasi melewati tengah malam', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description' => 'Uji durasi lintas tengah malam',
            'report_date'      => now()->toDateString(),
            'start_time'       => '22:00',
            'end_time'         => '06:00',
        ])
        ->assertRedirect();

    $laporan  = $this->laporan->fresh();
    $jamKerja = app(JamKerjaService::class);
    $ringkas  = $jamKerja->ringkasJamKerja(
        $jamKerja->jadwalHari($laporan->technician, $laporan->report_date),
        $laporan->technician?->group,
        $laporan->report_date,
        $laporan->start_time,
        $laporan->end_time
    );

    // 22:00 - 06:00 = 8 jam = 480 menit (melewati tengah malam), tetapi seluruh
    // rentang itu di LUAR jam kerja reguler sehingga menjadi LEMBUR: man hour
    // normal laporan 0 (lembur ditampilkan terpisah di profil teknisi).
    expect($ringkas['total'])->toBe(480)
        ->and($ringkas['lembur'])->toBe(480)
        ->and($laporan->work_duration_minutes)->toBe(0)
        ->and($laporan->start_time)->toBe('22:00')
        ->and($laporan->end_time)->toBe('06:00');
});
