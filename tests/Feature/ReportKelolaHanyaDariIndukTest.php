<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\ReportCopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pengelolaan laporan hanya boleh dari laporan INDUK (perbaikan 14/10/2026).
 *
 * Latar: baris salinan (kolaborasi teknisi `collaborator_of` maupun alat/lokasi
 * tambahan `multi_of`) dulu bisa dibuka lalu diedit atau dihapus sendiri-sendiri
 * dari halaman detailnya. Akibatnya satu pekerjaan bisa berbeda isinya antar
 * baris, dan menghapus satu baris salinan memecah satu pekerjaan menjadi
 * beberapa laporan lepas.
 *
 * Aturan yang dijaga di berkas ini:
 *   1. Edit (PUT) dan hapus (DELETE) baris salinan DITOLAK dengan pesan yang
 *      mengarahkan ke laporan induk (ReportController::penjagaKelolaInduk()).
 *   2. Perubahan laporan INDUK disebarkan ke SELURUH baris salinannya
 *      (ReportCopyService::sinkronkanSalinan()).
 *   3. Identitas tiap baris salinan tetap: teknisi kolaborator, kode laporan,
 *      serta lokasi/alat item tambahan.
 *   4. Halaman baris salinan menampilkan popup penolakan + tombol "Cek Laporan
 *      Induk", dan TIDAK memuat tombol hapus baris itu.
 *   5. Menghapus laporan induk tetap menghapus seluruh baris salinannya.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->pelapor = Technician::create([
        'name'   => 'Pelapor Kelola Induk',
        'nik'    => 'RKI001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->rekan = Technician::create([
        'name'   => 'Rekan Kelola Induk',
        'nik'    => 'RKI002',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();

    $this->areaInduk = Area::query()->orderBy('id')->firstOrFail();
    $this->flInduk   = FunctionalLocation::query()->orderBy('id')->firstOrFail();
    $this->flLain    = FunctionalLocation::query()->orderByDesc('id')->firstOrFail();

    $this->alatInduk = rkiAset([
        'equipment_no'  => '99010001',
        'tech_ident_no' => 'RKI-A1',
        'description'   => 'Alat induk uji',
        'area_id'       => $this->areaInduk->id,
        'funcloc_id'    => $this->flInduk->id,
    ]);

    $this->alatLain = rkiAset([
        'equipment_no'  => '99010002',
        'tech_ident_no' => 'RKI-A2',
        'description'   => 'Alat tambahan uji',
        'area_id'       => $this->areaInduk->id,
        'funcloc_id'    => $this->flLain->id,
    ]);

    $this->alatKetiga = rkiAset([
        'equipment_no'  => '99010003',
        'tech_ident_no' => 'RKI-A3',
        'description'   => 'Alat pengganti lokasi induk',
        'area_id'       => $this->areaInduk->id,
        'funcloc_id'    => $this->flLain->id,
    ]);

    $this->induk = Report::create([
        'technician_id'         => $this->pelapor->id,
        'creator_id'            => $this->pelapor->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'RKI-INDUK-01',
        'work_description'      => 'Pekerjaan induk uji kelola',
        'work_duration_minutes' => 120,
        'start_time'            => '08:00',
        'end_time'              => '10:00',
        'area_id'               => $this->areaInduk->id,
        'funcloc_id'            => $this->flInduk->id,
        'asset_id'              => $this->alatInduk->id,
        'report_type'           => 'equipment_repair',
        'jenis_pekerjaan'       => 'CM',
        'finding'               => 'Temuan awal induk',
        'root_cause'            => 'Penyebab awal induk',
        'status'                => 'continue',
    ]);

    // Baris salinan dibuat lewat service yang sama dengan halaman detail supaya
    // aturannya identik dengan yang dipakai aplikasi.
    $this->salinan = app(ReportCopyService::class)->buatSalinanKolaborasi($this->induk, $this->rekan);
    $this->multi   = app(ReportCopyService::class)->buatSalinanMulti($this->induk, $this->alatLain, null);
});

