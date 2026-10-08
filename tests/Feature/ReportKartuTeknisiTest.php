<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianKinerjaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Kartu "Teknisi Pelaksana" pada kolom kanan halaman detail laporan
 * (08/10/2026): profil + angka kinerja + grafik jumlah laporan per bulan.
 *
 * Aturan yang dijaga di sini:
 *   1. Baris salinan multi alat (multi_of) TIDAK dihitung, sama seperti daftar
 *      laporan dan man hour.
 *   2. Baris salinan kolaborasi TETAP dihitung untuk teknisi kolaboratornya.
 *   3. Deret grafik selalu 12 bulan dan bulan terakhir adalah bulan berjalan.
 *   4. Kartu menampilkan chip teknisi (bisa diklik) bila laporan berkolaborasi.
 *   5. Tanpa data teknisi, kartu tidak dirender sama sekali.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'    => 'Budi Kinerja',
        'nik'     => 'KIN001',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->rekan = Technician::create([
        'name'    => 'Sari Kinerja',
        'nik'     => 'KIN002',
        'group'   => 'reguler',
        'section' => 'ei',
        'status'  => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'RPT-KINERJA-1',
        'work_description'      => 'Uji kartu kinerja teknisi',
        'work_duration_minutes' => 60,
        'report_type'           => 'equipment_repair',
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'completed',
    ]);
});

test('service kinerja menghitung laporan tanpa baris salinan multi alat', function () {
    Report::create([
        'technician_id'         => $this->rekan->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'RPT-KINERJA-2',
        'work_description'      => 'Salinan kolaborasi',
        'work_duration_minutes' => 60,
        'report_type'           => 'equipment_repair',
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'continue',
        'collaborator_of'       => $this->laporan->id,
    ]);

    // Salinan multi alat untuk teknisi yang sama: tidak boleh dihitung.
    Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'RPT-KINERJA-3',
        'work_description'      => 'Salinan multi alat',
        'work_duration_minutes' => 60,
        'report_type'           => 'equipment_repair',
        'status'                => 'completed',
        'multi_of'              => $this->laporan->id,
    ]);

    $hasil = app(TechnicianKinerjaService::class)->ringkasUntuk([$this->teknisi->id, $this->rekan->id]);

    expect($hasil)->toHaveCount(2);

    $budi = $hasil[$this->teknisi->id];

    expect($budi['nama'])->toBe('Budi Kinerja')
        ->and($budi['inisial'])->toBe('BK')
        ->and($budi['bagian'])->toBe('Mekanik')
        ->and($budi['grup'])->toBe('Reguler')
        ->and($budi['status'])->toBe('Aktif')
        ->and($budi['total'])->toBe(1)
        ->and($budi['selesai'])->toBe(1)
        ->and($budi['belum_selesai'])->toBe(0)
        ->and($budi['jumlah_bulanan'])->toHaveCount(TechnicianKinerjaService::JUMLAH_BULAN)
        ->and($budi['label_bulan'])->toHaveCount(TechnicianKinerjaService::JUMLAH_BULAN)
        ->and($budi['bulan_ini'])->toBe(1)
        ->and(collect($budi['komposisi'])->firstWhere('label', 'CM')['jumlah'])->toBe(1);

    // Kolaborator: baris kolaborasinya dihitung walau induknya milik teknisi lain.
    $sari = $hasil[$this->rekan->id];

    expect($sari['total'])->toBe(1)
        ->and($sari['belum_selesai'])->toBe(1);
});

test('deret bulanan memakai bulan berjalan sebagai slot terakhir', function () {
    Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        'report_code'           => 'RPT-KINERJA-4',
        'work_description'      => 'Laporan bulan lalu',
        'work_duration_minutes' => 60,
        'report_type'           => 'equipment_repair',
        'status'                => 'completed',
    ]);

    $hasil = app(TechnicianKinerjaService::class)->ringkasUntuk([$this->teknisi->id]);
    $deret = $hasil[$this->teknisi->id]['jumlah_bulanan'];

    expect($deret[TechnicianKinerjaService::JUMLAH_BULAN - 1])->toBe(1)
        ->and($deret[TechnicianKinerjaService::JUMLAH_BULAN - 2])->toBe(1);
});

test('permintaan tanpa id teknisi tidak menghasilkan data', function () {
    expect(app(TechnicianKinerjaService::class)->ringkasUntuk([]))->toBe([]);
});

test('kartu teknisi tampil di halaman detail dengan angka dan grafik', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('Teknisi Pelaksana', false)
        ->assertSee('Budi Kinerja', false)
        ->assertSee('KIN001', false)
        ->assertSee('Mekanik', false)
        ->assertSee('Reguler', false)
        ->assertSee('kartuKinerja(', false)
        ->assertSee('Jumlah laporan per bulan', false)
        ->assertSee('Komposisi jenis pekerjaan', false)
        ->assertSee('Lihat detail teknisi', false)
        ->assertSee('12 bulan terakhir, tanpa baris salinan multi alat.', false);
});

test('chip teknisi dapat diklik saat laporan berkolaborasi', function () {
    $salinan = Report::create([
        'technician_id'         => $this->rekan->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'RPT-KINERJA-5',
        'work_description'      => 'Salinan kolaborasi untuk chip',
        'work_duration_minutes' => 60,
        'report_type'           => 'equipment_repair',
        'status'                => 'completed',
        'collaborator_of'       => $this->laporan->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('Sari Kinerja', false)
        ->assertSee('@click="pilih(orang.id)"', false)
        ->assertSee('Kolaborator', false)
        ->assertSee('Pelaksana', false);

    // Dari baris salinan pun kartu menampilkan kedua teknisi pekerjaan itu
    // (pengelolaannya memang mengikuti laporan induk).
    $this->actingAs($this->admin)
        ->get('/reports/' . $salinan->id)
        ->assertStatus(200)
        ->assertSee('Budi Kinerja', false)
        ->assertSee('Sari Kinerja', false)
        ->assertSee('kartuKinerja(', false);
});
