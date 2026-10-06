<?php

use App\Models\Asset;
use App\Models\Company;
use App\Models\FunctionalLocation;
use App\Models\PanelPembacaan;
use App\Models\PanelRuangan;
use App\Models\User;
use App\Services\ItPingService;
use App\Services\PanelPerangkatReader;
use App\Services\PanelSensorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pembaca perangkat tiruan untuk pengujian pengaturan ruangan: mencatat port
 * yang dipakai sehingga pengujian bisa memastikan port TCP per ruangan benar.
 */
class PembacaPengaturanTiruan extends PanelPerangkatReader
{
    /** @var array<int, int> Port TCP yang dipakai pada tiap pemanggilan. */
    public array $portTcp = [];

    /** @var array<string, mixed>|null Jawaban JSON pada port TCP. */
    public ?array $jawabanTcp = null;

    public function bacaJsonHttp(string $url): ?array
    {
        return null;
    }

    public function bacaJsonTcp(string $ip, int $port): ?array
    {
        $this->portTcp[] = $port;

        return $this->jawabanTcp;
    }

    public function bacaDashboardHtml(string $ip, int $port): ?array
    {
        return null;
    }
}

/**
 * Ping tiruan supaya pengujian tidak menunggu proses ping sistem operasi.
 */
class PingPengaturanTiruan extends ItPingService
{
    public function ping(string $ip): array
    {
        return ['status' => 'online', 'latency_ms' => 2];
    }
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // Seeder sudah menyiapkan master PT EPE; dipakai apa adanya bila ada.
    $this->pt = Company::query()->firstOrCreate(['code' => 'EPE'], ['name' => 'PT EPE']);

    // Dua node lokasi dipakai bergantian supaya pengujian pemindahan lokasi
    // baris aset cermin punya tujuan yang berbeda.
    $this->funcloc = FunctionalLocation::query()->create([
        'code'       => 'EPE-PRD-AR01-SA90',
        'segment'    => 'SA90',
        'name'       => 'Section Panel Uji',
        'level'      => FunctionalLocation::LEVEL_SECTION,
        'company_id' => $this->pt->id,
        'is_active'  => true,
    ]);

    $this->funclocLain = FunctionalLocation::query()->create([
        'code'       => 'EPE-PRD-AR01-SA91',
        'segment'    => 'SA91',
        'name'       => 'Section Panel Uji Lain',
        'level'      => FunctionalLocation::LEVEL_SECTION,
        'company_id' => $this->pt->id,
        'is_active'  => true,
    ]);

    $this->pembaca = new PembacaPengaturanTiruan();
    $this->app->instance(PanelPerangkatReader::class, $this->pembaca);
    $this->app->instance(ItPingService::class, new PingPengaturanTiruan());
});

/**
 * Kirim data form ruangan lengkap ke endpoint simpan.
 *
 * @param  array<string, mixed>  $ubah  Nilai yang ingin diganti
 * @return \Illuminate\Testing\TestResponse
 */
function simpanRuangan(array $ubah = [])
{
    return test()->actingAs(test()->admin)->post('/panel/ruangan', array_merge([
        'nama_ruangan'      => 'Ruang Panel Pengaturan',
        'pt_location'       => 'EPE',
        'plant'             => '6010',
        'keterangan'        => 'Ruangan untuk pengujian pengaturan',
        'ip_address'        => '192.168.41.240',
        'port'              => 80,
        'port_tcp'          => 5000,
        'protokol'          => 'otomatis',
        'ambang_suhu_atas'  => 30,
        'ambang_suhu_bawah' => 18,
        'aktif'             => 1,
        'funcloc_id'        => test()->funcloc->id,
        'kode_alat'         => 'PANEL-EPE-01',
        'equipment_type'    => 'Panel Monitoring',
    ], $ubah));
}

// ============================================================
// FORM, PENYIMPANAN, DAN BARIS ASET CERMIN
// ============================================================

test('form tambah ruangan memuat pilihan sumber data, lokasi, dan kode alat', function () {
    $this->actingAs($this->admin)
        ->get('/panel/ruangan/create')
        ->assertOk()
        ->assertSee('Cara Baca Perangkat (Sumber Data)')
        ->assertSee('Dashboard Arduino (sketsa sendiri)')
        ->assertSee('Firmware proyek (JSON API)')
        ->assertSee('name="protokol"', false)
        ->assertSee('name="port_tcp"', false)
        ->assertSee('name="funcloc_id"', false)
        ->assertSee('name="kode_alat"', false)
        ->assertSee('name="equipment_type"', false)
        ->assertSee('Section Panel Uji');
});

