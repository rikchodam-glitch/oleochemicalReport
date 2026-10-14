<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Ambil angka pada kartu ringkasan halaman Laporan.
 *
 * @param  string  $html   Isi halaman
 * @param  string  $kartu  Isi atribut data-kartu (mis. "total")
 * @return int
 */
function angkaKartuFilterTanggal(string $html, string $kartu): int
{
    preg_match('/data-kartu="' . preg_quote($kartu, '/') . '"[^>]*>([\d.,]+)</', $html, $cocok);

    return (int) str_replace([',', '.'], '', $cocok[1] ?? '0');
}

/**
 * Ambil tautan halaman Laporan yang memuat kunci query tertentu.
 *
 * Yang diperiksa adalah URL yang BENAR-BENAR dirender halaman, bukan potongan
 * teks: bug "tanggal reset ke hari ini" hanya tampak dari parameter yang
 * hilang pada tautan tab tampilan/grub.
 *
 * @param  string  $html   Isi halaman
 * @param  string  $kunci  Kunci query yang harus ada (mis. "tampilan=shift")
 * @return array<int, string>  Daftar URL, entity HTML sudah didekode
 */
function tautanLaporanMemuat(string $html, string $kunci): array
{
    preg_match_all('/href="([^"]*\/reports\?[^"]*)"/', $html, $cocok);

    $hasil = [];

    foreach ($cocok[1] ?? [] as $url) {
        $bersih = html_entity_decode($url, ENT_QUOTES);

        if (str_contains($bersih, $kunci)) {
            $hasil[] = $bersih;
        }
    }

    return array_values(array_unique($hasil));
}

/**
 * Ambil markup form pemilih grub (form yang memuat id="pilih-grub").
 *
 * Pemilihan grub hanya ada pada tampilan Shift. Pemeriksaan isian tersembunyi
 * harus dibatasi ke form ini karena halaman yang sama juga memuat panel Filter
 * Lanjutan dan popup Export Laporan dengan isian nama yang sama.
 *
 * @param  string  $html  Isi halaman
 * @return string  Markup form pemilih grub (string kosong bila tidak ada)
 */
function formPemilihGrub(string $html): string
{
    $posisi = strpos($html, 'id="pilih-grub"');

    if ($posisi === false) {
        return '';
    }

    $awal  = strrpos(substr($html, 0, $posisi), '<form');
    $akhir = strpos($html, '</form>', $posisi);

    if ($awal === false || $akhir === false) {
        return '';
    }

    return substr($html, $awal, $akhir - $awal);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisiReguler = Technician::create([
        'name'   => 'Teknisi Reguler Filter',
        'nik'    => 'FILT901',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->teknisiShift = Technician::create([
        'name'   => 'Teknisi Shift Filter',
        'nik'    => 'FILT902',
        'group'  => 'grub_b',
        'status' => 'active',
    ]);

    // Tanggal uji SENGAJA bukan hari ini: kalau tanggalnya hari ini, bug
    // "tanggal reset ke hari ini" tidak akan terlihat dari hasil halaman.
    $this->tanggal = now()->subDays(3)->toDateString();

    // Rentang uji juga sengaja tidak memuat tanggal uji di atas, supaya
    // penyaringan satu hari dan penyaringan rentang bisa dibedakan.
    $this->dari   = now()->subDays(6)->toDateString();
    $this->sampai = now()->subDays(4)->toDateString();

    $this->laporanReguler = Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'creator_id'       => $this->teknisiReguler->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'FILT-REG-01',
        'work_description' => 'Pekerjaan reguler filter uji',
        'jenis_pekerjaan'  => 'CM',
        'status'           => 'completed',
    ]);

    $this->laporanShift = Report::create([
        'technician_id'    => $this->teknisiShift->id,
        'creator_id'       => $this->teknisiShift->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'FILT-SHF-01',
        'work_description' => 'Pekerjaan shift filter uji',
        'jenis_pekerjaan'  => 'PM',
        'status'           => 'completed',
    ]);
});

