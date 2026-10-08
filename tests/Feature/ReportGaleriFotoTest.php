<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Popup galeri foto pada halaman detail laporan (08/10/2026).
 *
 * Aturan yang dijaga di sini:
 *   1. Markup popup galeri hanya dimuat SEKALI per halaman (dipanggil dari
 *      reports.show lewat partial reports.partials._galeri-foto).
 *   2. Foto pada grid kecil membuka popup (bukan tab baru) dan tombol
 *      "Lihat semua" tersedia untuk dokumentasi maupun hygiene.
 *   3. Tombol hapus di dalam popup hanya dirender untuk laporan INDUK, karena
 *      baris salinan memakai berkas foto yang sama.
 *   4. Metode pengendali galeri ada di komponen Alpine reportDetail() supaya
 *      popupnya bisa berpindah foto lewat tombol, panah keyboard, dan geser.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Galeri',
        'nik'    => 'GAL001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->teknisiLain = Technician::create([
        'name'   => 'Teknisi Galeri Dua',
        'nik'    => 'GAL002',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'           => $this->teknisi->id,
        'creator_id'              => $this->teknisi->id,
        'report_date'             => now()->toDateString(),
        'report_code'             => 'RPT-GALERI-1',
        'work_description'        => 'Uji popup galeri foto',
        'work_duration_minutes'   => 60,
        'report_type'             => 'equipment_repair',
        'status'                  => 'completed',
        'photo_documentation'     => ['laporan/uji-1.jpg', 'laporan/uji-2.jpg'],
        'photo_hygiene_clearance' => ['laporan/uji-3.jpg'],
    ]);

    $this->salinan = Report::create([
        'technician_id'           => $this->teknisiLain->id,
        'creator_id'              => $this->teknisi->id,
        'report_date'             => now()->toDateString(),
        'report_code'             => 'RPT-GALERI-2',
        'work_description'        => 'Salinan kolaborasi uji galeri',
        'work_duration_minutes'   => 60,
        'report_type'             => 'equipment_repair',
        'status'                  => 'completed',
        'collaborator_of'         => $this->laporan->id,
        'photo_documentation'     => ['laporan/uji-1.jpg', 'laporan/uji-2.jpg'],
        'photo_hygiene_clearance' => ['laporan/uji-3.jpg'],
    ]);
});

test('halaman detail memuat popup galeri satu kali dengan pemicunya', function () {
    $balasan = $this->actingAs($this->admin)->get('/reports/' . $this->laporan->id);

    $balasan->assertStatus(200)
        // Popup galeri hanya sekali walau dipakai dua bagian foto.
        ->assertSee('x-show="galeri.buka"', false)
        // Grid kecil mengarahkan kliknya ke popup, bukan tab baru.
        ->assertSee("galeriBuka('documentation', idx)", false)
        ->assertSee("galeriBuka('hygiene', idx)", false)
        // Tombol jalan pintas untuk membuka seluruh galeri.
        ->assertSee('Lihat semua', false)
        ->assertSee('Foto Dokumentasi', false)
        ->assertSee('Foto Hygiene', false);

    expect(substr_count($balasan->getContent(), 'x-show="galeri.buka"'))->toBe(1);
});

test('popup galeri memuat pengendali pindah foto, thumbnail, dan penghitung posisi', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        // Penanda memakai bentuk markup (bukan sekadar nama fungsi), supaya
        // perubahan yang memutus sambungan tombol dengan popup ketahuan.
        ->assertSee('@click="galeriSebelum()"', false)
        ->assertSee('@click="galeriBerikut()"', false)
        ->assertSee('@touchstart="galeriGeserMulai($event)"', false)
        ->assertSee('@touchend="galeriGeserSelesai($event)"', false)
        ->assertSee('@click="galeriKe(idx)"', false)
        ->assertSee('x-text="galeriPosisi()"', false)
        ->assertSee(':src="galeriFoto()"', false)
        ->assertSee('photoGroups[galeri.jenis]', false)
        ->assertSee('x-teleport="body"', false);
});

test('tombol hapus foto di popup galeri hanya dirender untuk laporan induk', function () {
    // Tombol di dalam popup memanggil galeriHapus(); itulah penandanya (teks
    // "Hapus foto ini" juga dipakai judul tombol hapus pada grid kecil, jadi
    // tidak dipakai sebagai penanda di sini).
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('@click="galeriHapus()"', false);

    // Baris salinan kolaborasi memakai berkas foto yang sama, jadi tombol
    // hapusnya tidak boleh muncul di sana.
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->salinan->id)
        ->assertStatus(200)
        ->assertSee('x-show="galeri.buka"', false)
        ->assertDontSee('@click="galeriHapus()"', false);
});

test('metode pengendali galeri tersedia pada komponen reportDetail', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('galeri: {', false)
        ->assertSee('galeriBuka(jenis, indeks)', false)
        ->assertSee('galeriTutup()', false)
        ->assertSee('galeriJenisBuka(jenis)', false)
        ->assertSee('async galeriHapus()', false)
        ->assertSee('galeriFoto()', false);
});
