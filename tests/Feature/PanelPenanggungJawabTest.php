<?php

use App\Models\PanelRuangan;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penanggung jawab (PIC) ruang panel: dipilih dari data teknisi, boleh lebih
 * dari satu, dan dipakai pengiriman pengingat harian lewat Telegram.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // Panel ditarik dari perangkat pada permintaan pertama; dimatikan supaya
    // pengujian tidak membuka soket ke perangkat nyata.
    config(['panel.pull.enabled' => false]);

    $this->budi = Technician::query()->create([
        'name'    => 'Budi Penanggung Jawab',
        'nik'     => '910000001',
        'section' => 'mekanik',
        'group'   => 'reguler',
        'status'  => 'active',
    ]);

    $this->sari = Technician::query()->create([
        'name'        => 'Sari Penanggung Jawab',
        'nik'         => '910000002',
        'section'     => 'ei',
        'group'       => 'reguler_staff',
        'status'      => 'active',
        'telegram_id' => '111222333',
    ]);
});

/**
 * Kirim data form ruangan lengkap ke endpoint simpan.
 *
 * @param  array<string, mixed>  $ubah  Nilai yang ingin diganti
 * @return \Illuminate\Testing\TestResponse
 */
function simpanRuanganPic(array $ubah = [])
{
    return test()->actingAs(test()->admin)->post('/panel/ruangan', array_merge([
        'nama_ruangan'      => 'Ruang Panel PIC',
        'ip_address'        => '192.168.41.245',
        'port'              => 80,
        'protokol'          => 'otomatis',
        'ambang_suhu_atas'  => 30,
        'ambang_suhu_bawah' => 18,
        'aktif'             => 1,
    ], $ubah));
}

test('form pengaturan ruangan memuat pilihan penanggung jawab dari data teknisi', function () {
    $this->actingAs($this->admin)
        ->get('/panel/ruangan/create')
        ->assertOk()
        ->assertSee('Penanggung Jawab Panel')
        ->assertSee('name="penanggung_jawab[]"', false)
        ->assertSee('Budi Penanggung Jawab')
        ->assertSee('Sari Penanggung Jawab');
});

test('penanggung jawab tersimpan saat ruangan baru dibuat', function () {
    simpanRuanganPic([
        'penanggung_jawab' => [$this->budi->id, $this->sari->id],
    ])->assertRedirect();

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel PIC')->firstOrFail();

    expect($ruangan->penanggungJawab()->pluck('technicians.id')->sort()->values()->all())
        ->toBe(collect([$this->budi->id, $this->sari->id])->sort()->values()->all())
        ->and($ruangan->namaPenanggungJawab())->toContain('Budi Penanggung Jawab');
});

test('penanggung jawab bisa diganti dan dihapus saat ruangan diubah', function () {
    simpanRuanganPic(['penanggung_jawab' => [$this->budi->id, $this->sari->id]]);

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel PIC')->firstOrFail();

    $this->actingAs($this->admin)
        ->put('/panel/ruangan/' . $ruangan->id, [
            'nama_ruangan'      => 'Ruang Panel PIC',
            'ip_address'        => '192.168.41.245',
            'port'              => 80,
            'protokol'          => 'otomatis',
            'ambang_suhu_atas'  => 30,
            'ambang_suhu_bawah' => 18,
            'aktif'             => 1,
            'penanggung_jawab'  => [$this->sari->id],
        ])
        ->assertRedirect();

    expect($ruangan->fresh()->penanggungJawab()->pluck('technicians.id')->all())
        ->toBe([$this->sari->id]);

    // Mengirim daftar kosong menghapus seluruh penanggung jawab.
    $this->actingAs($this->admin)
        ->put('/panel/ruangan/' . $ruangan->id, [
            'nama_ruangan'      => 'Ruang Panel PIC',
            'ip_address'        => '192.168.41.245',
            'port'              => 80,
            'protokol'          => 'otomatis',
            'ambang_suhu_atas'  => 30,
            'ambang_suhu_bawah' => 18,
            'aktif'             => 1,
        ])
        ->assertRedirect();

    expect($ruangan->fresh()->penanggungJawab()->count())->toBe(0);
});

test('teknisi yang tidak terdaftar ditolak sebagai penanggung jawab', function () {
    simpanRuanganPic(['penanggung_jawab' => [999999]])
        ->assertSessionHasErrors('penanggung_jawab.0');

    expect(PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel PIC')->exists())->toBeFalse();
});

test('halaman detail ruangan menampilkan daftar penanggung jawab', function () {
    simpanRuanganPic(['penanggung_jawab' => [$this->budi->id]]);

    $ruangan = PanelRuangan::query()->where('nama_ruangan', 'Ruang Panel PIC')->firstOrFail();

    $this->actingAs($this->admin)
        ->get('/panel/ruangan/' . $ruangan->id)
        ->assertOk()
        ->assertSee('Penanggung jawab')
        ->assertSee('Budi Penanggung Jawab');
});

test('data dashboard panel memuat nama penanggung jawab tiap ruangan', function () {
    simpanRuanganPic(['penanggung_jawab' => [$this->budi->id]]);

    $this->actingAs($this->admin)
        ->getJson('/panel/data')
        ->assertOk()
        ->assertJsonPath('ruangan.0.penanggung_jawab', 'Budi Penanggung Jawab');
});

test('relasi teknisi ke ruang panel yang ditangani berjalan dua arah', function () {
    simpanRuanganPic(['penanggung_jawab' => [$this->budi->id]]);

    expect($this->budi->fresh()->panelYangDitangani()->pluck('panel_ruangan.nama_ruangan')->all())
        ->toBe(['Ruang Panel PIC']);
});
