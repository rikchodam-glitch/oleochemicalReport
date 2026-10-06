<?php

use App\Models\PanelRuangan;
use App\Models\Technician;
use App\Models\User;
use App\Services\PanelNotifikasiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Pengingat harian ruang panel lewat Telegram: dikirim kepada penanggung jawab
 * (PIC) pada awal jam masuk kerja mereka, mengikuti jam kerja atau jadwal shift.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    config([
        'services.telegram.bot_token'    => 'token-uji',
        'panel.notifikasi.jendela_menit' => 15,
        'app.url'                        => 'https://oleomtc.com',
    ]);

    Http::fake();
});

/**
 * Buat satu ruangan panel dengan satu penanggung jawab.
 *
 * @param  Technician  $teknisi
 * @param  float|null  $suhu  Suhu pembacaan terakhir (null = belum ada data)
 * @return PanelRuangan
 */
function ruanganDenganPic(Technician $teknisi, ?float $suhu = 24.9): PanelRuangan
{
    static $nomor = 0;
    $nomor++;

    $ruangan = PanelRuangan::query()->create([
        'nama_ruangan'      => 'Ruang Panel Pengingat ' . $nomor,
        'pt_location'       => 'EPE',
        'plant'             => '6010',
        'ip_address'        => '192.168.41.25' . $nomor,
        'port'              => 80,
        'protokol'          => 'otomatis',
        'aktif'             => true,
        'ambang_suhu_atas'  => 30,
        'ambang_suhu_bawah' => 18,
    ]);

    $ruangan->penanggungJawab()->attach($teknisi->id);

    if ($suhu !== null) {
        $ruangan->pembacaan()->create([
            'waktu'        => '2026-10-06 07:55:00',
            'suhu'         => $suhu,
            'kelembapan'   => 65.4,
            'valid'        => true,
            'sesi_boot'    => 'sk',
            'id_perangkat' => 1,
            'sumber'       => 'pull',
        ]);
    }

    return $ruangan;
}

test('jam mulai kerja mengikuti jam kerja non-shift dan hari libur', function () {
    $notifikasi = app(PanelNotifikasiService::class);

    $reguler = Technician::query()->create([
        'name' => 'Teknisi Reguler', 'nik' => '920000001', 'section' => 'mekanik',
        'group' => 'reguler', 'status' => 'active',
    ]);

    $staff = Technician::query()->create([
        'name' => 'Teknisi Staff', 'nik' => '920000002', 'section' => 'ei',
        'group' => 'reguler_staff', 'status' => 'active',
    ]);

    // Selasa 06/10/2026.
    expect($notifikasi->mulaiKerja($reguler, Carbon::parse('2026-10-06')))->toBe('08:00')
        ->and($notifikasi->mulaiKerja($staff, Carbon::parse('2026-10-06')))->toBe('08:00')
        // Reguler Staff libur Sabtu.
        ->and($notifikasi->mulaiKerja($staff, Carbon::parse('2026-10-10')))->toBeNull()
        // Reguler tetap masuk Sabtu (08.00-14.00).
        ->and($notifikasi->mulaiKerja($reguler, Carbon::parse('2026-10-10')))->toBe('08:00')
        // Reguler libur Minggu.
        ->and($notifikasi->mulaiKerja($reguler, Carbon::parse('2026-10-11')))->toBeNull();
});

test('jam mulai kerja pekerja shift mengikuti kode pola rotasi', function () {
    $notifikasi = app(PanelNotifikasiService::class);
    $tanggal    = Carbon::parse('2026-10-06');

    $sasaran = [
        'grub_a' => '08:00',
        'grub_b' => '16:00',
        'grub_c' => null,
        'grub_d' => '00:00',
    ];

    $nomor = 0;

    foreach ($sasaran as $grup => $harapan) {
        $nomor++;

        $teknisi = Technician::query()->create([
            'name'    => 'Teknisi ' . $grup,
            'nik'     => '93' . str_pad((string) $nomor, 5, '0', STR_PAD_LEFT),
            'section' => 'mekanik',
            'group'   => $grup,
            'status'  => 'active',
        ]);

        expect($notifikasi->mulaiKerja($teknisi, $tanggal))->toBe($harapan);
    }
});

