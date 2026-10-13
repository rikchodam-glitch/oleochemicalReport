<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Keamanan penghapusan laporan (perbaikan 13/10/2026):
 *   1. Baris salinan (kolaborasi & multi alat) memakai PATH FOTO YANG SAMA
 *      dengan laporan induk, jadi menghapus satu baris salinan TIDAK boleh
 *      menghapus berkas foto milik induk (dulu berkasnya ikut terhapus
 *      sehingga foto laporan induk rusak).
 *   2. Menghapus laporan INDUK wajib ikut menghapus baris salinannya. Kolom
 *      multi_of memakai aturan nullOnDelete di database sehingga tanpa
 *      perbaikan ini salinan alat tambahan berubah menjadi laporan biasa dan
 *      jamnya terhitung dua kali di man hour.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->disk  = config('telegram.photo_disk', 'telegram_photos');
    Storage::fake($this->disk);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Hapus Salinan',
        'nik'    => 'HPS001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);
});

/**
 * Buat laporan INDUK berfoto beserta salinan kolaborasi dan salinan multi alat
 * yang memakai path foto yang sama (seperti di aplikasi).
 *
 * @param  Technician $teknisi Teknisi pemilik laporan
 * @param  string     $disk    Nama disk foto yang dipakai
 * @return array<int, mixed>   [$induk, $kolaborasi, $multi, $pathDokumentasi, $pathHygiene]
 */
function laporanIndukBerfoto(Technician $teknisi, string $disk): array
{
    $dokumentasi = 'reports/uji/dokumentasi-bersama.jpg';
    $hygiene     = 'reports/uji/hygiene-bersama.jpg';

    Storage::disk($disk)->put($dokumentasi, 'isi-foto');
    Storage::disk($disk)->put($hygiene, 'isi-foto');

    $foto = [
        'photo_documentation'     => [$dokumentasi],
        'photo_hygiene_clearance' => [$hygiene],
    ];

    $induk = Report::create(array_merge([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => '2026-09-29',
        'report_code'           => 'HPS-INDUK-01',
        'work_description'      => 'Pekerjaan induk uji hapus',
        'work_duration_minutes' => 300,
        'status'                => 'completed',
    ], $foto));

    $kolaborasi = Report::create(array_merge([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'collaborator_of'       => $induk->id,
        'report_date'           => '2026-09-29',
        'report_code'           => 'HPS-KOLAB-01',
        'work_description'      => 'Salinan kolaborasi uji hapus',
        'work_duration_minutes' => 300,
        'status'                => 'completed',
    ], $foto));

    $multi = Report::create(array_merge([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'multi_of'              => $induk->id,
        'report_date'           => '2026-09-29',
        'report_code'           => 'HPS-MULTI-01',
        'work_description'      => 'Salinan multi alat uji hapus',
        'work_duration_minutes' => 300,
        'status'                => 'completed',
    ], $foto));

    return [$induk, $kolaborasi, $multi, $dokumentasi, $hygiene];
}

test('menghapus baris salinan kolaborasi tidak menghapus foto laporan induk', function () {
    [$induk, $kolaborasi, $multi, $dokumentasi, $hygiene] = laporanIndukBerfoto($this->teknisi, $this->disk);

    $kolaborasi->delete();

    expect(Report::find($kolaborasi->id))->toBeNull()
        ->and($induk->fresh()->photo_documentation)->toContain($dokumentasi);

    // Berkas foto tetap ada karena laporan induk (dan salinan multi alat)
    // memakai path yang sama.
    Storage::disk($this->disk)->assertExists($dokumentasi);
    Storage::disk($this->disk)->assertExists($hygiene);

    // Salinan multi alat bukan milik baris kolaborasi, jadi tidak ikut terhapus.
    expect(Report::find($multi->id))->not->toBeNull();
});

test('menghapus baris salinan tetap membersihkan berkas foto miliknya sendiri', function () {
    [$induk, $kolaborasi, $multi, $dokumentasi] = laporanIndukBerfoto($this->teknisi, $this->disk);

    // Salinan punya foto KHUSUS yang tidak dipakai laporan lain.
    $khusus = 'reports/uji/foto-khusus-salinan.jpg';
    Storage::disk($this->disk)->put($khusus, 'isi-foto');
    $kolaborasi->update(['photo_documentation' => [$khusus]]);

    $kolaborasi->delete();

    Storage::disk($this->disk)->assertMissing($khusus);
    Storage::disk($this->disk)->assertExists($dokumentasi);
});

test('menghapus laporan induk ikut menghapus salinan dan berkas fotonya', function () {
    [$induk, $kolaborasi, $multi, $dokumentasi, $hygiene] = laporanIndukBerfoto($this->teknisi, $this->disk);

    $induk->delete();

    expect(Report::find($induk->id))->toBeNull()
        ->and(Report::find($kolaborasi->id))->toBeNull()
        ->and(Report::find($multi->id))->toBeNull();

    // Tidak ada laporan lain yang memakai foto itu, jadi berkasnya dibersihkan.
    Storage::disk($this->disk)->assertMissing($dokumentasi);
    Storage::disk($this->disk)->assertMissing($hygiene);
});

test('rute hapus laporan membersihkan salinan juga', function () {
    [$induk, $kolaborasi, $multi] = laporanIndukBerfoto($this->teknisi, $this->disk);

    $this->actingAs($this->admin)
        ->delete('/reports/' . $induk->id)
        ->assertRedirect();

    expect(Report::find($induk->id))->toBeNull()
        ->and(Report::find($kolaborasi->id))->toBeNull()
        ->and(Report::find($multi->id))->toBeNull();
});

test('laporan tanpa salinan dan tanpa foto tetap bisa dihapus', function () {
    $laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => '2026-09-29',
        'report_code'           => 'HPS-SEDERHANA-01',
        'work_description'      => 'Laporan tanpa salinan',
        'work_duration_minutes' => 60,
        'status'                => 'completed',
    ]);

    $laporan->delete();

    expect(Report::find($laporan->id))->toBeNull();
});

