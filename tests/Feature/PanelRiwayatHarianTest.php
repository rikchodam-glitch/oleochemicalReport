<?php

use App\Models\PanelPembacaan;
use App\Models\PanelRuangan;
use App\Models\User;
use App\Services\PanelRiwayatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Riwayat harian ruang panel: rekap per hari, grafik realtime halaman detail,
 * dan ekspor Excel-nya.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // Panel ditarik dari perangkat pada permintaan pertama; dimatikan supaya
    // pengujian tidak membuka soket ke perangkat nyata.
    config(['panel.pull.enabled' => false]);

    $this->ruangan = PanelRuangan::query()->create([
        'nama_ruangan'      => 'Ruang Panel Riwayat',
        'pt_location'       => 'EPE',
        'plant'             => '6010',
        'ip_address'        => '192.168.41.250',
        'port'              => 80,
        'protokol'          => 'otomatis',
        'aktif'             => true,
        'ambang_suhu_atas'  => 30,
        'ambang_suhu_bawah' => 18,
    ]);
});

/**
 * Tambahkan satu baris pembacaan pada tanggal-waktu tertentu.
 *
 * @param  string  $waktu       Waktu pengukuran (Y-m-d H:i:s)
 * @param  float   $suhu
 * @param  float   $kelembapan
 * @param  int     $nomorUrut   Nomor pembacaan perangkat (harus berbeda tiap baris)
 * @return PanelPembacaan
 */
function catatPembacaan(string $waktu, float $suhu, float $kelembapan, int $nomorUrut): PanelPembacaan
{
    return PanelPembacaan::query()->create([
        'panel_ruangan_id' => test()->ruangan->id,
        'waktu'            => $waktu,
        'suhu'             => $suhu,
        'kelembapan'       => $kelembapan,
        'valid'            => true,
        'sesi_boot'        => 'sk',
        'id_perangkat'     => $nomorUrut,
        'sumber'           => 'pull',
    ]);
}

test('rekap harian menghitung nilai minimum, maksimum, rata-rata, dan jumlah di luar ambang', function () {
    catatPembacaan('2026-10-05 10:00:00', 25.0, 60.0, 1);
    catatPembacaan('2026-10-05 11:00:00', 31.5, 65.0, 2);
    catatPembacaan('2026-10-06 09:00:00', 17.0, 70.0, 3);
    // Pembacaan di bulan lain tidak boleh ikut terhitung.
    catatPembacaan('2026-09-30 09:00:00', 40.0, 50.0, 4);

    $harian = app(PanelRiwayatService::class)->harian(
        $this->ruangan,
        Carbon::parse('2026-10-01'),
        Carbon::parse('2026-10-31')
    );

    expect($harian)->toHaveCount(2);

    $lima = $harian[0];

    expect($lima['tanggal'])->toBe('2026-10-05')
        ->and($lima['jumlah'])->toBe(2)
        ->and($lima['suhu_min'])->toBe(25.0)
        ->and($lima['suhu_max'])->toBe(31.5)
        ->and($lima['suhu_rata'])->toBe(28.25)
        ->and($lima['kelembapan_max'])->toBe(65.0)
        ->and($lima['jumlah_panas'])->toBe(1)
        ->and($lima['jumlah_dingin'])->toBe(0)
        ->and($lima['keterangan'])->toBe('Pernah terlalu panas');

    $enam = $harian[1];

    expect($enam['tanggal'])->toBe('2026-10-06')
        ->and($enam['jumlah_panas'])->toBe(0)
        ->and($enam['jumlah_dingin'])->toBe(1)
        ->and($enam['keterangan'])->toBe('Pernah terlalu dingin');

    $ringkasan = app(PanelRiwayatService::class)->ringkasan($harian);

    expect($ringkasan['jumlah_hari'])->toBe(2)
        ->and($ringkasan['jumlah_pembacaan'])->toBe(3)
        ->and($ringkasan['jumlah_panas'])->toBe(1)
        ->and($ringkasan['jumlah_dingin'])->toBe(1);
});

test('halaman detail menampilkan tabel riwayat harian dan tombol ekspor Excel', function () {
    catatPembacaan('2026-10-05 11:00:00', 31.5, 65.0, 1);

    $this->actingAs($this->admin)
        ->get('/panel/ruangan/' . $this->ruangan->id . '?bulan=2026-10')
        ->assertOk()
        ->assertSee('Grafik realtime')
        ->assertSee('Riwayat harian')
        ->assertSee('31.50')
        ->assertSee('Terlalu panas')
        ->assertSee('Ekspor Excel')
        ->assertSee('grafikRuangan', false);
});

test('ekspor riwayat harian mengunduh berkas Excel berisi rekap', function () {
    catatPembacaan('2026-10-05 11:00:00', 31.5, 65.0, 1);

    $balasan = $this->actingAs($this->admin)
        ->get(route('panel.ruangan.export-harian', ['panelRuangan' => $this->ruangan, 'bulan' => '2026-10']));

    $balasan->assertOk()->assertDownload();

    // Berkas .xlsx adalah arsip ZIP, jadi isinya wajib dimulai dengan "PK".
    expect(substr((string) $balasan->streamedContent(), 0, 2))->toBe('PK');
});

test('bulan yang tidak sah dikembalikan ke bulan berjalan tanpa galat', function () {
    $this->actingAs($this->admin)
        ->get('/panel/ruangan/' . $this->ruangan->id . '?bulan=bukan-bulan')
        ->assertOk()
        ->assertSee(now()->format('Y-m'));
});

test('endpoint data ruangan mengirim titik grafik terbaru untuk halaman detail', function () {
    catatPembacaan('2026-10-05 10:00:00', 25.0, 60.0, 1);
    catatPembacaan('2026-10-05 11:00:00', 26.5, 61.5, 2);

    $this->actingAs($this->admin)
        ->getJson('/panel/ruangan/' . $this->ruangan->id . '/data')
        ->assertOk()
        ->assertJsonCount(2, 'ruangan.grafik')
        ->assertJsonPath('ruangan.suhu', 26.5)
        ->assertJsonPath('ruangan.kondisi', 'normal');
});
