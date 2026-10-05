<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\CollaborationService;
use App\Services\Shift\ManHourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Kolaborasi teknisi dari halaman edit web.
 *
 * Aturan yang dijaga di sini:
 *   1. Menambah kolaborator membuat baris salinan laporan tersendiri
 *      (collaborator_of menunjuk laporan induk) dengan detail pekerjaan yang
 *      SAMA seperti induk, tetapi kode laporan baru dan teknisi berbeda.
 *   2. Pelaksana laporan sendiri, teknisi yang sudah terdaftar, dan penambahan
 *      melebihi batas CollaborationService::MAX_KOLABORATOR ditolak.
 *   3. Menghapus kolaborator hanya menghapus baris salinannya, laporan induk
 *      tetap utuh.
 *   4. Laporan salinan (kolaborasi/multi alat) tidak boleh menambah kolaborator
 *      sendiri; semuanya dikelola dari laporan induk.
 *   5. Salinan kolaborasi ikut terhitung pada man hour teknisi kolaborator
 *      (perilaku yang sama dengan salinan buatan wizard Telegram).
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->pelapor = Technician::create([
        'name'   => 'Pelapor Kolaborasi Uji',
        'nik'    => 'KOL001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();

    $this->induk = Report::create([
        'technician_id'         => $this->pelapor->id,
        'creator_id'            => $this->pelapor->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'KOLAB-INDUK-01',
        'work_description'      => 'Perbaikan pompa kolaborasi dari halaman edit',
        'work_duration_minutes' => 120,
        'start_time'            => '08:00',
        'end_time'              => '10:00',
        'jenis_pekerjaan'       => 'CM',
        'root_cause'            => 'Bearing aus',
        'finding'               => 'Ada kebocoran kecil di seal',
        'status'                => 'completed',
        'completed_at'          => now(),
    ]);
});

// Helper pembuat teknisi uji kolaborator.
function teknisiKolaborasi(string $nama, string $nik, string $grup = 'reguler'): Technician
{
    return Technician::create([
        'name'   => $nama,
        'nik'    => $nik,
        'group'  => $grup,
        'status' => 'active',
    ]);
}

test('halaman edit menampilkan bagian kolaborasi', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->induk->id . '/edit')
        ->assertStatus(200)
        ->assertSee('Kolaborasi Teknisi', false)
        ->assertSee('Tambah Kolaborator', false)
        ->assertSee('kolaborator_tambah', false);
});

test('menambah kolaborator membuat baris salinan dengan detail pekerjaan yang sama', function () {
    $kolaborator = teknisiKolaborasi('Kolaborator Satu', 'KOL101');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kolaborator->id])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('kolaborator.0.name', 'Kolaborator Satu');

    $salinan = Report::where('collaborator_of', $this->induk->id)->firstOrFail();

    expect($salinan->technician_id)->toBe($kolaborator->id)
        ->and($salinan->creator_id)->toBe($this->pelapor->id)
        ->and($salinan->work_description)->toBe($this->induk->work_description)
        ->and($salinan->work_duration_minutes)->toBe(120)
        ->and($salinan->start_time)->toBe('08:00')
        ->and($salinan->end_time)->toBe('10:00')
        ->and($salinan->root_cause)->toBe('Bearing aus')
        ->and($salinan->finding)->toBe('Ada kebocoran kecil di seal')
        ->and($salinan->jenis_pekerjaan)->toBe('CM')
        ->and($salinan->report_date->toDateString())->toBe($this->tanggal)
        ->and($salinan->status)->toBe('completed')
        // Kode laporan sendiri, bukan menyalin kode induk.
        ->and($salinan->report_code)->not->toBe('KOLAB-INDUK-01')
        ->and($salinan->report_code)->not->toBeNull()
        // Bukan baris salinan alat tambahan.
        ->and($salinan->multi_of)->toBeNull();
});

test('kolaborator yang sudah terdaftar dan pelaksana sendiri ditolak', function () {
    $kolaborator = teknisiKolaborasi('Kolaborator Kembar', 'KOL102');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kolaborator->id])
        ->assertStatus(200);

    // Ditambahkan dua kali: permintaan kedua ditolak tanpa menambah baris.
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kolaborator->id])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Report::where('collaborator_of', $this->induk->id)->count())->toBe(1);

    // Pelaksana laporan sendiri tidak boleh menjadi kolaborator.
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $this->pelapor->id])
        ->assertStatus(422);

    expect(Report::where('collaborator_of', $this->induk->id)->count())->toBe(1);
});

