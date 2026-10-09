<?php

use App\Models\SpMaterial;
use App\Models\SpPantau;
use App\Models\User;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

// ============================================================
// MODUL SPARE PART: DAFTAR PANTAU, HALAMAN, DAN EXPORT
// ============================================================

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
});

/**
 * Menjalankan import lengkap dari berkas fixture.
 *
 * @return array<string, string> Path berkas fixture
 */
function spImporPenuh(): array
{
    $berkas = spUjiBerkas();

    app(SparepartPantauService::class)->imporDariBerkas($berkas['pantau']);

    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $berkas['stok'],
        SparepartImportService::SUMBER_PR   => $berkas['pr'],
        SparepartImportService::SUMBER_PO   => $berkas['po'],
    ]);

    return $berkas;
}

test('impor berkas daftar pantau mengisi daftar dan menghitung status material', function () {
    $berkas = spUjiBerkas();

    $this->actingAs($this->admin)
        ->post(route('sparepart.pantau.impor'), [
            'berkas' => new UploadedFile($berkas['pantau'], 'pantau.xlsx', null, null, true),
            'mode'   => 'gabung',
        ])
        ->assertRedirect(route('sparepart.pantau'));

    expect(SpPantau::query()->count())->toBe(5)
        ->and(SpPantau::query()->where('kode_material', '30000002')->value('stok_min'))->toEqual(1);

    $this->actingAs($this->admin)->get(route('sparepart.pantau'))
        ->assertStatus(200)
        ->assertSee('30000002')
        ->assertSee('Daftar Pantau Sparepart');
});

test('tambah, ubah, dan hapus daftar pantau manual ikut menghitung ulang status', function () {
    $berkas = spImporPenuh();

    // Tambah manual: material tanpa catatan stok dianggap stok nol -> REORDER.
    $this->actingAs($this->admin)
        ->post(route('sparepart.pantau.store'), [
            'kode_material' => '30000009',
            'deskripsi'     => 'MATERIAL BARU UJI',
            'stok_min'      => 2,
        ])
        ->assertRedirect(route('sparepart.pantau'));

    $pantau = SpPantau::query()->where('kode_material', '30000009')->firstOrFail();

    expect($pantau->sumber)->toBe(SpPantau::SUMBER_MANUAL)
        ->and((bool) SpMaterial::query()->where('kode_material', '30000009')->value('reorder'))->toBeTrue();

    // Ubah stok minimum material berstok (30000003, stok tersedia 7) menjadi 10
    // sehingga statusnya berubah menjadi REORDER, lalu dikembalikan.
    $bearing = SpPantau::query()->where('kode_material', '30000003')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('sparepart.pantau.update', $bearing), [
            'kode_material' => '30000003',
            'deskripsi'     => 'BEARING UJI',
            'stok_min'      => 10,
        ])
        ->assertRedirect(route('sparepart.pantau'));

    expect((bool) SpMaterial::query()->where('kode_material', '30000003')->value('reorder'))->toBeTrue();

    $this->actingAs($this->admin)
        ->put(route('sparepart.pantau.update', $bearing), [
            'kode_material' => '30000003',
            'deskripsi'     => 'BEARING UJI',
            'stok_min'      => 1,
        ])
        ->assertRedirect(route('sparepart.pantau'));

    expect((bool) SpMaterial::query()->where('kode_material', '30000003')->value('reorder'))->toBeFalse();

    // Hapus -> baris keadaan material itu ikut dibuang.
    $this->actingAs($this->admin)
        ->delete(route('sparepart.pantau.destroy', $pantau))
        ->assertRedirect(route('sparepart.pantau'));

    expect(SpPantau::query()->where('kode_material', '30000009')->exists())->toBeFalse()
        ->and(SpMaterial::query()->where('kode_material', '30000009')->exists())->toBeFalse()
        ->and(SpMaterial::query()->count())->toBe(5);

    // Berkas lain tetap utuh.
    expect((int) SpMaterial::query()->where('kode_material', '30000002')->value('po_line'))->toBe(1)
        ->and(is_file($berkas['stok']))->toBeTrue();
});

test('halaman sparepart menampilkan tabel keadaan dan mengikuti saringan', function () {
    spImporPenuh();

    $this->actingAs($this->admin)
        ->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertSee('data-kode-material="30000002"', false)
        ->assertSee('REORDER')
        ->assertSee('Kejar PO');

    $this->actingAs($this->admin)
        ->get(route('sparepart.index', ['status' => 'aman']))
        ->assertStatus(200)
        ->assertSee('data-kode-material="30000003"', false)
        ->assertDontSee('data-kode-material="30000002"', false);
});