test('tab tampilan mempertahankan tanggal yang sedang dipilih', function () {
    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->getContent();

    $tautanShift = tautanLaporanMemuat($html, 'tampilan=shift');

    expect($tautanShift)->toHaveCount(1)
        ->and($tautanShift[0])->toContain('tampilan=shift')
        ->and($tautanShift[0])->toContain('tanggal=' . $this->tanggal);

    // Bukti perilaku: membuka tautan Shift itu tetap menampilkan pekerjaan
    // pada tanggal yang sama (sebelum perbaikan, tanggalnya kembali ke hari
    // ini sehingga tampilan Shift selalu kosong).
    $this->actingAs($this->admin)
        ->get($tautanShift[0])
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $this->laporanShift->id . '"', false)
        ->assertDontSee('data-laporan-id="' . $this->laporanReguler->id . '"', false);
});

test('tab tampilan mempertahankan rentang tanggal yang sedang dipakai', function () {
    $html = $this->actingAs($this->admin)
        ->get('/reports?date_from=' . $this->dari . '&date_to=' . $this->sampai)
        ->assertStatus(200)
        ->assertSee('Sedang memakai rentang tanggal', false)
        ->getContent();

    $tautanShift = tautanLaporanMemuat($html, 'tampilan=shift');

    expect($tautanShift)->toHaveCount(1)
        ->and($tautanShift[0])->toContain('date_from=' . $this->dari)
        ->and($tautanShift[0])->toContain('date_to=' . $this->sampai);

    // Bukti perilaku: halaman sesudah berpindah tampilan masih memakai rentang.
    $this->actingAs($this->admin)
        ->get($tautanShift[0])
        ->assertStatus(200)
        ->assertSee('Sedang memakai rentang tanggal', false);
});

test('pemilih grub membawa tanggal atau rentang yang sedang dipakai', function () {
    $satuHari = $this->actingAs($this->admin)
        ->get('/reports?tampilan=shift&tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->getContent();

    $formSatuHari = formPemilihGrub($satuHari);

    expect($formSatuHari)->not->toBe('')
        ->and($formSatuHari)->toContain('name="tanggal" value="' . $this->tanggal . '"')
        ->and($formSatuHari)->not->toContain('name="date_from"');

    $rentang = $this->actingAs($this->admin)
        ->get('/reports?tampilan=shift&date_from=' . $this->dari . '&date_to=' . $this->sampai)
        ->assertStatus(200)
        ->getContent();

    $formRentang = formPemilihGrub($rentang);

    expect($formRentang)->not->toBe('')
        ->and($formRentang)->toContain('name="date_from" value="' . $this->dari . '"')
        ->and($formRentang)->toContain('name="date_to" value="' . $this->sampai . '"')
        // Rentang yang dipakai menggantikan tanggal harian, jadi form pemilih
        // grub tidak boleh membawa isian tanggal yang menyesatkan.
        ->and($formRentang)->not->toContain('name="tanggal"');
});

test('kartu Total Pekerjaan berada tepat di samping kartu Done dan Continue', function () {
    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->getContent();

    $posisiTotal    = strpos($html, 'data-kartu="total"');
    $posisiContinue = strpos($html, 'data-kartu="status-continue"');
    $posisiDone     = strpos($html, 'data-kartu="status-completed"');
    $posisiJenis    = strpos($html, 'data-kartu="jenis-EI"');

    expect($posisiTotal)->not->toBeFalse()
        ->and($posisiContinue)->not->toBeFalse()
        ->and($posisiDone)->not->toBeFalse()
        ->and($posisiJenis)->not->toBeFalse()
        // Urutan: Total, Continue, Done (satu grup kartu), lalu baris kartu jenis.
        ->and($posisiTotal)->toBeLessThan($posisiContinue)
        ->and($posisiContinue)->toBeLessThan($posisiDone)
        ->and($posisiDone)->toBeLessThan($posisiJenis)
        // Kartu Total hanya ada SEKALI (tidak tersisa di baris kartu jenis).
        ->and(substr_count($html, 'data-kartu="total"'))->toBe(1);

    // Angkanya tetap jumlah baris tabel pada filter yang sedang aktif.
    $jumlahBaris = substr_count($html, 'data-laporan-id="');

    expect($jumlahBaris)->toBeGreaterThan(0)
        ->and(angkaKartuFilterTanggal($html, 'total'))->toBe($jumlahBaris);
});