test('penambahan kolaborator dibatasi lima orang', function () {
    foreach (range(1, CollaborationService::MAX_KOLABORATOR) as $nomor) {
        $orang = teknisiKolaborasi('Kolaborator ' . $nomor, 'KOL2' . str_pad((string) $nomor, 2, '0', STR_PAD_LEFT));

        $this->actingAs($this->admin)
            ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $orang->id])
            ->assertStatus(200);
    }

    $kelebihan = teknisiKolaborasi('Kolaborator Kelebihan', 'KOL299');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kelebihan->id])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Report::where('collaborator_of', $this->induk->id)->count())->toBe(CollaborationService::MAX_KOLABORATOR);
});

test('menghapus kolaborator hanya menghapus baris salinannya', function () {
    $kolaborator = teknisiKolaborasi('Kolaborator Hapus', 'KOL301');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kolaborator->id])
        ->assertStatus(200);

    $salinan = Report::where('collaborator_of', $this->induk->id)->firstOrFail();

    $this->actingAs($this->admin)
        ->deleteJson('/reports/' . $this->induk->id . '/collaborators/' . $salinan->id)
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('kolaborator', []);

    expect(Report::find($salinan->id))->toBeNull()
        ->and(Report::find($this->induk->id))->not->toBeNull();
});

test('menghapus baris yang bukan salinan laporan ini ditolak', function () {
    $lain = Report::create([
        'technician_id'         => $this->pelapor->id,
        'creator_id'            => $this->pelapor->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'KOLAB-LAIN-01',
        'work_description'      => 'Laporan lain di hari yang sama',
        'work_duration_minutes' => 30,
        'status'                => 'completed',
    ]);

    $this->actingAs($this->admin)
        ->deleteJson('/reports/' . $this->induk->id . '/collaborators/' . $lain->id)
        ->assertStatus(422);

    expect(Report::find($lain->id))->not->toBeNull();
});

test('laporan salinan tidak bisa menambah kolaborator sendiri', function () {
    $kolaborator = teknisiKolaborasi('Kolaborator Salinan', 'KOL401');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kolaborator->id])
        ->assertStatus(200);

    $salinan = Report::where('collaborator_of', $this->induk->id)->firstOrFail();
    $baru    = teknisiKolaborasi('Teknisi Lain', 'KOL402');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $salinan->id . '/collaborators', ['technician_id' => $baru->id])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Report::where('collaborator_of', $this->induk->id)->count())->toBe(1);
});

test('salinan kolaborasi ikut terhitung pada man hour teknisi kolaborator', function () {
    $kolaborator = teknisiKolaborasi('Kolaborator Man Hour', 'KOL501');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kolaborator->id])
        ->assertStatus(200);

    $harian = collect(app(ManHourService::class)->harian(
        $kolaborator,
        Carbon::parse($this->tanggal),
        Carbon::parse($this->tanggal)
    ))->first();

    expect($harian['jumlah_pekerjaan'])->toBe(1)
        ->and($harian['menit'])->toBe(120);
});

test('galat validasi endpoint kolaborator dibalas JSON 422, bukan memutar halaman', function () {
    // Pemanggil halaman edit memakai fetch, jadi galat validasi pun harus JSON
    // (lihat pengecualian shouldRenderJsonWhen pada bootstrap/app.php).
    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('technician_id');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('technician_id');
});

test('halaman detail laporan menyediakan tautan ke halaman edit lengkap', function () {
    // Daftar laporan hanya membuka halaman detail, jadi tautan pada halaman
    // detail inilah jalan masuk ke pengelolaan kolaborator, alat/lokasi
    // tambahan, dan foto (lihat partial _tombol-edit-lengkap).
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->induk->id)
        ->assertStatus(200)
        ->assertSee('Edit Lengkap', false)
        ->assertSee('/reports/' . $this->induk->id . '/edit', false);
});

test('laporan salinan kolaborasi menautkan halaman edit INDUKNYA', function () {
    $kolaborator = teknisiKolaborasi('Rekan Salinan Uji', 'KOL010');

    $this->actingAs($this->admin)
        ->postJson('/reports/' . $this->induk->id . '/collaborators', ['technician_id' => $kolaborator->id])
        ->assertStatus(200);

    $salinan = Report::where('collaborator_of', $this->induk->id)->firstOrFail();

    $this->actingAs($this->admin)
        ->get('/reports/' . $salinan->id)
        ->assertStatus(200)
        // Halaman edit yang ditautkan harus milik laporan INDUK, bukan salinan.
        ->assertSee('/reports/' . $this->induk->id . '/edit', false)
        ->assertDontSee('/reports/' . $salinan->id . '/edit', false);
});
