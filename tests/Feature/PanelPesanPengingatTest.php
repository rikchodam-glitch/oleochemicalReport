<?php

use App\Models\PanelRuangan;
use App\Models\Technician;
use App\Services\PanelNotifikasiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Format pesan pengingat harian ruang panel (Telegram):
 *
 *   Halo <nama>, kondisi terkini <ruangan> (<lokasi>)
 *   Suhu Rata-rata: xx,xx C / Kelembapan: xx,xx %        (tebal)
 *   Suhu Terkini: xx,xx C / Kelembapan: xx,xx %
 *   Kondisi: TERLALU PANAS (berdasarkan suhu rata-rata)  (tebal)
 *   Perangkat: offline, pembacaan terakhir dd/mm/yyyy HH:ii
 *   Dashboard lengkap: <app.url>/panel
 *
 * "Suhu Rata-rata" dan lencana kondisi memakai pembacaan 24 jam terakhir
 * (config('panel.notifikasi.rata_rata_jam')), sedangkan "Suhu Terkini" memakai
 * pembacaan paling baru.
 */

beforeEach(function () {
    $this->seed();

    config([
        'app.url'                        => 'https://oleomtc.com',
        'panel.notifikasi.rata_rata_jam' => 24,
    ]);

    $this->teknisi = Technician::query()->create([
        'name' => 'Erik Khoirul Adam', 'nik' => '930000001', 'section' => 'ei',
        'group' => 'reguler', 'status' => 'active', 'telegram_id' => '555000777',
    ]);
});

/**
 * Buat ruangan panel dengan deret pembacaan [waktu, suhu, kelembapan].
 *
 * @param  string  $nama                                    Nama ruangan
 * @param  array<int, array{0: string, 1: float, 2: float}>  $pembacaan  Deret pembacaan
 * @param  bool    $online                                   Status perangkat terakhir
 * @return PanelRuangan
 */
function ruanganPesanUji(string $nama, array $pembacaan, bool $online = true): PanelRuangan
{
    $ruangan = PanelRuangan::query()->create([
        'nama_ruangan'      => $nama,
        'pt_location'       => 'EPE',
        'plant'             => null,
        'ip_address'        => '192.168.41.90',
        'port'              => 80,
        'protokol'          => 'otomatis',
        'aktif'             => true,
        'ambang_suhu_atas'  => 30,
        'ambang_suhu_bawah' => 18,
        'status_terakhir'   => $online ? 'online' : 'offline',
        'terakhir_ditarik'  => now(),
    ]);

    foreach ($pembacaan as $nomor => $baris) {
        $ruangan->pembacaan()->create([
            'waktu'        => $baris[0],
            'suhu'         => $baris[1],
            'kelembapan'   => $baris[2],
            'valid'        => true,
            'sesi_boot'    => 'sk',
            // Kolom (panel_ruangan_id, sesi_boot, id_perangkat) berindeks unik,
            // jadi tiap pembacaan diberi nomor perangkat berbeda - sama seperti
            // penarikan nyata yang menaikkan id_perangkat tiap pembacaan baru.
            'id_perangkat' => $nomor + 1,
            'sumber'       => 'pull',
        ]);
    }

    return $ruangan;
}

test('pesan pengingat memakai format baru dengan rata-rata dan suhu terkini', function () {
    $ruangan = ruanganPesanUji('Ruang Panel Server', [
        ['2026-10-06 05:00:00', 20.10, 51.20],
        ['2026-10-06 06:00:00', 20.30, 51.30],
        ['2026-10-06 07:55:00', 25.10, 71.20],
    ]);

    $ruangan->penanggungJawab()->attach($this->teknisi->id);

    $pesan = app(PanelNotifikasiService::class)
        ->susunPesan($this->teknisi, Carbon::parse('2026-10-06 08:00:00'))['pesan'];

    // Rata-rata 24 jam terakhir: suhu (20,10 + 20,30 + 25,10) / 3 = 21,83,
    // kelembapan (51,20 + 51,30 + 71,20) / 3 = 57,90.
    $tautan = '<a href="https://oleomtc.com/panel/ruangan/' . $ruangan->id . '">';

    expect($pesan)->toContain('Halo Erik Khoirul Adam, kondisi terkini ' . $tautan . 'Ruang Panel Server (EPE)</a>')
        ->and($pesan)->toContain('<b>Suhu Rata-rata: 21,83 C / Kelembapan: 57,90 %</b>')
        ->and($pesan)->toContain('Suhu Terkini: 25,10 C / Kelembapan: 71,20 %')
        ->and($pesan)->toContain('<b>Kondisi: SUHU NORMAL (berdasarkan suhu rata-rata)</b>')
        ->and($pesan)->toContain('Perangkat: online, pembacaan terakhir 06/10/2026 07:55')
        ->and($pesan)->toContain('Dashboard lengkap: https://oleomtc.com/panel');
});

