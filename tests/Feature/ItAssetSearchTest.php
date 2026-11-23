<?php

use App\Models\FunctionalLocation;
use App\Models\IpNetwork;
use App\Models\ItAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pencarian halaman IT Assets.
 *
 * Aturan yang dijaga:
 *   1. Multi-kata AND; kolom tag/nama/IP/pabrikan/model/serial/plant/lokasi,
 *      tipe perangkat (kode + label), dan Functional Location ikut dicari.
 *   2. Tag persis/awalan diprioritaskan.
 *   3. Dropdown saran: perangkat, tipe, plant, lokasi, VLAN.
 *   4. Kotak pencarian utama (id searchItAsset) menggantikan input lama;
 *      filter tipe/status/plant/VLAN tetap bisa dipakai bersama kata kunci.
 *   5. Endpoint saran minimal 2 huruf dan hanya untuk pengguna login.
 */

/**
 * Buat satu perangkat IT uji.
 *
 * @param  array<string, mixed>  $kolom  Kolom tambahan/penimpa
 */
function perangkatPencarianUji(array $kolom = []): ItAsset
{
    return ItAsset::create(array_merge([
        'device_type' => 'PC',
        'tag_name' => 'PC-PCARI-01',
        'name' => 'Perangkat uji pencarian',
        'ip_address' => '192.168.41.2',
        'plant' => 'Plant Uji',
        'department' => 'IT',
        'location' => 'Ruang Uji',
        'status' => 'active',
    ], $kolom));
}

/**
 * Posisi URL detail perangkat pada HTML halaman.
 *
 * @param  string  $html  Isi halaman
 * @param  int  $id  ID perangkat
 * @return int|false
 */
function posisiPerangkatPencarianUji(string $html, int $id)
{
    return strpos($html, route('it-assets.show', $id));
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->funcloc = FunctionalLocation::create([
        'code' => 'IT-ZQ-01',
        'segment' => '01',
        'name' => 'Section IT Zq',
        'level' => FunctionalLocation::LEVEL_SECTION,
        'is_active' => true,
    ]);

    $this->pc = perangkatPencarianUji([
        'device_type' => 'PC',
        'tag_name' => 'PC-ZQ-01',
        'name' => 'Komputer Produksi',
        'ip_address' => '192.168.41.2',
        'brand' => 'Dell',
        'model' => 'Optiplex',
        'serial_number' => 'SN-ZQ-1',
        'plant' => 'Zeta Plant',
        'department' => 'Produksi',
        'location' => 'Zeta Office',
        'funcloc_id' => $this->funcloc->id,
    ]);

    $this->cctv = perangkatPencarianUji([
        'device_type' => 'CCTV',
        'tag_name' => 'CCTV-ZQ-01',
        'name' => 'Kamera Area',
        'ip_address' => '192.168.41.10',
        'plant' => 'Plant ZQ',
        'location' => 'Zeta Office',
    ]);

    $this->laptop = perangkatPencarianUji([
        'device_type' => 'LAPTOP',
        'tag_name' => 'NB-ZQ-01',
        'name' => 'Notebook Dinas',
        'ip_address' => '192.168.42.5',
        'plant' => 'Plant ZQ',
    ]);

    $this->switch = perangkatPencarianUji([
        'device_type' => 'SWITCH_MANAGED',
        'tag_name' => 'SW-ZQ-01',
        'name' => 'Switch Inti',
        'ip_address' => '192.168.41.1',
        'plant' => 'Plant ZQ',
    ]);

    IpNetwork::create([
        'vlan' => 41,
        'cidr' => 24,
        'network' => '192.168.41.0',
        'netmask' => '255.255.255.0',
        'broadcast' => '192.168.41.255',
        'name' => 'VLAN ZQ',
    ]);
});

test('pencarian perangkat menjangkau tag nama IP pabrikan model serial plant dan lokasi', function () {
    foreach ([
        'PC-ZQ-01',
        'Komputer Produksi',
        '192.168.41.2',
        'Dell',
        'Optiplex',
        'SN-ZQ-1',
        'Zeta Plant',
        'Zeta Office',
        'Section IT Zq',
    ] as $kunci) {
        $html = $this->actingAs($this->admin)
            ->get('/it-assets?search='.urlencode($kunci))
            ->assertStatus(200)
            ->getContent();

        expect(strpos($html, route('it-assets.show', $this->pc->id)))->not->toBeFalse();
    }

    // Multi-kata AND.
    $this->actingAs($this->admin)
        ->get('/it-assets?search='.urlencode('komputer produksi'))
        ->assertStatus(200)
        ->assertSee(route('it-assets.show', $this->pc->id), false)
        ->assertDontSee(route('it-assets.show', $this->cctv->id), false);

    $this->actingAs($this->admin)
        ->get('/it-assets?search='.urlencode('komputer kamera'))
        ->assertStatus(200)
        ->assertDontSee(route('it-assets.show', $this->pc->id), false)
        ->assertDontSee(route('it-assets.show', $this->cctv->id), false);
});