// Helper pembuat aset uji laporan induk & item tambahan.
function rkiAset(array $data): Asset
{
    return Asset::create(array_merge([
        'equipment_no'   => '99010000',
        'description'    => 'Alat Uji Kelola Induk',
        'tech_ident_no'  => 'RKI-UJI',
        'functional_loc' => null,
        'funcloc_id'     => FunctionalLocation::query()->value('id'),
        'company_id'     => null,
        'department_id'  => null,
        'area_id'        => null,
        'status'         => 'active',
    ], $data));
}

test('baris salinan kolaborasi tidak bisa diedit dari barisnya sendiri', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->salinan->id, [
            'work_description'      => 'DIUBAH DARI BARIS SALINAN',
            'report_date'           => $this->tanggal,
            'work_duration_minutes' => 999,
        ])
        ->assertRedirect()
        // Pesannya menyebut laporan induknya supaya admin tahu harus ke mana.
        ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'RKI-INDUK-01'));

    expect($this->salinan->fresh()->work_description)->toBe('Pekerjaan induk uji kelola')
        ->and($this->salinan->fresh()->work_duration_minutes)->toBe(120);
});

test('baris salinan alat tambahan tidak bisa diedit dari barisnya sendiri', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->multi->id, [
            'work_description'      => 'DIUBAH DARI BARIS ALAT TAMBAHAN',
            'report_date'           => $this->tanggal,
            'work_duration_minutes' => 999,
        ])
        ->assertRedirect()
        ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'RKI-INDUK-01'));

    expect($this->multi->fresh()->work_description)->toBe('Pekerjaan induk uji kelola')
        ->and($this->multi->fresh()->work_duration_minutes)->toBe(120);
});

test('baris salinan kolaborasi tidak bisa dihapus dari barisnya sendiri', function () {
    $this->actingAs($this->admin)
        ->delete('/reports/' . $this->salinan->id)
        ->assertRedirect()
        ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'RKI-INDUK-01'));

    expect(Report::find($this->salinan->id))->not->toBeNull()
        ->and(Report::find($this->induk->id))->not->toBeNull();
});

test('baris salinan alat tambahan tidak bisa dihapus dari barisnya sendiri', function () {
    $this->actingAs($this->admin)
        ->delete('/reports/' . $this->multi->id)
        ->assertRedirect()
        ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'RKI-INDUK-01'));

    expect(Report::find($this->multi->id))->not->toBeNull()
        ->and(Report::find($this->induk->id))->not->toBeNull();
});

test('ubah status lewat jalur lama pada baris salinan juga ditolak', function () {
    $this->actingAs($this->admin)
        ->post(route('reports.update-status', $this->salinan), ['status' => 'completed'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($this->salinan->fresh()->status)->toBe('continue');
});

test('mengedit laporan induk menyebarkan perubahan ke seluruh baris salinannya', function () {
    $jumlahAwal = Report::count();

    $this->actingAs($this->admin)
        ->put('/reports/' . $this->induk->id, [
            'work_description'      => 'Deskripsi pekerjaan hasil edit induk',
            'report_date'           => $this->tanggal,
            'work_duration_minutes' => 200,
            'start_time'            => '08:00',
            'end_time'              => '11:20',
            'finding'               => 'Temuan hasil edit induk',
            'root_cause'            => 'Penyebab hasil edit induk',
            'jenis_pekerjaan'       => 'CM',
            'status'                => 'completed',
        ])
        ->assertRedirect(route('reports.show', $this->induk));

    $kolaborasi = $this->salinan->fresh();
    $multi      = $this->multi->fresh();

    // Salinan kolaborasi: isi pekerjaan mengikuti induk, identitas tetap.
    expect($kolaborasi->work_description)->toBe('Deskripsi pekerjaan hasil edit induk')
        ->and($kolaborasi->finding)->toBe('Temuan hasil edit induk')
        ->and($kolaborasi->root_cause)->toBe('Penyebab hasil edit induk')
        ->and($kolaborasi->status)->toBe('completed')
        ->and($kolaborasi->work_duration_minutes)->toBe(200)
        ->and($kolaborasi->technician_id)->toBe($this->rekan->id)
        ->and($kolaborasi->collaborator_of)->toBe($this->induk->id)
        // Salinan multi alat: isi pekerjaan ikut berubah, identitas alat tetap.
        ->and($multi->work_description)->toBe('Deskripsi pekerjaan hasil edit induk')
        ->and($multi->finding)->toBe('Temuan hasil edit induk')
        ->and($multi->status)->toBe('completed')
        ->and($multi->work_duration_minutes)->toBe(200)
        ->and($multi->asset_id)->toBe($this->alatLain->id)
        ->and($multi->funcloc_id)->toBe($this->flLain->id)
        ->and($multi->multi_of)->toBe($this->induk->id);

    // Menyebarkan perubahan TIDAK boleh membuat baris salinan baru.
    expect(Report::count())->toBe($jumlahAwal);
});

test('salinan kolaborasi mengikuti lokasi induk sedangkan salinan alat tetap memakai alatnya', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->induk->id, [
            'work_description' => 'Pekerjaan induk pindah alat',
            'report_date'      => $this->tanggal,
            'area_id'          => $this->areaInduk->id,
            'funcloc_id'       => $this->flLain->id,
            'asset_id'         => $this->alatKetiga->id,
            'report_type'      => 'equipment_repair',
        ])
        ->assertRedirect(route('reports.show', $this->induk));

    expect($this->salinan->fresh()->asset_id)->toBe($this->alatKetiga->id)
        ->and($this->salinan->fresh()->funcloc_id)->toBe($this->flLain->id)
        // Item tambahan tetap memakai alatnya sendiri, bukan alat induk.
        ->and($this->multi->fresh()->asset_id)->toBe($this->alatLain->id)
        ->and($this->multi->fresh()->work_description)->toBe('Pekerjaan induk pindah alat');
});