test('pengingat terkirim pada awal jam masuk kerja beserta kondisi ruangan', function () {
    $teknisi = Technician::query()->create([
        'name' => 'PIC Panel', 'nik' => '920000003', 'section' => 'ei',
        'group' => 'reguler', 'status' => 'active', 'telegram_id' => '555000111',
    ]);

    ruanganDenganPic($teknisi, 31.5);

    $ringkasan = app(PanelNotifikasiService::class)->kirimOtomatis(Carbon::parse('2026-10-06 08:00:00'));

    expect($ringkasan['terkirim'])->toBe(1)
        ->and($ringkasan['gagal'])->toBe(0);

    Http::assertSent(function ($request) {
        $teks = (string) $request['text'];

        return $request['chat_id'] === '555000111'
            && str_contains($teks, 'Ruang Panel Pengingat')
            && str_contains($teks, '31,50 C')
            && str_contains($teks, 'TERLALU PANAS')
            && str_contains($teks, 'https://oleomtc.com/panel/ruangan/');
    });

    // Panggilan kedua pada hari yang sama tidak boleh mengirim lagi.
    $ulang = app(PanelNotifikasiService::class)->kirimOtomatis(Carbon::parse('2026-10-06 08:05:00'));

    expect($ulang['terkirim'])->toBe(0);
    Http::assertSentCount(1);
});

test('teknisi tanpa Telegram ID dan hari OFF tidak dikirimi pesan', function () {
    $tanpaTelegram = Technician::query()->create([
        'name' => 'PIC Tanpa Telegram', 'nik' => '920000004', 'section' => 'ei',
        'group' => 'reguler', 'status' => 'active',
    ]);

    $shiftOff = Technician::query()->create([
        'name' => 'PIC Shift OFF', 'nik' => '920000005', 'section' => 'mekanik',
        'group' => 'grub_c', 'status' => 'active', 'telegram_id' => '555000222',
    ]);

    ruanganDenganPic($tanpaTelegram);
    ruanganDenganPic($shiftOff);

    // Grub C sedang OFF pada 06/10/2026, sehingga hanya teknisi reguler yang
    // masuk jendela jam kerja - dan dia belum punya Telegram ID.
    $ringkasan = app(PanelNotifikasiService::class)->kirimOtomatis(Carbon::parse('2026-10-06 08:00:00'));

    expect($ringkasan['terkirim'])->toBe(0)
        ->and($ringkasan['dilewati'])->toBe(2);

    Http::assertNothingSent();
});

test('uji kirim memaksa pengiriman walau di luar jam kerja dan tidak mencatat riwayat', function () {
    $teknisi = Technician::query()->create([
        'name' => 'PIC Uji Kirim', 'nik' => '920000006', 'section' => 'mekanik',
        'group' => 'grub_c', 'status' => 'active', 'telegram_id' => '555000333',
    ]);

    ruanganDenganPic($teknisi, 17.2);

    $notifikasi = app(PanelNotifikasiService::class);
    $paksa      = $notifikasi->kirimPaksa($teknisi, Carbon::parse('2026-10-06 13:30:00'));

    expect($paksa['terkirim'])->toBe(1)
        ->and($paksa['detail'][0]['ruangan'])->toBe(1);

    Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'TERLALU DINGIN'));

    // Karena tidak dicatat, pengiriman berikutnya tetap jalan.
    $lagi = $notifikasi->kirimPaksa($teknisi, Carbon::parse('2026-10-06 13:35:00'));

    expect($lagi['terkirim'])->toBe(1);
});

test('perintah panel:kirim-pengingat berjalan dan mengirim pengingat uji', function () {
    $teknisi = Technician::query()->create([
        'name' => 'PIC Perintah', 'nik' => '920000007', 'section' => 'mekanik',
        'group' => 'reguler', 'status' => 'active', 'telegram_id' => '555000444',
    ]);

    ruanganDenganPic($teknisi);

    $this->artisan('panel:kirim-pengingat --uji-kirim')->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'Ruang Panel Pengingat'));
});

test('tombol kirim pengingat pada halaman detail hanya mengirim ke PIC ruangan itu', function () {
    $pic = Technician::query()->create([
        'name' => 'PIC Ruangan Ini', 'nik' => '920000008', 'section' => 'mekanik',
        'group' => 'reguler', 'status' => 'active', 'telegram_id' => '555000555',
    ]);

    $lainnya = Technician::query()->create([
        'name' => 'PIC Ruangan Lain', 'nik' => '920000009', 'section' => 'ei',
        'group' => 'reguler', 'status' => 'active', 'telegram_id' => '555000666',
    ]);

    $ruangan = ruanganDenganPic($pic);
    ruanganDenganPic($lainnya);

    $this->actingAs($this->admin)
        ->post('/panel/ruangan/' . $ruangan->id . '/kirim-pengingat')
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => (string) $request['chat_id'] === '555000555');
});

