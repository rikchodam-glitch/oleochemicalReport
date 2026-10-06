<?php

use App\Models\PanelPembacaan;
use App\Models\PanelRuangan;
use App\Models\User;
use App\Services\ItPingService;
use App\Services\PanelPerangkatReader;
use App\Services\PanelSensorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pembaca perangkat tiruan: tidak pernah membuka soket, hanya mengembalikan
 * jawaban yang disiapkan pengujian. Dipakai supaya pengujian tidak bergantung
 * pada perangkat ESP32 yang benar-benar terpasang di jaringan.
 */
class PembacaPanelTiruan extends PanelPerangkatReader
{
    /** @var array<int, string> Urutan pemanggilan: http, tcp, html. */
    public array $panggilan = [];

    /** @var array<string, array<string, mixed>|null> Jawaban HTTP per potongan URL. */
    public array $jawabanHttp = [];

    /** @var array<string, mixed>|null Jawaban port TCP JSON. */
    public ?array $jawabanTcp = null;

    /** @var array<string, mixed>|null Jawaban dashboard HTML. */
    public ?array $jawabanHtml = null;

    public function bacaJsonHttp(string $url): ?array
    {
        $this->panggilan[] = 'http';

        foreach ($this->jawabanHttp as $kunci => $nilai) {
            if (str_contains($url, $kunci)) {
                return $nilai;
            }
        }

        return null;
    }

    public function bacaJsonTcp(string $ip, int $port): ?array
    {
        $this->panggilan[] = 'tcp';

        return $this->jawabanTcp;
    }

    public function bacaDashboardHtml(string $ip, int $port): ?array
    {
        $this->panggilan[] = 'html';

        return $this->jawabanHtml;
    }
}

/**
 * Ping tiruan supaya pengujian tidak menunggu proses ping sistem operasi.
 */
class PingPanelTiruan extends ItPingService
{
    public function ping(string $ip): array
    {
        return ['status' => 'online', 'latency_ms' => 1];
    }
}

/**
 * Balasan khas sketch dashboard (satu baris JSON dari port TCP).
 *
 * @param  float  $suhu
 * @param  float  $kelembapan
 * @param  int  $uptime
 * @return array<string, mixed>
 */
function balasanSketchPanel(float $suhu, float $kelembapan, int $uptime = 222): array
{
    return [
        'device'      => 'ESP32_W5500',
        'ip'          => '192.168.41.240',
        'temperature' => $suhu,
        'humidity'    => $kelembapan,
        'uptime_sec'  => $uptime,
    ];
}

beforeEach(function () {
    $this->seed();

    $this->pembaca = new PembacaPanelTiruan();
    $this->app->instance(PanelPerangkatReader::class, $this->pembaca);
    $this->app->instance(ItPingService::class, new PingPanelTiruan());

    $this->sensor = app(PanelSensorService::class);
    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->ruangan = PanelRuangan::query()->create([
        'nama_ruangan'      => 'Ruang Panel Uji',
        'pt_location'       => 'EPE',
        'plant'             => '6010',
        'ip_address'        => '192.168.41.240',
        'port'              => 80,
        'protokol'          => 'otomatis',
        'aktif'             => true,
        'ambang_suhu_atas'  => 30,
        'ambang_suhu_bawah' => 18,
    ]);
});

// ============================================================
// PROTOKOL SKETCH DASHBOARD (JSON TCP PORT 5000 / DASHBOARD HTML PORT 80)
// ============================================================

test('protokol otomatis memakai JSON TCP saat JSON API firmware tidak menjawab', function () {
    $this->pembaca->jawabanTcp = balasanSketchPanel(27.5, 64.0);

    $hasil = $this->sensor->tarikPembacaan($this->ruangan);

    expect($hasil['berhasil'])->toBeTrue()
        ->and($hasil['baru'])->toBe(1)
        ->and($this->pembaca->panggilan)->toBe(['http', 'tcp']);

    $baris = PanelPembacaan::query()->firstOrFail();

    expect((float) $baris->suhu)->toBe(27.5)
        ->and((float) $baris->kelembapan)->toBe(64.0)
        ->and($baris->sesi_boot)->toBe('sk')
        ->and($baris->waktu->year)->toBe((int) now()->year)
        ->and($this->ruangan->fresh()->status_terakhir)->toBe('online');
});