test('pencarian perangkat mengenali label dan sinonim tipe', function () {
    // "laptop" tidak ada di kolom teks perangkat, tetapi cocok ke device_type
    // LAPTOP.
    $this->actingAs($this->admin)
        ->get('/it-assets?search=laptop')
        ->assertStatus(200)
        ->assertSee(route('it-assets.show', $this->laptop->id), false)
        ->assertDontSee(route('it-assets.show', $this->pc->id), false);

    // "kamera" -> CCTV lewat sinonim.
    $this->actingAs($this->admin)
        ->get('/it-assets?search=kamera')
        ->assertStatus(200)
        ->assertSee(route('it-assets.show', $this->cctv->id), false);

    // Dua kata pada label "Switch Managed".
    $this->actingAs($this->admin)
        ->get('/it-assets?search='.urlencode('switch managed'))
        ->assertStatus(200)
        ->assertSee(route('it-assets.show', $this->switch->id), false)
        ->assertDontSee(route('it-assets.show', $this->pc->id), false);
});

test('hasil pencarian perangkat mendahulukan tag yang cocok persis', function () {
    $tagPersis = perangkatPencarianUji([
        'device_type' => 'PC',
        'tag_name' => 'ZQ-RANK-99',
        'name' => 'Perangkat lama',
        'ip_address' => '192.168.43.2',
    ]);

    $sebutDiNama = perangkatPencarianUji([
        'device_type' => 'PC',
        'tag_name' => 'ZQ-LAIN-01',
        'name' => 'Perangkat baru yang menyebut ZQ-RANK-99 di nama',
        'ip_address' => '192.168.43.3',
    ]);

    $html = $this->actingAs($this->admin)
        ->get('/it-assets?search=ZQ-RANK-99')
        ->assertStatus(200)
        ->getContent();

    $posisiTag = posisiPerangkatPencarianUji($html, $tagPersis->id);
    $posisiNama = posisiPerangkatPencarianUji($html, $sebutDiNama->id);

    expect($posisiTag)->not->toBeFalse()
        ->and($posisiNama)->not->toBeFalse()
        ->and($posisiTag)->toBeLessThan($posisiNama);
});

test('endpoint saran perangkat mengembalikan kelompok perangkat plant dan lokasi', function () {
    $respons = $this->actingAs($this->admin)
        ->getJson('/it-assets/cari?q=Zeta');

    $respons->assertStatus(200)
        ->assertJsonPath('kata_kunci', 'Zeta')
        ->assertJsonStructure([
            'kata_kunci',
            'total',
            'kelompok' => [
                '*' => ['tipe', 'label', 'jumlah', 'items'],
            ],
        ]);

    $data = $respons->json();
    $tipes = array_column($data['kelompok'], 'tipe');

    expect($data['total'])->toBeGreaterThanOrEqual(1)
        ->and($tipes)->toContain('perangkat')
        ->and($tipes)->toContain('plant')
        ->and($tipes)->toContain('lokasi');

    $grupPerangkat = collect($data['kelompok'])->firstWhere('tipe', 'perangkat');

    expect(array_column($grupPerangkat['items'], 'url'))->toContain(route('it-assets.show', $this->pc->id))
        ->and(count($grupPerangkat['items']))->toBeLessThanOrEqual(5);
});

test('endpoint saran perangkat mengembalikan tipe dan VLAN', function () {
    $tipe = $this->actingAs($this->admin)
        ->getJson('/it-assets/cari?q=kamera')
        ->assertStatus(200)
        ->json();

    expect(array_column($tipe['kelompok'], 'tipe'))->toContain('tipe');

    $vlan = $this->actingAs($this->admin)
        ->getJson('/it-assets/cari?q=41')
        ->assertStatus(200)
        ->json();

    expect(array_column($vlan['kelompok'], 'tipe'))->toContain('vlan');

    $grupVlan = collect($vlan['kelompok'])->firstWhere('tipe', 'vlan');

    expect(array_column($grupVlan['items'], 'url'))->toContain(route('it-assets.index', ['vlan' => 41]));
});

test('endpoint saran perangkat mengabaikan kata kunci kurang dari dua huruf', function () {
    $this->actingAs($this->admin)
        ->getJson('/it-assets/cari?q=Z')
        ->assertStatus(200)
        ->assertJsonPath('total', 0)
        ->assertJsonPath('kelompok', []);
});

test('tamu tidak bisa memakai endpoint saran perangkat', function () {
    $this->get('/it-assets/cari?q=kamera')
        ->assertRedirect('/login');
});

test('kotak pencarian perangkat di atas halaman dan filter tetap bisa dipakai bersama', function () {
    $this->actingAs($this->admin)
        ->get('/it-assets')
        ->assertStatus(200)
        ->assertSee('id="searchItAsset"', false)
        ->assertSee('name="search"', false)
        ->assertDontSee('placeholder="Tag, nama, atau IP..."', false);

    // Kata kunci + filter tipe harus berlaku bersama.
    $this->actingAs($this->admin)
        ->get('/it-assets?search=kamera&device_type=CCTV')
        ->assertStatus(200)
        ->assertSee(route('it-assets.show', $this->cctv->id), false)
        ->assertDontSee(route('it-assets.show', $this->pc->id), false);
});

test('mode layout CCTV tetap menampilkan hasil pencarian', function () {
    // Kartu layout hanya menampilkan tag + IP (nama tidak dirender), jadi
    // acuan hasil adalah tag CCTV.
    $this->actingAs($this->admin)
        ->get('/it-assets?search=kamera&device_type=CCTV')
        ->assertStatus(200)
        ->assertSee('CCTV-ZQ-01');
});
