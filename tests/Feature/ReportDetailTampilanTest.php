<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tampilan halaman detail laporan (reports.show) sejak 06/10/2026:
 * 1. Kartu Temuan tampil SEBELUM kartu Root Cause, mengikuti urutan wizard
 *    (Step 5a temuan lalu Step 5b root cause).
 * 2. Analisa AI dan Timeline TIDAK lagi tampil langsung di halaman, tetapi
 *    dibuka lewat tombol popup yang isinya dipindah ke <body> dengan
 *    x-teleport (lihat reportDetail() pada resources/views/reports/show.blade.php).
 * 3. Kartu Aksi Admin tidak lagi memuat tombol pintas status.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Uji Tampilan',
        'nik'         => 'TAMPIL-1',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '67002',
    ]);

    $this->laporan = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->toDateString(),
        'report_code'      => 'RPT-TAMPIL-1',
        'work_description' => 'Uji tampilan detail laporan',
        'finding'          => 'Baut pengikat longgar',
        'root_cause'       => 'Getaran berlebih',
        'status'           => 'completed',
        'ai_analyzed'      => true,
        'ai_confidence'    => 80,
    ]);
});

test('kartu Temuan tampil sebelum kartu Root Cause', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSeeInOrder(['Temuan (Opsional)', 'Root Cause'], false);
});

test('Analisa AI dan Timeline dibuka lewat tombol popup, bukan tampil langsung di halaman', function () {
    $response = $this->actingAs($this->admin)->get('/reports/' . $this->laporan->id);

    $response->assertStatus(200)
        // Kartu pengganti kedua kartu lama: hanya berisi dua tombol.
        ->assertSee('Analisa AI dan Timeline', false)
        ->assertSee("bukaPopup('analisa')", false)
        ->assertSee("bukaPopup('timeline')", false)
        // Isi kedua kartu lama dipindah ke popup yang dilempar ke <body>.
        ->assertSee("x-show=\"popup === 'analisa'\"", false)
        ->assertSee("x-show=\"popup === 'timeline'\"", false);

    // Empat popup memakai x-teleport: Analisa AI, Timeline, galeri foto,
    // dan penolakan baris salinan (partial _penolakan-salinan, 14/10/2026).
    expect(substr_count($response->getContent(), 'x-teleport="body"'))->toBe(4);
});

test('popup Analisa AI memberi tahu bila laporan belum pernah dianalisa AI', function () {
    $laporanPolos = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->toDateString(),
        'report_code'      => 'RPT-TAMPIL-2',
        'work_description' => 'Laporan tanpa analisa AI',
        'status'           => 'completed',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports/' . $laporanPolos->id)
        ->assertStatus(200)
        ->assertSee('Belum ada analisa AI untuk laporan ini.', false);
});

test('kartu Aksi Admin tidak lagi memuat tombol pintas status laporan', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertDontSee('Tandai Selesai', false)
        ->assertDontSee('Tandai Perlu Review', false)
        // Status tetap bisa diubah lewat pilihan Status pada mode edit.
        ->assertSee('Status Pekerjaan', false)
        ->assertSee('Hapus Laporan', false);
});