test('nilai nol dari sketch tidak disimpan karena sensor belum terpasang', function () {
    $this->pembaca->jawabanTcp = balasanSketchPanel(0.0, 0.0);

    $hasil = $this->sensor->tarikPembacaan($this->ruangan);

    expect($hasil['berhasil'])->toBeTrue()
        ->and($hasil['baru'])->toBe(0)
        ->and(PanelPembacaan::query()->count())->toBe(0);

    $ruangan = $this->ruangan->fresh();

    expect($ruangan->status_terakhir)->toBe('online')
        ->and($ruangan->catatan)->toContain('sensor belum mengirim pembacaan');
});

test('nilai yang sama dalam jeda penyimpanan tidak disimpan berulang', function () {
    $this->pembaca->jawabanTcp = balasanSketchPanel(27.5, 64.0);

    $this->sensor->tarikPembacaan($this->ruangan);
    $kedua = $this->sensor->tarikPembacaan($this->ruangan->fresh());

    expect($kedua['berhasil'])->toBeTrue()
        ->and($kedua['baru'])->toBe(0)
        ->and(PanelPembacaan::query()->count())->toBe(1);
});

test('nilai yang berubah selalu disimpan walau belum lewat jeda', function () {
    $this->pembaca->jawabanTcp = balasanSketchPanel(27.5, 64.0);
    $this->sensor->tarikPembacaan($this->ruangan);

    $this->pembaca->jawabanTcp = balasanSketchPanel(29.25, 63.5);

    $kedua = $this->sensor->tarikPembacaan($this->ruangan->fresh());

    expect($kedua['baru'])->toBe(1)
        ->and(PanelPembacaan::query()->count())->toBe(2)
        ->and((float) PanelPembacaan::query()->orderByDesc('id')->firstOrFail()->suhu)->toBe(29.25);
});

test('protokol tcp-json hanya memakai port TCP tanpa memanggil JSON API', function () {
    $this->ruangan->update(['protokol' => 'tcp-json']);
    $this->pembaca->jawabanTcp = balasanSketchPanel(28.0, 60.0);

    $this->sensor->tarikPembacaan($this->ruangan->fresh());

    expect($this->pembaca->panggilan)->toBe(['tcp']);
});

test('protokol html membaca angka dashboard dan menyimpannya', function () {
    $this->ruangan->update(['protokol' => 'html']);
    $this->pembaca->jawabanHtml = [
        'device'      => 'dashboard-html',
        'ip'          => '192.168.41.240',
        'temperature' => 30.5,
        'humidity'    => 58.0,
        'uptime_sec'  => null,
    ];

    $hasil = $this->sensor->tarikPembacaan($this->ruangan->fresh());

    expect($hasil['baru'])->toBe(1)
        ->and($this->pembaca->panggilan)->toBe(['html'])
        ->and((float) PanelPembacaan::query()->firstOrFail()->suhu)->toBe(30.5);
});

test('protokol api-json tetap memakai buffer perangkat seperti semula', function () {
    $this->pembaca->jawabanHttp = [
        'api/status' => [
            'boot'             => 'A1B2C3',
            'uptime_s'         => 100,
            'mac'              => '02:00:5A:41:00:01',
            'w5500_terdeteksi' => true,
        ],
        'api/buffer' => [
            'data' => [
                ['id' => 7, 'uptime_s' => 96, 'suhu' => 26.5, 'kelembapan' => 61.0, 'valid' => true],
            ],
        ],
    ];

    $hasil = $this->sensor->tarikPembacaan($this->ruangan);

    expect($hasil['baru'])->toBe(1);

    $baris = PanelPembacaan::query()->firstOrFail();

    expect($baris->sesi_boot)->toBe('A1B2C3')
        ->and($baris->id_perangkat)->toBe(7)
        ->and($this->ruangan->fresh()->mac_address)->toBe('02:00:5A:41:00:01');
});


