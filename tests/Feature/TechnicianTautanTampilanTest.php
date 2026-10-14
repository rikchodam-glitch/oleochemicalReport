<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Ambil seluruh tautan halaman Laporan yang menyaring satu teknisi.
 *
 * Tautan dari profil teknisi WAJIB membawa tampilan yang sesuai tipe jadwal
 * teknisi itu; kalau tidak, daftar laporannya terbuka pada tampilan Reguler
 * dan pekerja shift tampak tidak punya laporan (daftar kosong).
 *
 * @param  string  $html       Isi halaman profil teknisi
 * @param  int     $idTeknisi  ID teknisi yang dipakai pada tautan
 * @return array<int, string>  Daftar URL, entity HTML sudah didekode
 */
function tautanLaporanTeknisi(string $html, int $idTeknisi): array
{
    preg_match_all('/href="([^"]*\/reports\?[^"]*technician_id=' . $idTeknisi . '[^"]*)"/', $html, $cocok);

    return array_values(array_unique(array_map(
        fn (string $url) => html_entity_decode($url, ENT_QUOTES),
        $cocok[1] ?? []
    )));
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisiShift = Technician::create([
        'name'   => 'Teknisi Shift Tautan',
        'nik'    => 'TTL901',
        'group'  => 'grub_b',
        'status' => 'active',
    ]);

    $this->teknisiReguler = Technician::create([
        'name'   => 'Teknisi Reguler Tautan',
        'nik'    => 'TTL902',
        'group'  => 'reguler_staff',
        'status' => 'active',
    ]);

    // Tanggal di awal bulan berjalan: periode Rincian Man Hour Harian memakai
    // bulan berjalan selama belum ada jadwal shift yang diimpor, sehingga baris
    // tanggal ini pasti ada dan tautannya pasti dirender.
    $this->tanggal = now()->startOfMonth()->toDateString();

    $this->laporanShift = Report::create([
        'technician_id'    => $this->teknisiShift->id,
        'creator_id'       => $this->teknisiShift->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'TTL-SHF-01',
        'work_description' => 'Pekerjaan shift tautan uji',
        'jenis_pekerjaan'  => 'PM',
        'status'           => 'completed',
    ]);

    $this->laporanReguler = Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'creator_id'       => $this->teknisiReguler->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'TTL-REG-01',
        'work_description' => 'Pekerjaan reguler tautan uji',
        'jenis_pekerjaan'  => 'CM',
        'status'           => 'completed',
    ]);
});

test('tautan laporan dari profil teknisi shift membawa tampilan shift dan grubnya', function () {
    $html = $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisiShift->id)
        ->assertStatus(200)
        ->getContent();

    $tautan = tautanLaporanTeknisi($html, $this->teknisiShift->id);

    expect($tautan)->not->toBeEmpty()
        ->and(collect($tautan)->every(
            fn (string $url) => str_contains($url, 'tampilan=shift') && str_contains($url, 'grub=grub_b')
        ))->toBeTrue()
        // Baris Rincian Man Hour Harian membawa tanggal harinya sekalian.
        ->and(collect($tautan)->contains(
            fn (string $url) => str_contains($url, 'tanggal=' . $this->tanggal)
        ))->toBeTrue();
});

test('tautan laporan dari profil teknisi reguler memakai tampilan reguler tanpa grub', function () {
    $html = $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisiReguler->id)
        ->assertStatus(200)
        ->getContent();

    $tautan = tautanLaporanTeknisi($html, $this->teknisiReguler->id);

    expect($tautan)->not->toBeEmpty()
        ->and(collect($tautan)->every(
            fn (string $url) => str_contains($url, 'tampilan=reguler')
        ))->toBeTrue()
        ->and(collect($tautan)->every(
            fn (string $url) => ! str_contains($url, 'grub=')
        ))->toBeTrue();
});

test('membuka tautan dari profil teknisi shift menampilkan laporan pada tanggal itu', function () {
    $html = $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisiShift->id)
        ->assertStatus(200)
        ->getContent();

    $tautan  = tautanLaporanTeknisi($html, $this->teknisiShift->id);
    $urlHari = collect($tautan)->first(fn (string $url) => str_contains($url, 'tanggal=' . $this->tanggal));

    expect($urlHari)->not->toBeNull('Tautan tanggal dari baris Rincian Man Hour Harian tidak ditemukan.');

    $this->actingAs($this->admin)
        ->get($urlHari)
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $this->laporanShift->id . '"', false)
        ->assertDontSee('data-laporan-id="' . $this->laporanReguler->id . '"', false);
});

test('laporan teknisi shift memang tidak tampil pada tampilan reguler', function () {
    // Inilah sebab tautan profil WAJIB membawa tampilan: tampilan Reguler hanya
    // memuat teknisi non-shift (keputusan 26/09/2026), sehingga daftar laporan
    // teknisi shift tampak kosong bila tautannya tanpa parameter tampilan.
    $this->actingAs($this->admin)
        ->get('/reports?technician_id=' . $this->teknisiShift->id . '&tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Belum ada pekerjaan', false)
        ->assertDontSee('data-laporan-id="' . $this->laporanShift->id . '"', false);
});

