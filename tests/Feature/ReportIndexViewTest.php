<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'SITETEST'], ['name' => 'Site Uji']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPTS'],
        ['name' => 'Departemen Uji']
    );

    // Teknisi reguler (pemilik pekerjaan) dan teknisi shift Grub D (kolaborator).
    $this->teknisiReguler = Technician::create([
        'name'   => 'Teknisi Reguler Uji',
        'nik'    => 'REG001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->teknisiGrubD = Technician::create([
        'name'   => 'Teknisi Grub D Uji',
        'nik'    => 'GRUPD002',
        'group'  => 'grub_d',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();

    // Pekerjaan reguler (induk) + salinan kolaborasi untuk teknisi Grub D.
    $this->laporanInduk = Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'creator_id'       => $this->teknisiReguler->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'REG-INDUK-01',
        'work_description' => 'Ganti bearing pompa reguler uji',
        'root_cause'       => 'Bearing aus',
        'jenis_pekerjaan'  => 'CM',
        'status'           => 'submitted',
    ]);

    $this->laporanAnak = Report::create([
        'technician_id'    => $this->teknisiGrubD->id,
        'creator_id'       => $this->teknisiReguler->id,
        'collaborator_of'  => $this->laporanInduk->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'SALINAN-KOLAB-01',
        'work_description' => 'Ganti bearing pompa reguler uji',
        'jenis_pekerjaan'  => 'CM',
        'status'           => 'submitted',
    ]);

    // Pekerjaan milik teknisi Grub D (bukan kolaborasi).
    // Catatan: pada SQLite (test) kolom jenis_pekerjaan masih memakai CHECK
    // constraint nilai lama (CM/dCM/PM), jadi fixture memakai PM.
    $this->laporanGrubD = Report::create([
        'technician_id'    => $this->teknisiGrubD->id,
        'creator_id'       => $this->teknisiGrubD->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'GRUPD-KERJA-01',
        'work_description' => 'Pengecekan motor grub D uji',
        'jenis_pekerjaan'  => 'PM',
        'status'           => 'submitted',
    ]);

    // Pekerjaan reguler TANPA kolaborator shift: tetap tampil di tab Reguler.
    // Dipakai untuk menguji tabel Reguler setelah laporan berkolaborasi shift
    // dipindahkan ke tab Shift.
    $this->laporanRegulerBiasa = Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'creator_id'       => $this->teknisiReguler->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'REG-BIASA-01',
        'work_description' => 'Pekerjaan reguler tanpa kolaborasi shift',
        'jenis_pekerjaan'  => 'PM',
        'status'           => 'submitted',
    ]);
});

// ============================================================
// TAMPILAN DASAR: FOKUS PEKERJAAN + TANGGAL + TAB REGULER/SHIFT
// ============================================================

test('halaman laporan menampilkan pekerjaan di depan dan teknisi di sampingnya', function () {
    $respons = $this->actingAs($this->admin)->get('/reports?tanggal=' . $this->tanggal);

    $respons->assertStatus(200)
        ->assertSee('Pekerjaan', false)
        ->assertSee('Teknisi', false)
        ->assertSee('Pekerjaan reguler tanpa kolaborasi shift', false)
        ->assertSee('Teknisi Reguler Uji', false)
        // Navigasi tanggal
        ->assertSee('Buka', false)
        ->assertSee('Hari Ini', false)
        // Tab tampilan
        ->assertSee('Reguler', false)
        ->assertSee('Shift', false);
});

test('tampilan reguler hanya memuat pekerjaan teknisi reguler', function () {
    $respons = $this->actingAs($this->admin)->get('/reports?tanggal=' . $this->tanggal . '&tampilan=reguler');

    $respons->assertStatus(200)
        ->assertSee('Pekerjaan reguler tanpa kolaborasi shift', false)
        // Laporan reguler yang berkolaborasi dengan Grub D pindah ke tab Shift.
        ->assertDontSee('Ganti bearing pompa reguler uji', false)
        ->assertDontSee('Pengecekan motor grub D uji', false);
});

test('tampilan shift bisa dipersempit ke satu grub', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&tampilan=shift&grub=grub_d');

    $respons->assertStatus(200)
        ->assertSee('Pengecekan motor grub D uji', false)
        ->assertSee('Grub D', false)
        // Laporan reguler yang berkolaborasi dengan Grub D ikut tampil di Shift.
        ->assertSee('Ganti bearing pompa reguler uji', false)
        ->assertSee('Kolaborasi Grub D', false);
});

