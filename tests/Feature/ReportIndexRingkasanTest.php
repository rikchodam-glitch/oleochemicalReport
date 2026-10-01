<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Ambil angka pada kartu ringkasan halaman laporan.
 *
 * Kartu ditandai atribut data-kartu (mis. "jenis-IT", "status-continue",
 * "total") supaya pengujian tidak bergantung pada kelas CSS/tata letak.
 *
 * @param  string  $html   Isi halaman
 * @param  string  $kartu  Penanda kartu
 * @return int
 */
function angkaKartuRingkasan(string $html, string $kartu): int
{
    preg_match('/data-kartu="' . preg_quote($kartu, '/') . '"[^>]*>([\d.,]+)</', $html, $cocok);

    return (int) str_replace([',', '.'], '', $cocok[1] ?? '0');
}

/**
 * Ambil angka jumlah laporan pada chip tanggal di panel navigasi tanggal.
 *
 * @param  string  $html     Isi halaman
 * @param  string  $tanggal  Format Y-m-d
 * @return int  -1 bila chip tanggal itu tidak ditemukan
 */
function angkaChipTanggalRingkasan(string $html, string $tanggal): int
{
    preg_match(
        '/data-chip-tanggal="' . preg_quote($tanggal, '/') . '"[^>]*data-chip-jumlah="(\d+)"/',
        $html,
        $cocok
    );

    return isset($cocok[1]) ? (int) $cocok[1] : -1;
}

/**
 * Buat laporan uji pada tanggal uji (nilai bawaan: pekerjaan selesai).
 *
 * @param  int                   $teknisiId  ID teknisi pemilik laporan
 * @param  string                $tanggal    Tanggal laporan (Y-m-d)
 * @param  array<string, mixed>  $kolom      Kolom tambahan/penimpa
 * @return Report
 */