test('ruangan panel baru membuat baris aset cermin lengkap', function () {
    simpanRuangan()->assertRedirect();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    expect($ruangan->funcloc_id)->toBe($this->funcloc->id)
        ->and($ruangan->kode_alat)->toBe('PANEL-EPE-01')
        ->and($ruangan->port_tcp)->toBe(5000)
        ->and($ruangan->equipment_type)->toBe('Panel Monitoring');

    $cermin = Asset::query()->where('panel_ruangan_id', $ruangan->id)->firstOrFail();

    expect($cermin->data_source)->toBe('panel_sync')
        ->and($cermin->tech_ident_no)->toBe('PANEL-EPE-01')
        ->and($cermin->equipment_type)->toBe('Panel Monitoring')
        ->and($cermin->equipment_no)->toHaveLength(8)
        ->and($cermin->funcloc_id)->toBe($this->funcloc->id)
        ->and($cermin->company_id)->toBe($this->pt->id)
        ->and($cermin->has_equipment_no)->toBeTrue()
        ->and($cermin->status)->toBe('active')
        ->and($cermin->description)->toContain('EPE-PRD-AR01-SA90');
});

test('nomor equipment ruangan mengikuti seri nomor PT lokasinya', function () {
    Asset::query()->create([
        'equipment_no'   => '10000007',
        'description'    => 'Aset lama PT EPE',
        'tech_ident_no'  => 'X-EPE-1',
        'company_id'     => $this->pt->id,
        'funcloc_id'     => $this->funcloc->id,
        'functional_loc' => $this->funcloc->code,
        'data_source'    => 'import_excel',
    ]);

    simpanRuangan();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    expect(Asset::query()->where('panel_ruangan_id', $ruangan->id)->value('equipment_no'))
        ->toBe('10000008');
});