test('tampilan reguler memuat reguler staff, tampilan shift tidak', function () {
    // Teknisi Reguler Staff (non-shift) dengan laporan sendiri.
    $staf = Technician::create([
        'name'   => 'Teknisi Staf Uji',
        'nik'    => 'STAF001',
        'group'  => 'reguler_staff',
        'status' => 'active',
    ]);

    Report::create([
        'technician_id'    => $staf->id,
        'creator_id'       => $staf->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'STAF-KERJA-01',
        'work_description' => 'Administrasi jadwal reguler staff uji',
        'jenis_pekerjaan'  => 'PM',
        'status'           => 'submitted',
    ]);

    // Reguler Staff BUKAN pekerja shift: laporannya harus muncul di tampilan
    // Reguler (bersama teknisi reguler).
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&tampilan=reguler')
        ->assertStatus(200)
        ->assertSee('Administrasi jadwal reguler staff uji', false)
        ->assertSee('Pekerjaan reguler tanpa kolaborasi shift', false)
        ->assertDontSee('Ganti bearing pompa reguler uji', false);

    // Dan tidak boleh muncul di tampilan Shift, juga tidak menjadi pilihan grub.
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&tampilan=shift')
        ->assertStatus(200)
        ->assertSee('Pengecekan motor grub D uji', false)
        // Laporan reguler dengan kolaborator Grub D ikut Shift.
        ->assertSee('Ganti bearing pompa reguler uji', false)
        ->assertDontSee('Administrasi jadwal reguler staff uji', false)
        ->assertDontSee('Reguler Staff</option>', false);
});

test('tampilan shift tanpa pilih grub memuat semua grub shift', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&tampilan=shift');

    $respons->assertStatus(200)->assertSee('Pengecekan motor grub D uji', false);
});

test('grub yang tidak dikenal diabaikan tanpa error', function () {
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&tampilan=shift&grub=grub_z')
        ->assertStatus(200)
        ->assertSee('Pengecekan motor grub D uji', false);
});

// ============================================================
// KOLABORASI: SATU BARIS + DAFTAR KOLABORATOR
// ============================================================

test('laporan kolaborasi tampil satu baris dengan daftar kolaborator di tab Shift', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&tampilan=shift&grub=grub_d');

    $respons->assertStatus(200)
        // Satu baris pekerjaan untuk laporan induk (kode laporan tidak lagi
        // ditampilkan, baris dikenali lewat penanda data-laporan-id).
        ->assertSee('data-laporan-id="' . $this->laporanInduk->id . '"', false)
        ->assertSee('Berkolaborasi dengan:', false)
        ->assertSee('Teknisi Grub D Uji', false)
        // Salinan kolaborasi TIDAK dirender sebagai baris sendiri
        ->assertDontSee('data-laporan-id="' . $this->laporanAnak->id . '"', false);

    $html = $respons->getContent();

    expect(substr_count($html, 'data-laporan-id="' . $this->laporanInduk->id . '"'))->toBe(1);
});

test('pekerjaan milik teknisi grub D tetap muncul sebagai baris tersendiri', function () {
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&tampilan=shift&grub=grub_d')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $this->laporanGrubD->id . '"', false);
});

// ============================================================
// NAVIGASI TANGGAL
// ============================================================

test('tanggal lain bisa dibuka dan laporan hari itu yang tampil', function () {
    $kemarin = now()->subDays(2)->toDateString();

    Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'report_date'      => $kemarin,
        'report_code'      => 'REG-HARI-LAIN',
        'work_description' => 'Pekerjaan dua hari lalu',
        'status'           => 'submitted',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $kemarin)
        ->assertStatus(200)
        ->assertSee('Pekerjaan dua hari lalu', false)
        ->assertDontSee('Ganti bearing pompa reguler uji', false);
});

test('laporan terbaru tampil di urutan paling atas', function () {
    // Bersihkan laporan pada tanggal uji agar urutannya hanya ditentukan data ini.
    Report::whereDate('report_date', $this->tanggal)->delete();

    $lama = Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'REG-URUT-LAMA',
        'work_description' => 'Pekerjaan lama dibuat lebih dulu',
        'status'           => 'submitted',
    ]);
    $lama->forceFill(['created_at' => now()->subHours(3)])->save();

    $baru = Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'REG-URUT-BARU',
        'work_description' => 'Pekerjaan paling baru',
        'status'           => 'submitted',
    ]);
    $baru->forceFill(['created_at' => now()])->save();

    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->getContent();

    $posisiBaru = strpos($html, 'Pekerjaan paling baru');
    $posisiLama = strpos($html, 'Pekerjaan lama dibuat lebih dulu');

    expect($posisiBaru)->not->toBeFalse()
        ->and($posisiLama)->not->toBeFalse()
        ->and($posisiBaru)->toBeLessThan($posisiLama);
});

test('export csv memakai filter tampilan dan tanggal yang sama', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/csv?tanggal=' . $this->tanggal . '&tampilan=shift&grub=grub_d');

    $respons->assertStatus(200);

    $isiCsv = $respons->streamedContent();

    expect($isiCsv)->toContain('GRUPD-KERJA-01')
        ->and($isiCsv)->toContain('Kolaborator')
        // Laporan reguler dengan kolaborator Grub D ikut Shift, satu baris.
        ->and($isiCsv)->toContain('REG-INDUK-01')
        ->and(substr_count($isiCsv, 'REG-INDUK-01'))->toBe(1)
        ->and($isiCsv)->not->toContain('SALINAN-KOLAB-01');
});