function laporanRingkasanUji(int $teknisiId, string $tanggal, array $kolom = []): Report
{
    return Report::create(array_merge([
        'technician_id'    => $teknisiId,
        'creator_id'       => $teknisiId,
        'report_date'      => $tanggal,
        'work_description' => 'Pekerjaan uji ringkasan',
        'status'           => 'completed',
    ], $kolom));
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisiReguler = Technician::create([
        'name'   => 'Teknisi Reguler Ringkasan',
        'nik'    => 'REG901',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->teknisiGrubD = Technician::create([
        'name'   => 'Teknisi Grub D Ringkasan',
        'nik'    => 'GRUPD901',
        'group'  => 'grub_d',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();
});

test('kartu jenis pekerjaan memuat IT dan bisa diklik untuk memfilter tabel', function () {
    laporanRingkasanUji($this->teknisiReguler->id, $this->tanggal, [
        'report_code'      => 'UJI-IT-01',
        'work_description' => 'Perbaikan jaringan IT uji',
        'jenis_pekerjaan'  => 'IT',
    ]);

    laporanRingkasanUji($this->teknisiReguler->id, $this->tanggal, [
        'report_code'      => 'UJI-MEK-01',
        'work_description' => 'Ganti bearing mekanik uji',
        'jenis_pekerjaan'  => 'Mekanik',
    ]);

    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('data-kartu="jenis-IT"', false)
        ->assertSee('jenis_pekerjaan=IT', false)
        ->getContent();

    // Angka kartu dihitung sebagai facet: kartu lain tidak menjadi nol.
    expect(angkaKartuRingkasan($html, 'jenis-IT'))->toBe(1)
        ->and(angkaKartuRingkasan($html, 'jenis-Mekanik'))->toBe(1)
        ->and(angkaKartuRingkasan($html, 'total'))->toBe(2);

    $htmlIT = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&jenis_pekerjaan=IT')
        ->assertStatus(200)
        ->assertSee('Perbaikan jaringan IT uji', false)
        ->assertDontSee('Ganti bearing mekanik uji', false)
        ->getContent();

    // Tabel terfilter ke IT: kartu total mengikuti baris yang tampil.
    expect(angkaKartuRingkasan($htmlIT, 'total'))->toBe(1)
        ->and(angkaKartuRingkasan($htmlIT, 'jenis-Mekanik'))->toBe(1);
});

test('kartu status Continue dan Done tampil di samping filter Reguler/Shift', function () {
    laporanRingkasanUji($this->teknisiReguler->id, $this->tanggal, [
        'report_code'      => 'UJI-CONT-01',
        'work_description' => 'Pekerjaan belum tuntas uji',
        'jenis_pekerjaan'  => 'CM',
        'status'           => 'continue',
    ]);

    laporanRingkasanUji($this->teknisiReguler->id, $this->tanggal, [
        'report_code'      => 'UJI-DONE-01',
        'work_description' => 'Pekerjaan tuntas uji',
        'jenis_pekerjaan'  => 'CM',
        'status'           => 'completed',
    ]);

    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('data-kartu="status-continue"', false)
        ->assertSee('data-kartu="status-completed"', false)
        ->assertSee('status=continue', false)
        ->assertSee('status=completed', false)
        ->getContent();

    expect(angkaKartuRingkasan($html, 'status-continue'))->toBe(1)
        ->and(angkaKartuRingkasan($html, 'status-completed'))->toBe(1)
        ->and(angkaKartuRingkasan($html, 'total'))->toBe(2)
        // Kartu status dirender setelah tab Reguler/Shift (di sebelahnya).
        ->and(strpos($html, 'data-kartu="status-continue"'))
        ->toBeGreaterThan(strpos($html, 'Shift'));

    $htmlContinue = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal . '&status=continue')
        ->assertStatus(200)
        ->assertSee('Pekerjaan belum tuntas uji', false)
        ->assertDontSee('Pekerjaan tuntas uji', false)
        ->getContent();

    expect(angkaKartuRingkasan($htmlContinue, 'total'))->toBe(1)
        // Chip tanggal ikut memakai filter status yang sedang aktif.
        ->and(angkaChipTanggalRingkasan($htmlContinue, $this->tanggal))->toBe(1);
});

test('total dan chip tanggal menghitung kolaborasi serta salinan multi alat sekali', function () {
    $induk = laporanRingkasanUji($this->teknisiReguler->id, $this->tanggal, [
        'report_code'      => 'UJI-INDUK-01',
        'work_description' => 'Pekerjaan induk uji',
        'jenis_pekerjaan'  => 'CM',
    ]);

    // Salinan kolaborasi (untuk teknisi shift) dan salinan multi alat.
    laporanRingkasanUji($this->teknisiGrubD->id, $this->tanggal, [
        'report_code'      => 'UJI-KOLAB-01',
        'work_description' => 'Pekerjaan induk uji',
        'jenis_pekerjaan'  => 'CM',
        'collaborator_of'  => $induk->id,
    ]);

    laporanRingkasanUji($this->teknisiReguler->id, $this->tanggal, [
        'report_code'      => 'UJI-MULTI-01',
        'work_description' => 'Pekerjaan induk uji',
        'jenis_pekerjaan'  => 'CM',
        'multi_of'         => $induk->id,
    ]);

    $respons = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Berkolaborasi dengan:', false)
        ->assertDontSee('UJI-KOLAB-01', false)
        ->assertDontSee('UJI-MULTI-01', false);

    $html = $respons->getContent();

    // Satu laporan = satu baris: kartu total, info paginasi, dan chip tanggal
    // harus sama-sama bernilai 1 walau ada 2 baris salinan di database.
    expect(angkaKartuRingkasan($html, 'total'))->toBe(1)
        ->and(angkaChipTanggalRingkasan($html, $this->tanggal))->toBe(1)
        ->and(preg_match('/dari\s*<span[^>]*>1<\/span>\s*pekerjaan/u', $html))->toBe(1);
});

test('panel filter lanjutan berada tepat sebelum tabel dan mempertahankan tanggal', function () {
    laporanRingkasanUji($this->teknisiReguler->id, $this->tanggal, [
        'report_code'      => 'UJI-URUT-01',
        'work_description' => 'Pekerjaan uji urutan panel',
        'jenis_pekerjaan'  => 'CM',
    ]);

    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Filter Lanjutan', false)
        ->assertSee('Terapkan Filter', false)
        ->getContent();

    $posisiFilter = strpos($html, 'Terapkan Filter');
    $posisiTabel  = strpos($html, '<table');

    expect($posisiFilter)->not->toBeFalse()
        ->and($posisiTabel)->not->toBeFalse()
        ->and($posisiFilter)->toBeLessThan($posisiTabel);

    // Panel filter mengirim tanggal aktif supaya tidak melompat ke hari ini.
    preg_match('/<details.*?<\/details>/s', $html, $cocok);

    expect($cocok[0] ?? '')->toContain('name="tanggal"');
});

