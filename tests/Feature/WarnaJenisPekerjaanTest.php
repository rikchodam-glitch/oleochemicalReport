<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Support\WarnaJenisPekerjaan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Warna satu jenis pekerjaan WAJIB seragam antara kartu/lencana (filter halaman
 * Laporan, popup export, detail laporan, detail teknisi) dan grafik pada
 * halaman detail alat/lokasi, punya ciri sendiri (tidak mirip warna jenis
 * lain), dan tetap kontras di atas latar putih.
 *
 * Seluruh perhitungan di berkas ini memakai rumus baku (WCAG untuk kontras,
 * CIE76 di ruang Lab untuk jarak warna) supaya pemeriksaannya tidak bergantung
 * pada penilaian mata.
 */

/**
 * Pecah kode warna hex menjadi kanal merah, hijau, biru (0-255).
 *
 * @param  string  $hex  Kode warna hex (#RRGGBB)
 * @return array<int, int>  Kanal warna
 */
function warnaUjiRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Luminansi relatif satu warna menurut rumus WCAG.
 *
 * @param  string  $hex  Kode warna hex
 * @return float  Luminansi 0-1
 */
function warnaUjiLuminansi(string $hex): float
{
    $kanal = array_map(function (int $nilai): float {
        $bagian = $nilai / 255;

        return $bagian <= 0.03928 ? $bagian / 12.92 : (($bagian + 0.055) / 1.055) ** 2.4;
    }, warnaUjiRgb($hex));

    return 0.2126 * $kanal[0] + 0.7152 * $kanal[1] + 0.0722 * $kanal[2];
}

/**
 * Rasio kontras dua warna (1 = sama, 21 = hitam lawan putih).
 *
 * @param  string  $warna  Warna yang diuji
 * @param  string  $latar  Warna latar (bawaan putih)
 * @return float  Rasio kontras
 */