test('ruangan tanpa lokasi belum dibuatkan baris aset', function () {
    simpanRuangan(['funcloc_id' => null, 'kode_alat' => null]);

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    expect($ruangan->funcloc_id)->toBeNull()
        ->and(Asset::query()->where('panel_ruangan_id', $ruangan->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->get('/panel/ruangan/' . $ruangan->id)
        ->assertOk()
        ->assertSee('Lokasi (Functional Location) belum dipilih');
});

test('kode alat yang sudah dipakai ruangan lain ditolak', function () {
    simpanRuangan();

    $this->actingAs($this->admin)
        ->post('/panel/ruangan', [
            'nama_ruangan'      => 'Ruang Panel Bentrok',
            'ip_address'        => '192.168.41.241',
            'port'              => 80,
            'protokol'          => 'otomatis',
            'ambang_suhu_atas'  => 30,
            'ambang_suhu_bawah' => 18,
            'funcloc_id'        => $this->funclocLain->id,
            'kode_alat'         => 'PANEL-EPE-01',
        ])
        ->assertSessionHasErrors('kode_alat');

    expect(PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Bentrok')->exists())
        ->toBeFalse();
});

test('kode alat yang sudah dipakai perangkat IT ditolak', function () {
    Asset::query()->create([
        'equipment_no'   => '90000001',
        'description'    => 'Kamera CCTV EPE',
        'tech_ident_no'  => 'CCTV-EPE-99',
        'company_id'     => $this->pt->id,
        'funcloc_id'     => $this->funcloc->id,
        'functional_loc' => $this->funcloc->code,
        'data_source'    => 'it_sync',
    ]);

    $this->actingAs($this->admin)
        ->post('/panel/ruangan', [
            'nama_ruangan'      => 'Ruang Panel Ambil Kode IT',
            'ip_address'        => '192.168.41.242',
            'port'              => 80,
            'protokol'          => 'otomatis',
            'ambang_suhu_atas'  => 30,
            'ambang_suhu_bawah' => 18,
            'funcloc_id'        => $this->funcloc->id,
            'kode_alat'         => 'CCTV-EPE-99',
        ])
        ->assertSessionHasErrors('kode_alat');
});

// ============================================================
// ADOPSI, DETAIL, UBAH, HAPUS, DAN PERINTAH SINKRON
// ============================================================

test('baris aset lama berkode sama diadopsi tanpa menimpa nomor equipment', function () {
    Asset::query()->create([
        'equipment_no'   => '10000055',
        'description'    => 'Panel lama yang dulu terdaftar manual',
        'tech_ident_no'  => 'PANEL-LAMA',
        'company_id'     => $this->pt->id,
        'funcloc_id'     => $this->funcloc->id,
        'functional_loc' => $this->funcloc->code,
        'data_source'    => 'manual',
    ]);

    simpanRuangan(['kode_alat' => 'PANEL-LAMA']);

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();
    $daftar  = Asset::query()->where('tech_ident_no', 'PANEL-LAMA')->get();

    expect($daftar)->toHaveCount(1)
        ->and($daftar->first()->panel_ruangan_id)->toBe($ruangan->id)
        ->and($daftar->first()->data_source)->toBe('panel_sync')
        ->and($daftar->first()->equipment_no)->toBe('10000055');
});

test('halaman detail menampilkan perangkat, lokasi, dan riwayat pembacaan', function () {
    simpanRuangan();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    PanelPembacaan::query()->create([
        'panel_ruangan_id' => $ruangan->id,
        'waktu'            => now()->subMinute(),
        'suhu'             => 26.5,
        'kelembapan'       => 61.25,
        'valid'            => true,
        'sesi_boot'        => 'sk',
        'id_perangkat'     => 1,
        'sumber'           => 'pull',
    ]);

    $this->actingAs($this->admin)
        ->get('/panel/ruangan/' . $ruangan->id)
        ->assertOk()
        ->assertSee('PANEL-EPE-01')
        ->assertSee('EPE-PRD-AR01-SA90')
        ->assertSee('192.168.41.240')
        ->assertSee('Riwayat pembacaan terakhir')
        ->assertSee('26.50')
        ->assertSee('61.25');
});

test('ubah pengaturan memperbarui baris aset cermin tanpa membuat baris baru', function () {
    simpanRuangan();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    $balasan = $this->actingAs($this->admin)
        ->put('/panel/ruangan/' . $ruangan->id, [
            'nama_ruangan'      => 'Ruang Panel Pengaturan',
            'ip_address'        => '192.168.41.240',
            'port'              => 80,
            'port_tcp'          => 5005,
            'protokol'          => 'tcp-json',
            'ambang_suhu_atas'  => 31,
            'ambang_suhu_bawah' => 19,
            'aktif'             => 1,
            'funcloc_id'        => $this->funclocLain->id,
            'kode_alat'         => 'PANEL-EPE-01',
            'equipment_type'    => 'Panel Monitoring Suhu',
        ]);

    // Setelah disimpan, pengguna kembali ke halaman detail ruangan itu.
    $balasan->assertRedirect('/panel/ruangan/' . $ruangan->id);

    $cermin = Asset::query()->where('panel_ruangan_id', $ruangan->id)->get();

    expect($cermin)->toHaveCount(1)
        ->and($cermin->first()->equipment_type)->toBe('Panel Monitoring Suhu')
        ->and($cermin->first()->funcloc_id)->toBe($this->funclocLain->id)
        ->and($cermin->first()->functional_loc)->toBe('EPE-PRD-AR01-SA91')
        ->and($ruangan->refresh()->port_tcp)->toBe(5005)
        ->and($ruangan->protokol)->toBe('tcp-json');
});

test('menghapus ruangan menghapus baris aset cerminnya', function () {
    simpanRuangan();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    expect(Asset::query()->where('panel_ruangan_id', $ruangan->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->delete('/panel/ruangan/' . $ruangan->id)
        ->assertRedirect(route('panel.index'));

    expect(Asset::query()->where('panel_ruangan_id', $ruangan->id)->exists())->toBeFalse()
        ->and(PanelRuangan::query()->whereKey($ruangan->id)->exists())->toBeFalse();
});

test('form ubah ruangan menampilkan nilai yang tersimpan', function () {
    simpanRuangan();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    $this->actingAs($this->admin)
        ->get('/panel/ruangan/' . $ruangan->id . '/edit')
        ->assertOk()
        ->assertSee('PANEL-EPE-01')
        ->assertSee('Simpan Perubahan')
        ->assertSee('value="' . $this->funcloc->id . '"', false)
        ->assertSee('selected', false);
});

test('kartu dashboard panel menautkan pengaturan ke halaman ruangan', function () {
    simpanRuangan();

    $this->actingAs($this->admin)
        ->get('/panel')
        ->assertOk()
        ->assertSee('panel/ruangan', false)
        ->assertSee('/edit', false);
});

test('perintah panel:sync-assets membuat baris cermin dan menghormati dry-run', function () {
    simpanRuangan();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    Asset::query()->where('panel_ruangan_id', $ruangan->id)->delete();

    $this->artisan('panel:sync-assets --dry-run')->assertSuccessful();

    expect(Asset::query()->where('panel_ruangan_id', $ruangan->id)->exists())->toBeFalse();

    $this->artisan('panel:sync-assets')->assertSuccessful();

    expect(Asset::query()->where('panel_ruangan_id', $ruangan->id)->exists())->toBeTrue();
});

test('port JSON TCP per ruangan dipakai saat membaca perangkat sketsa', function () {
    simpanRuangan(['port_tcp' => 5005, 'protokol' => 'tcp-json']);

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel Pengaturan')->firstOrFail();

    $this->pembaca->jawabanTcp = [
        'device'      => 'ESP32_W5500',
        'ip'          => '192.168.41.240',
        'temperature' => 27.5,
        'humidity'    => 64.0,
        'uptime_sec'  => 120,
    ];

    $this->actingAs($this->admin)
        ->post('/panel/ruangan/' . $ruangan->id . '/uji-koneksi')
        ->assertOk()
        ->assertJsonPath('sumber', 'tcp-json');

    expect($this->pembaca->portTcp)->toBe([5005]);
});
