<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Unggah & hapus foto pada mode edit halaman DETAIL laporan.
 *
 * Aturan yang dijaga di sini:
 *   1. Halaman detail memuat bagian Foto Laporan (grid + kotak unggah).
 *   2. Unggah mendukung BANYAK berkas sekaligus (`photos[]`) dan tetap
 *      mendukung bentuk lama satu berkas (`photo`).
 *   3. Jenis foto menentukan kolom yang diisi (dokumentasi / hygiene).
 *   4. Unggah tanpa berkas dan berkas bukan gambar ditolak.
 *   5. Baris salinan (kolaborasi/multi alat) TIDAK boleh mengubah foto karena
 *      memakai path foto yang sama dengan laporan induk.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->disk = config('telegram.photo_disk', 'telegram_photos');
    Storage::fake($this->disk);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Foto',
        'nik'    => 'FTO001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'FOTO-LAPORAN-01',
        'work_description'      => 'Pekerjaan uji unggah foto',
        'work_duration_minutes' => 60,
        'status'                => 'completed',
    ]);
});

test('halaman detail menampilkan bagian foto dan kotak unggah', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('Foto Laporan', false)
        ->assertSee('foto_berkas', false)
        ->assertSee('foto_unggah', false)
        ->assertSee('Foto Hygiene Clearance', false);
});

test('unggah beberapa foto sekaligus menambah kolom dokumentasi', function () {
    $berkas = [
        UploadedFile::fake()->image('foto-a.jpg'),
        UploadedFile::fake()->image('foto-b.jpg'),
    ];

    $this->actingAs($this->admin)
        ->post('/reports/' . $this->laporan->id . '/photos', [
            'photos' => $berkas,
            'type'   => 'documentation',
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $tersimpan = $this->laporan->fresh();

    expect($tersimpan->photo_documentation)->toHaveCount(2)
        ->and($tersimpan->photo_hygiene_clearance)->toBeEmpty();

    foreach ($tersimpan->photo_documentation as $path) {
        Storage::disk($this->disk)->assertExists($path);
    }
});

test('unggah foto hygiene mengisi kolom hygiene clearance', function () {
    $this->actingAs($this->admin)
        ->post('/reports/' . $this->laporan->id . '/photos', [
            'photos' => [UploadedFile::fake()->image('higiene.jpg')],
            'type'   => 'hygiene',
        ], ['Accept' => 'application/json'])
        ->assertStatus(200);

    $tersimpan = $this->laporan->fresh();

    expect($tersimpan->photo_hygiene_clearance)->toHaveCount(1)
        ->and($tersimpan->photo_documentation)->toBeEmpty();
});

test('bentuk lama satu berkas bernama photo tetap didukung', function () {
    $this->actingAs($this->admin)
        ->post('/reports/' . $this->laporan->id . '/photos', [
            'photo' => UploadedFile::fake()->image('tunggal.jpg'),
            'type'  => 'documentation',
        ])
        ->assertRedirect();

    expect($this->laporan->fresh()->photo_documentation)->toHaveCount(1);
});

test('unggah tanpa berkas dan berkas bukan gambar ditolak', function () {
    $this->actingAs($this->admin)
        ->post('/reports/' . $this->laporan->id . '/photos', [
            'type' => 'documentation',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->actingAs($this->admin)
        ->post('/reports/' . $this->laporan->id . '/photos', [
            'photos' => [UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')],
            'type'   => 'documentation',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect($this->laporan->fresh()->photo_documentation)->toBeEmpty();
});

test('hapus foto memotong array dan menghapus berkasnya dari penyimpanan', function () {
    $this->actingAs($this->admin)
        ->post('/reports/' . $this->laporan->id . '/photos', [
            'photos' => [
                UploadedFile::fake()->image('satu.jpg'),
                UploadedFile::fake()->image('dua.jpg'),
            ],
            'type' => 'documentation',
        ], ['Accept' => 'application/json'])
        ->assertStatus(200);

    $tersimpan = $this->laporan->fresh();
    $dihapus   = $tersimpan->photo_documentation[0];
    $tersisa   = $tersimpan->photo_documentation[1];

    $this->actingAs($this->admin)
        ->delete('/reports/' . $this->laporan->id . '/photos/0?type=documentation', [], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $sesudah = $this->laporan->fresh();

    expect($sesudah->photo_documentation)->toHaveCount(1)
        ->and($sesudah->photo_documentation[0])->toBe($tersisa);

    Storage::disk($this->disk)->assertMissing($dihapus);
    Storage::disk($this->disk)->assertExists($tersisa);
});

test('baris salinan tidak boleh mengubah foto', function () {
    $salinan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'collaborator_of'       => $this->laporan->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'FOTO-SALINAN-01',
        'work_description'      => 'Salinan kolaborasi',
        'work_duration_minutes' => 60,
        'photo_documentation'   => ['reports/2026/10/05/1/lama.jpg'],
        'status'                => 'completed',
    ]);

    $this->actingAs($this->admin)
        ->post('/reports/' . $salinan->id . '/photos', [
            'photos' => [UploadedFile::fake()->image('baru.jpg')],
            'type'   => 'documentation',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->actingAs($this->admin)
        ->delete('/reports/' . $salinan->id . '/photos/0?type=documentation', [], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect($salinan->fresh()->photo_documentation)->toHaveCount(1);
});