test('kondisi dinilai dari suhu rata-rata, bukan pembacaan terakhir', function () {
    $ruangan = ruanganPesanUji('Ruang Panel Pompa', [
        ['2026-10-06 05:00:00', 20.00, 50.00],
        ['2026-10-06 06:00:00', 20.00, 50.00],
        ['2026-10-06 07:55:00', 45.00, 50.00],
    ]);

    $ruangan->penanggungJawab()->attach($this->teknisi->id);

    $pesan = app(PanelNotifikasiService::class)
        ->susunPesan($this->teknisi, Carbon::parse('2026-10-06 08:00:00'))['pesan'];

    // Rata-rata (20 + 20 + 45) / 3 = 28,33 masih di bawah ambang 30 sehingga
    // kondisinya normal, walau pembacaan terakhirnya 45 C.
    expect($pesan)->toContain('Suhu Rata-rata: 28,33 C')
        ->and($pesan)->toContain('Suhu Terkini: 45,00 C')
        ->and($pesan)->toContain('Kondisi: SUHU NORMAL');
});

test('rata-rata hanya memakai pembacaan dalam 24 jam terakhir', function () {
    $ruangan = ruanganPesanUji('Ruang Panel Lama', [
        // Di luar jendela 24 jam (31 jam sebelum waktu acuan).
        ['2026-10-05 01:00:00', 60.00, 90.00],
        ['2026-10-06 05:00:00', 24.00, 60.00],
        ['2026-10-06 06:00:00', 26.00, 62.00],
    ]);

    $ruangan->penanggungJawab()->attach($this->teknisi->id);

    $pesan = app(PanelNotifikasiService::class)
        ->susunPesan($this->teknisi, Carbon::parse('2026-10-06 08:00:00'))['pesan'];

    // Pembacaan 60 C dari 05/10 01:00 TIDAK dihitung: rata-rata = (24 + 26) / 2.
    expect($pesan)->toContain('Suhu Rata-rata: 25,00 C')
        ->and($pesan)->toContain('Kondisi: SUHU NORMAL');
});


test('ruangan tanpa pembacaan dalam 24 jam terakhir tampil tanda - dan kondisi belum ada data', function () {
    $ruangan = ruanganPesanUji('Ruang Panel Mati', [
        ['2026-10-01 05:00:00', 31.00, 61.00],
    ], false);

    $ruangan->penanggungJawab()->attach($this->teknisi->id);

    $pesan = app(PanelNotifikasiService::class)
        ->susunPesan($this->teknisi, Carbon::parse('2026-10-06 08:00:00'))['pesan'];

    expect($pesan)->toContain('Suhu Rata-rata: - / Kelembapan: -')
        ->and($pesan)->toContain('Kondisi: BELUM ADA DATA (berdasarkan suhu rata-rata)')
        ->and($pesan)->toContain('Suhu Terkini: 31,00 C')
        ->and($pesan)->toContain('Perangkat: offline, pembacaan terakhir 01/10/2026 05:00');
});

test('sapaan dan tautan dashboard hanya muncul sekali walau ada beberapa ruangan', function () {
    $satu = ruanganPesanUji('Ruang Panel Satu', [['2026-10-06 07:00:00', 24.00, 55.00]]);
    $dua  = ruanganPesanUji('Ruang Panel Dua', [['2026-10-06 07:00:00', 26.00, 56.00]]);

    $satu->penanggungJawab()->attach($this->teknisi->id);
    $dua->penanggungJawab()->attach($this->teknisi->id);

    $pesan = app(PanelNotifikasiService::class)
        ->susunPesan($this->teknisi, Carbon::parse('2026-10-06 08:00:00'))['pesan'];

    // Ruangan diurutkan berdasarkan nama, jadi blok pertama adalah "Dua".
    $tautanDua   = '<a href="https://oleomtc.com/panel/ruangan/' . $dua->id . '">';
    $tautanSatu  = '<a href="https://oleomtc.com/panel/ruangan/' . $satu->id . '">';

    expect(substr_count($pesan, 'Halo Erik Khoirul Adam, kondisi terkini'))->toBe(1)
        ->and($pesan)->toContain('Halo Erik Khoirul Adam, kondisi terkini ' . $tautanDua . 'Ruang Panel Dua (EPE)</a>')
        ->and($pesan)->toContain('Kondisi terkini ' . $tautanSatu . 'Ruang Panel Satu (EPE)</a>')
        ->and(substr_count($pesan, 'Dashboard lengkap:'))->toBe(1)
        ->and(substr_count($pesan, 'Suhu Rata-rata:'))->toBe(2);
});

test('pengiriman otomatis tetap memakai pesan format baru', function () {
    $ruangan = ruanganPesanUji('Ruang Panel Otomatis', [['2026-10-06 07:30:00', 24.00, 60.00]]);
    $ruangan->penanggungJawab()->attach($this->teknisi->id);

    config([
        'services.telegram.bot_token' => 'token-uji',
        'panel.notifikasi.enabled'    => true,
    ]);

    Illuminate\Support\Facades\Http::fake();

    $ringkasan = app(PanelNotifikasiService::class)->kirimOtomatis(Carbon::parse('2026-10-06 08:00:00'));

    expect($ringkasan['terkirim'])->toBe(1);

    Illuminate\Support\Facades\Http::assertSent(function ($request) {
        $teks = (string) $request['text'];

        return str_contains($teks, '<b>Suhu Rata-rata: 24,00 C / Kelembapan: 60,00 %</b>')
            && str_contains($teks, 'Dashboard lengkap:');
    });
});