test('ubah status laporan induk ikut mengubah status baris salinannya', function () {
    $this->actingAs($this->admin)
        ->post(route('reports.update-status', $this->induk), ['status' => 'completed'])
        ->assertRedirect();

    expect($this->induk->fresh()->status)->toBe('completed')
        ->and($this->salinan->fresh()->status)->toBe('completed')
        ->and($this->multi->fresh()->status)->toBe('completed');
});

test('menghapus laporan induk tetap menghapus seluruh baris salinannya', function () {
    $this->actingAs($this->admin)
        ->delete('/reports/' . $this->induk->id)
        ->assertRedirect(route('reports.index'));

    // Tidak ada baris salinan yang tersisa sebagai laporan lepas ("pecah per alat").
    expect(Report::find($this->induk->id))->toBeNull()
        ->and(Report::find($this->salinan->id))->toBeNull()
        ->and(Report::find($this->multi->id))->toBeNull()
        ->and(Report::where('multi_of', $this->induk->id)->count())->toBe(0)
        ->and(Report::where('collaborator_of', $this->induk->id)->count())->toBe(0)
        ->and(Report::where('report_code', 'RKI-INDUK-01')->count())->toBe(0);
});

test('halaman baris salinan kolaborasi menampilkan popup penolakan dan tombol cek laporan induk', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->salinan->id)
        ->assertStatus(200)
        ->assertSee('Laporan ini tidak bisa diedit atau dihapus', false)
        // Tombol pada markup (bukan sekadar nama method di blok script).
        ->assertSee('@click="bukaPenolakan()"', false)
        ->assertSee('x-show="penolakan"', false)
        ->assertSee('Cek Laporan Induk', false)
        ->assertSee(route('reports.show', $this->induk), false)
        // Form hapus laporan tidak dirender di halaman baris salinan.
        ->assertDontSee('Hapus laporan ini? Tindakan tidak dapat dibatalkan.', false);
});

test('halaman baris salinan alat tambahan menampilkan popup penolakan', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->multi->id)
        ->assertStatus(200)
        ->assertSee('Laporan ini tidak bisa diedit atau dihapus', false)
        ->assertSee('alat/lokasi tambahan', false)
        ->assertSee('Cek Laporan Induk', false)
        ->assertDontSee('Hapus laporan ini? Tindakan tidak dapat dibatalkan.', false);
});

test('halaman laporan induk tetap menampilkan tombol edit dan hapus', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->induk->id)
        ->assertStatus(200)
        ->assertSee('Hapus Laporan', false)
        ->assertSee('Edit Laporan', false)
        ->assertSee('action="' . route('reports.destroy', $this->induk) . '"', false)
        // Tombol penolakan hanya dirender pada halaman baris salinan.
        ->assertDontSee('@click="bukaPenolakan()"', false);
});