// ============================================================
// TOMBOL UJI KONEKSI DAN PENYIMPANAN PROTOKOL DARI HALAMAN PANEL
// ============================================================

test('uji koneksi perangkat sketch melaporkan sensor belum membaca saat nilai nol', function () {
    $this->pembaca->jawabanTcp = balasanSketchPanel(0.0, 0.0);

    $this->actingAs($this->admin)
        ->post('/panel/ruangan/' . $this->ruangan->id . '/uji-koneksi')
        ->assertOk()
        ->assertJsonPath('protokol', 'otomatis')
        ->assertJsonPath('sumber', 'tcp-json')
        ->assertJsonPath('pembacaan.sensor_membaca', false)
        ->assertJsonPath('pesan', fn ($pesan) => str_contains($pesan, 'sensor belum mengirim pembacaan'));
});

test('uji koneksi perangkat sketch melaporkan perangkat sehat saat ada pembacaan', function () {
    $this->pembaca->jawabanTcp = balasanSketchPanel(27.5, 64.0);

    $this->actingAs($this->admin)
        ->post('/panel/ruangan/' . $this->ruangan->id . '/uji-koneksi')
        ->assertOk()
        ->assertJsonPath('sumber', 'tcp-json')
        ->assertJsonPath('pembacaan.sensor_membaca', true)
        ->assertJsonPath('pesan', fn ($pesan) => str_contains($pesan, 'Perangkat sehat lewat tcp-json'));
});

test('halaman panel menyajikan protokol tiap ruangan untuk polling', function () {
    $this->ruangan->update(['protokol' => 'tcp-json']);
    $this->pembaca->jawabanTcp = balasanSketchPanel(27.5, 64.0);

    $this->actingAs($this->admin)
        ->getJson('/panel/data')
        ->assertOk()
        ->assertJsonPath('ruangan.0.protokol', 'tcp-json')
        ->assertJsonPath('ruangan.0.protokol_label', 'JSON TCP sketch dashboard (port 5000)');
});

test('ruangan panel dapat disimpan dengan protokol tcp-json', function () {
    $this->actingAs($this->admin)
        ->post('/panel/ruangan', [
            'nama_ruangan'      => 'Ruang Panel Server',
            'ip_address'        => '192.168.41.240',
            'port'              => 80,
            'protokol'          => 'tcp-json',
            'ambang_suhu_atas'  => 32,
            'ambang_suhu_bawah' => 18,
            'aktif'             => 1,
        ])
        ->assertRedirect(route('panel.index'));

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Server')->firstOrFail();

    expect($ruangan->protokol)->toBe('tcp-json');
});

test('protokol yang tidak dikenal ditolak validasi', function () {
    $this->actingAs($this->admin)
        ->post('/panel/ruangan', [
            'nama_ruangan'      => 'Ruang Panel Salah Protokol',
            'ip_address'        => '192.168.41.240',
            'port'              => 80,
            'protokol'          => 'protokol-ngawur',
            'ambang_suhu_atas'  => 32,
            'ambang_suhu_bawah' => 18,
        ])
        ->assertSessionHasErrors('protokol');

    expect(PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Salah Protokol')->exists())
        ->toBeFalse();
});


test('form ruangan pada halaman panel memuat pilihan cara baca perangkat', function () {
    $this->actingAs($this->admin)
        ->get('/panel')
        ->assertOk()
        ->assertSee('Cara baca perangkat')
        ->assertSee('name="protokol"', false)
        ->assertSee('Otomatis (deteksi sendiri)', false)
        ->assertSee('JSON TCP sketch dashboard (port 5000)', false)
        ->assertSee('Dashboard HTML sketch (port 80)', false);
});