test('halaman import dan riwayat menampilkan format terkunci dan analisa', function () {
    spImporPenuh();

    $this->actingAs($this->admin)
        ->get(route('sparepart.impor'))
        ->assertStatus(200)
        ->assertSee('Judul kolom yang dikunci')
        ->assertSee('Still to be delivered');

    $impor = \App\Models\SpImpor::query()->firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('sparepart.riwayat'))
        ->assertStatus(200)
        ->assertSee('Riwayat Import Sparepart');

    $this->actingAs($this->admin)
        ->get(route('sparepart.riwayat', ['impor' => $impor->id]))
        ->assertStatus(200)
        ->assertSee('Analisa import #' . $impor->id)
        ->assertSee('Material di luar daftar pantau')
        ->assertSee('30009999');
});

test('export Excel menyusun blok judul dan menulis kode material sebagai teks', function () {
    spImporPenuh();

    $response = $this->actingAs($this->admin)->get(route('sparepart.export', ['status' => 'reorder']));
    $response->assertStatus(200);

    $path = spUjiFolder() . DIRECTORY_SEPARATOR . 'export-uji.xlsx';
    file_put_contents($path, $response->streamedContent());

    $buku  = IOFactory::load($path);
    $sheet = $buku->getSheetByName('Keadaan Spare Part');

    expect($sheet)->not->toBeNull()
        ->and($sheet->getCell('A7')->getValue())->toBe('No')
        ->and($sheet->getCell('B7')->getValue())->toBe('Kode Material')
        ->and($sheet->getCell('A8')->getValue())->toBe(1)
        ->and($sheet->getCell('B8')->getValue())->toBe('30000001')
        ->and($sheet->getCell('B8')->getDataType())->toBe(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)
        ->and($buku->getSheetByName('Ringkasan'))->not->toBeNull()
        ->and($buku->getSheetByName('Petunjuk'))->not->toBeNull();

    foreach ($buku->getWorksheetIterator() as $halaman) {
        $halaman->disconnectCells();
    }

    $buku->disconnectWorksheets();
});

test('unggah berkas lewat halaman import mengisi keadaan dan mengalihkan ke riwayat', function () {
    $berkas = spUjiBerkas();

    $this->actingAs($this->admin)
        ->post(route('sparepart.impor.proses'), [
            'stok'   => new UploadedFile($berkas['stok'], 'stok.xlsx', null, null, true),
            'pr'     => new UploadedFile($berkas['pr'], 'pr.xlsx', null, null, true),
            'po'     => new UploadedFile($berkas['po'], 'po.xlsx', null, null, true),
            'pantau' => new UploadedFile($berkas['pantau'], 'pantau.xlsx', null, null, true),
            'label'  => 'Uji unggah halaman',
        ])
        ->assertRedirect(route('sparepart.riwayat', ['impor' => 1]));

    expect(SpMaterial::query()->count())->toBe(5)
        ->and(\App\Models\SpImpor::query()->value('label'))->toBe('Uji unggah halaman');
});

test('unggah tanpa berkas sumber ditolak dengan pesan kesalahan', function () {
    $this->actingAs($this->admin)
        ->post(route('sparepart.impor.proses'), ['label' => 'tanpa berkas'])
        ->assertSessionHas('error');

    expect(SpMaterial::query()->count())->toBe(0);
});

test('akun non-admin tidak boleh mengunggah berkas (mode hanya baca)', function () {
    $berkas = spUjiBerkas();

    $pengguna = User::create([
        'name'     => 'Supervisor Uji',
        'username' => 'supuji',
        'email'    => 'supuji@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'supervisor',
    ]);

    $this->actingAs($pengguna)
        ->post(route('sparepart.impor.proses'), [
            'stok' => new UploadedFile($berkas['stok'], 'stok.xlsx', null, null, true),
        ])
        ->assertStatus(403);

    expect(SpMaterial::query()->count())->toBe(0);
});

test('impor daftar pantau mode ganti membuang baris di luar berkas', function () {
    spImporPenuh();

    $pantauKecil = spUjiXlsx('pantau-kecil.xlsx', [
        'MASTER DATA' => [
            ['Kode Material', 'Deskripsi', 'Jumlah'],
            ['30000002', 'SEAL UJI', 1],
            ['30000003', 'BEARING UJI', 4],
        ],
    ]);

    $this->actingAs($this->admin)
        ->post(route('sparepart.pantau.impor'), [
            'berkas' => new UploadedFile($pantauKecil, 'pantau-kecil.xlsx', null, null, true),
            'mode'   => 'ganti',
        ])
        ->assertRedirect(route('sparepart.pantau'));

    expect(SpPantau::query()->count())->toBe(2)
        ->and(SpPantau::query()->where('kode_material', '30000001')->exists())->toBeFalse()
        ->and(SpMaterial::query()->where('kode_material', '30000001')->exists())->toBeFalse()
        // Stok tersedia 30000003 = 7 dan stok minimum menjadi 4 -> masih aman.
        ->and((bool) SpMaterial::query()->where('kode_material', '30000003')->value('reorder'))->toBeFalse();
});
