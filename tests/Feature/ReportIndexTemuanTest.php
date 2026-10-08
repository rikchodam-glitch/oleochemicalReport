<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Buat laporan uji pada tanggal uji untuk pengujian kolom Pekerjaan di tabel
 * halaman daftar laporan.
 *
 * @param  int                   $teknisiId  ID teknisi pemilik laporan
 * @param  string                $tanggal    Tanggal laporan (Y-m-d)
 * @param  array<string, mixed>  $kolom      Kolom tambahan/penimpa
 * @return Report
 */
function laporanTemuanUji(int $teknisiId, string $tanggal, array $kolom = []): Report
{
    return Report::create(array_merge([
        'technician_id'    => $teknisiId,
        'creator_id'       => $teknisiId,
        'report_date'      => $tanggal,
        'work_description' => 'Pekerjaan uji temuan',
        'status'           => 'completed',
    ], $kolom));
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Reguler Temuan',
        'nik'    => 'REG950',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();
});

test('tabel laporan menampilkan temuan lalu penyebab di bawah deskripsi tanpa kode laporan', function () {
    laporanTemuanUji($this->teknisi->id, $this->tanggal, [
        'report_code'      => 'RPT-TEMUAN-0001',
        'work_description' => 'Pemasangan pompa fat trap 6060P1',
        'finding'          => 'Baut pengikat pompa longgar',
        'root_cause'       => 'Kebocoran pada mechseal',
    ]);

    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Temuan:', false)
        ->assertSee('Baut pengikat pompa longgar', false)
        ->assertSee('Penyebab:', false)
        ->assertSee('Kebocoran pada mechseal', false)
        ->assertSeeInOrder([
            'Pemasangan pompa fat trap 6060P1',
            'Temuan:',
            'Baut pengikat pompa longgar',
            'Penyebab:',
            'Kebocoran pada mechseal',
        ], false)
        ->getContent();

    // Kode laporan tidak lagi ditampilkan di tabel (masih bisa dicari lewat
    // kotak filter "Cari kode laporan").
    expect($html)->not->toContain('RPT-TEMUAN-0001');

    // Pemisah "|" yang dulu mengiringi kode laporan ikut dihapus.
    expect($html)->not->toContain('text-slate-200 mx-1');
});

test('baris tabel membawa penanda data-laporan-id sebagai pengganti kode laporan', function () {
    $laporan = laporanTemuanUji($this->teknisi->id, $this->tanggal, [
        'report_code'      => 'RPT-PENANDA-0001',
        'work_description' => 'Pekerjaan penanda baris uji',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        // Kode laporan tidak tampil lagi, jadi baris dikenali lewat penanda id
        // laporan (dipakai juga oleh test halaman laporan lainnya).
        ->assertSee('data-laporan-id="' . $laporan->id . '"', false)
        ->assertDontSee('RPT-PENANDA-0001', false);
});

test('laporan tanpa temuan dan penyebab tidak menampilkan labelnya', function () {
    laporanTemuanUji($this->teknisi->id, $this->tanggal, [
        'report_code'      => 'RPT-KOSONG-0001',
        'work_description' => 'Pekerjaan tanpa temuan uji',
        'finding'          => null,
        'root_cause'       => null,
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Pekerjaan tanpa temuan uji', false)
        ->assertDontSee('Temuan:', false)
        ->assertDontSee('Penyebab:', false);
});

test('penyebab tetap tampil walau temuan dikosongkan', function () {
    laporanTemuanUji($this->teknisi->id, $this->tanggal, [
        'report_code'      => 'RPT-SEBAB-0001',
        'work_description' => 'Pekerjaan hanya penyebab uji',
        'finding'          => null,
        'root_cause'       => 'Kebocoran pada mechseal',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Pekerjaan hanya penyebab uji', false)
        ->assertSee('Penyebab:', false)
        ->assertSee('Kebocoran pada mechseal', false)
        ->assertDontSee('Temuan:', false);
});

test('lencana LANJUTAN tetap tampil di kolom pekerjaan tanpa kode laporan', function () {
    $induk = laporanTemuanUji($this->teknisi->id, $this->tanggal, [
        'report_code'      => 'RPT-INDUK-0001',
        'work_description' => 'Pekerjaan induk uji',
    ]);

    laporanTemuanUji($this->teknisi->id, $this->tanggal, [
        'report_code'       => 'RPT-LANJUT-0001',
        'work_description'  => 'Pekerjaan lanjutan uji',
        'continued_from_id' => $induk->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Pekerjaan lanjutan uji', false)
        ->assertSee('LANJUTAN', false)
        ->assertDontSee('RPT-LANJUT-0001', false)
        ->assertDontSee('RPT-INDUK-0001', false);
});

test('temuan dan penyebab panjang dipotong agar baris tabel tetap ringkas', function () {
    $temuanPanjang   = str_repeat('Baut pengikat longgar ', 10);
    $penyebabPanjang = str_repeat('Kebocoran mechseal ', 10);

    laporanTemuanUji($this->teknisi->id, $this->tanggal, [
        'report_code'      => 'RPT-PANJANG-0001',
        'work_description' => 'Pekerjaan temuan panjang uji',
        'finding'          => $temuanPanjang,
        'root_cause'       => $penyebabPanjang,
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee(Str::limit($temuanPanjang, 80), false)
        ->assertSee(Str::limit($penyebabPanjang, 80), false)
        ->assertDontSee($temuanPanjang, false)
        ->assertDontSee($penyebabPanjang, false);
});