function warnaUjiKontras(string $warna, string $latar = '#FFFFFF'): float
{
    $a = warnaUjiLuminansi($warna);
    $b = warnaUjiLuminansi($latar);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/**
 * Ubah warna hex ke ruang warna Lab (D65) untuk mengukur jarak warna.
 *
 * @param  string  $hex  Kode warna hex
 * @return array<int, float>  Komponen L, a, b
 */
function warnaUjiLab(string $hex): array
{
    [$r, $g, $b] = array_map(function (int $nilai): float {
        $bagian = $nilai / 255;

        return $bagian <= 0.03928 ? $bagian / 12.92 : (($bagian + 0.055) / 1.055) ** 2.4;
    }, warnaUjiRgb($hex));

    $x = ($r * 0.4124 + $g * 0.3576 + $b * 0.1805) / 0.95047;
    $y = ($r * 0.2126 + $g * 0.7152 + $b * 0.0722) / 1.00000;
    $z = ($r * 0.0193 + $g * 0.1192 + $b * 0.9505) / 1.08883;

    $fungsi = fn (float $t): float => $t > 0.008856 ? $t ** (1 / 3) : (7.787 * $t + 16 / 116);

    return [116 * $fungsi($y) - 16, 500 * ($fungsi($x) - $fungsi($y)), 200 * ($fungsi($y) - $fungsi($z))];
}

/**
 * Jarak warna CIE76 antara dua kode warna hex.
 *
 * @param  string  $a  Warna pertama
 * @param  string  $b  Warna kedua
 * @return float  Jarak warna (0 = sama)
 */
function warnaUjiJarak(string $a, string $b): float
{
    $la = warnaUjiLab($a);
    $lb = warnaUjiLab($b);

    return sqrt(($la[0] - $lb[0]) ** 2 + ($la[1] - $lb[1]) ** 2 + ($la[2] - $lb[2]) ** 2);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTWRN'], ['name' => 'PT Warna']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPWRN'],
        ['name' => 'Departemen Warna']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAWRN'],
        ['name' => 'Area Warna']
    );

    $this->flSection = FunctionalLocation::create([
        'code'          => 'PTWRN-DEPWRN-AREAWRN-01',
        'segment'       => '01',
        'name'          => 'Section Warna',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->teknisi = Technician::create([
        'name' => 'Teknisi Warna', 'nik' => 'WRN001', 'group' => 'reguler', 'status' => 'active',
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-WRN-001',
        'description'    => 'Pompa Warna',
        'tech_ident_no'  => 'WRN-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    $this->tanggal = now()->toDateString();
});

/**
 * Buat laporan uji berjenis pekerjaan tertentu.
 *
 * @param  int          $teknisiId   ID teknisi pemilik laporan
 * @param  string       $tanggal     Tanggal laporan (Y-m-d)
 * @param  string       $jenis       Nilai kolom jenis_pekerjaan
 * @param  string       $kode        Kode laporan (harus unik)
 * @param  int|null     $asetId      Alat yang dikerjakan (opsional)
 * @param  int|null     $funcLocId   Lokasi yang dikerjakan (opsional)
 * @return Report
 */
function laporanWarnaUji(
    int $teknisiId,
    string $tanggal,
    string $jenis,
    string $kode,
    ?int $asetId = null,
    ?int $funcLocId = null
): Report {
    return Report::create([
        'technician_id'    => $teknisiId,
        'creator_id'       => $teknisiId,
        'report_date'      => $tanggal,
        'report_code'      => $kode,
        'work_description' => 'Pekerjaan warna uji ' . $jenis,
        'jenis_pekerjaan'  => $jenis,
        'asset_id'         => $asetId,
        'funcloc_id'       => $funcLocId,
        'report_type'      => $asetId ? 'equipment_repair' : 'area_work',
        'status'           => 'completed',
    ]);
}

test('setiap jenis pekerjaan punya warna sendiri dan Project memakai merah', function () {
    $palet = WarnaJenisPekerjaan::PALET;

    expect(WarnaJenisPekerjaan::jenisTersedia())->toBe([
        'CM', 'dCM', 'PM', 'CM/PM', 'EI', 'Mekanik', 'Fabrikasi', 'Sipil', 'IT', 'Project', 'UMUM',
    ]);

    // Tidak ada dua jenis yang memakai kode warna sama.
    expect(count(array_unique(array_values($palet))))->toBe(count($palet));

    // Project = merah: kanal merah paling besar dan jauh di atas kanal lain.
    [$r, $g, $b] = warnaUjiRgb($palet['Project']);

    expect($r)->toBeGreaterThan(120);
    expect($r - $g)->toBeGreaterThan(60);
    expect($r - $b)->toBeGreaterThan(60);
});

test('warna antar jenis tidak mirip dan tetap kontras di atas latar putih', function () {
    $palet = WarnaJenisPekerjaan::PALET;
    $jenis = array_keys($palet);

    $jarakTerkecil = 1000.0;

    for ($i = 0; $i < count($jenis); $i++) {
        for ($j = $i + 1; $j < count($jenis); $j++) {
            $jarakTerkecil = min($jarakTerkecil, warnaUjiJarak($palet[$jenis[$i]], $palet[$jenis[$j]]));
        }
    }

    // CIE76 >= 25 berarti dua warna jelas berbeda bagi mata (bukan "mirip").
    expect($jarakTerkecil)->toBeGreaterThanOrEqual(25.0);

    foreach ($palet as $jenisPekerjaan => $hex) {
        // Isi grafik tetap terlihat di atas kartu putih.
        expect(warnaUjiKontras($hex))->toBeGreaterThanOrEqual(2.5);

        // Teks lencana memenuhi kontras WCAG AA (>= 4,5:1) untuk teks kecil.
        expect(warnaUjiKontras(WarnaJenisPekerjaan::teks($jenisPekerjaan)))->toBeGreaterThanOrEqual(4.5);
    }
});

test('helper warna memakai palet yang sama untuk grafik dan lencana', function () {
    expect(WarnaJenisPekerjaan::hex('Project'))->toBe(WarnaJenisPekerjaan::PALET['Project']);
    expect(WarnaJenisPekerjaan::hex(null))->toBe(WarnaJenisPekerjaan::CADANGAN);
    expect(WarnaJenisPekerjaan::hex('TI'))->toBe(WarnaJenisPekerjaan::CADANGAN);
    expect(WarnaJenisPekerjaan::hex(' CM '))->toBe(WarnaJenisPekerjaan::PALET['CM']);
    expect(WarnaJenisPekerjaan::teks(''))->toBe(WarnaJenisPekerjaan::teks(null));

    // EI (#7C3AED) dipakai sebagai contoh: latar muda, teks gelap, garis sedang.
    expect(WarnaJenisPekerjaan::gayaLencana('EI'))
        ->toContain('background-color: rgba(124, 58, 237, 0.12)')
        ->toContain('color: ' . WarnaJenisPekerjaan::teks('EI'))
        ->toContain('border-color: rgba(124, 58, 237, 0.38)');

    expect(WarnaJenisPekerjaan::gayaTeks('EI'))->toBe('color: ' . WarnaJenisPekerjaan::teks('EI'));
    expect(WarnaJenisPekerjaan::gayaGaris('EI'))->toBe('border-color: rgba(124, 58, 237, 0.38)');

    // Teks lencana = versi gelap warna utama: ronanya sama, kontrasnya lebih tinggi.
    expect(warnaUjiKontras(WarnaJenisPekerjaan::teks('EI')))
        ->toBeGreaterThan(warnaUjiKontras(WarnaJenisPekerjaan::hex('EI')));
});

test('kartu dan lencana halaman Laporan memakai warna palet jenis pekerjaan', function () {
    laporanWarnaUji($this->teknisi->id, $this->tanggal, 'Project', 'WRN-PRJ-01');
    laporanWarnaUji($this->teknisi->id, $this->tanggal, 'EI', 'WRN-EI-01');

    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->getContent();

    // Angka kartu ringkasan (filter cepat) memakai warna teks palet jenisnya.
    expect($html)->toContain('style="color: ' . WarnaJenisPekerjaan::teks('Project') . '" data-kartu="jenis-Project"');
    expect($html)->toContain('style="color: ' . WarnaJenisPekerjaan::teks('EI') . '" data-kartu="jenis-EI"');

    // Lencana kolom Jenis pada tabel memakai latar/teks/garis dari palet yang sama.
    expect($html)->toContain(WarnaJenisPekerjaan::gayaLencana('Project'));
    expect($html)->toContain(WarnaJenisPekerjaan::gayaLencana('EI'));

    // Popup export memakai warna utama yang sama (titik penanda + warna centang).
    expect($html)->toContain('background-color: ' . WarnaJenisPekerjaan::hex('Project'));
    expect($html)->toContain('accent-color: ' . WarnaJenisPekerjaan::hex('EI'));
});

test('grafik halaman detail alat/lokasi memakai warna palet yang sama dengan halaman Laporan', function () {
    laporanWarnaUji($this->teknisi->id, $this->tanggal, 'EI', 'WRN-ALAT-EI', $this->aset->id);
    laporanWarnaUji($this->teknisi->id, $this->tanggal, 'Project', 'WRN-ALAT-PRJ', $this->aset->id);
    laporanWarnaUji($this->teknisi->id, $this->tanggal, 'Sipil', 'WRN-LOK-SIPIL', null, $this->flSection->id);

    // Halaman alat hanya memuat laporan alat itu; halaman lokasi memuat laporan
    // alat di dalamnya DITAMBAH pekerjaan area pada section itu.
    $warnaAlat   = [WarnaJenisPekerjaan::hex('EI'), WarnaJenisPekerjaan::hex('Project')];
    $warnaLokasi = [...$warnaAlat, WarnaJenisPekerjaan::hex('Sipil')];

    $htmlAlat = $this->actingAs($this->admin)
        ->get('/assets/' . $this->aset->id)
        ->assertStatus(200)
        // Donut komposisi dan batang tren memakai warna palet per jenis.
        ->assertViewHas('komposisiTipe', fn ($komposisi) => array_diff($warnaAlat, collect($komposisi)->pluck('color')->all()) === [])
        ->assertViewHas('trenPerbaikan', fn ($tren) => array_diff($warnaAlat, collect($tren['datasets'])->pluck('color')->all()) === [])
        ->getContent();

    $htmlLokasi = $this->actingAs($this->admin)
        ->get('/locations/' . $this->flSection->id)
        ->assertStatus(200)
        ->assertViewHas('komposisiTipe', fn ($komposisi) => array_diff($warnaLokasi, collect($komposisi)->pluck('color')->all()) === [])
        ->getContent();

    $htmlLaporan = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->getContent();

    // Kode warna yang dipakai grafik harus muncul juga di halaman Laporan
    // (kartu, lencana, dan popup export memakai palet yang sama).
    foreach ($warnaLokasi as $kode) {
        expect($htmlAlat)->toContain($kode);
        expect($htmlLokasi)->toContain($kode);
        expect($htmlLaporan)->toContain($kode);
    }
});

test('lencana jenis pekerjaan halaman detail laporan dan detail teknisi memakai warna palet', function () {
    $laporan = laporanWarnaUji($this->teknisi->id, $this->tanggal, 'Fabrikasi', 'WRN-DETAIL-01');

    $this->actingAs($this->admin)
        ->get('/reports/' . $laporan->id)
        ->assertStatus(200)
        ->assertSee(WarnaJenisPekerjaan::gayaLencana('Fabrikasi'), false);

    $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisi->id)
        ->assertStatus(200)
        ->assertSee(WarnaJenisPekerjaan::gayaLencana('Fabrikasi'), false);
});
