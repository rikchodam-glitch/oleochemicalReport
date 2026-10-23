<?php

use App\Models\SpMaterial;
use App\Models\User;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// MODUL SPARE PART: STOK MIN MRP, STOK BEDA, DAN FILTER HEADER
// ============================================================
// Keputusan user 23/10/2026:
// - stok minimum memakai Safety Stk EXPORT MRP (MD07/MD04);
// - material yang tidak ada di MRP diberi keterangan "Belum MRP";
// - indikator "Min Beda" diganti "Stok Beda" (Sisa G001 vs Stock by SAP);
// - judul/judul kolom tabel dapat diklik untuk menyaring data.

/**
 * Membuat berkas MRP uji dengan material tertentu saja.
 *
 * @param  string             $nama   Nama berkas
 * @param  array<int, string> $tanpa  Kode yang sengaja dihilangkan
 * @return string                     Path berkas
 */
function spMrpFilterBerkas(string $nama, array $tanpa = []): string
{
    $judul = [
        'Status', 'Valid from date', 'Material', 'MRP Area', 'Description', 'Accessed', 'Process.',
        'Stock DS', '1st RDS', '2nd RDS', 'EM1', 'EM2', 'EM3', 'EM4', 'EM5', 'EM6', 'EM7', 'EM8',
        'Plnt stock', 'Unit', 'Safety Stk', 'Reorder Pt', 'Matl type', 'Proc. Type', 'Spec.proc.',
        'ABC', 'MRP Group', 'MRP Type', 'L-L code', 'Created', 'Seg.Status',
    ];

    $material = [
        ['30000001', 0, 1],
        ['30000002', 0, 1],
        ['30000003', 5, 1],
        ['30000004', 0, 1],
        ['30000005', 2, 1],
    ];

    $baris = [$judul];

    foreach ($material as [$kode, $stok, $safety]) {
        if (in_array($kode, $tanpa, true)) {
            continue;
        }

        $baris[] = [
            'XOO', '', $kode, '7011', 'UJI', '', '', '', '', '', '', '', '', '', '', '', '', '',
            $stok, 'PCS', $safety, $safety, 'Z5ER', 'F', '', '', '0000', 'VB', '999', '', 'X',
        ];
    }

    return spUjiXlsx($nama, ['Sheet1' => $baris]);
}

beforeEach(function () {
    $this->seed();

    $this->admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->berkas = spUjiBerkas();

    app(SparepartPantauService::class)->imporDariBerkas($this->berkas['pantau']);
    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_STOK => $this->berkas['stok'],
        SparepartImportService::SUMBER_PR   => $this->berkas['pr'],
        SparepartImportService::SUMBER_PO   => $this->berkas['po'],
        SparepartImportService::SUMBER_MRP  => $this->berkas['mrp'],
    ]);
});

test('stok minimum memakai Safety Stk MRP dan material di luar MRP berstatus Belum MRP', function () {
    expect((float) SpMaterial::query()->where('kode_material', '30000002')->value('stok_min'))->toBe(1.0)
        ->and((bool) SpMaterial::query()->where('kode_material', '30000002')->value('reorder'))->toBeTrue()
        ->and((float) SpMaterial::query()->where('kode_material', '30000003')->value('stok_min'))->toBe(1.0)
        ->and((bool) SpMaterial::query()->where('kode_material', '30000003')->value('reorder'))->toBeFalse();

    // Berkas MRP baru tidak memuat 30000005 -> material itu menjadi Belum MRP.
    app(SparepartImportService::class)->import([
        SparepartImportService::SUMBER_MRP => spMrpFilterBerkas('mrp-tanpa-30000005.xlsx', ['30000005']),
    ]);

    $material = SpMaterial::query()->where('kode_material', '30000005')->firstOrFail();

    expect($material->stok_min)->toBeNull()
        ->and((bool) $material->reorder)->toBeFalse()
        ->and((string) $material->penanda)->toContain('Belum MRP');

    $this->actingAs($this->admin)
        ->get(route('sparepart.index', ['status' => 'belum']))
        ->assertStatus(200)
        ->assertSee('data-kode-material="30000005"', false)
        ->assertDontSee('data-kode-material="30000002"', false)
        ->assertSee('Belum MRP');
});

test('Stok Beda membandingkan sisa G001 dengan Stock by SAP dan bisa disaring dari header', function () {
    $material = SpMaterial::query()->where('kode_material', '30000003')->firstOrFail();

    expect($material->mrp_cmp)->toBe('Beda');

    $this->actingAs($this->admin)
        ->get(route('sparepart.index', ['mrp' => 'beda']))
        ->assertStatus(200)
        ->assertSee('data-kode-material="30000003"', false)
        ->assertDontSee('data-kode-material="30000002"', false)
        ->assertSee('Stok Beda');

    $this->actingAs($this->admin)
        ->get(route('sparepart.index', ['mrp' => 'sama']))
        ->assertStatus(200)
        ->assertSee('data-kode-material="30000002"', false)
        ->assertDontSee('data-kode-material="30000003"', false)
        ->assertSee('Stok Sama');
});

test('judul kolom tabel menyaring status, aksi, PR, PO, stok, dan teks', function () {
    $kasus = [
        ['status' => 'reorder', 'ada' => ['30000001', '30000002', '30000004'], 'tidak' => ['30000003', '30000005']],
        ['aksi' => SpMaterial::AKSI_BUAT_PO, 'ada' => ['30000001'], 'tidak' => ['30000002']],
        ['pr' => 'terbuka', 'ada' => ['30000001'], 'tidak' => ['30000002']],
        ['po' => 'terbuka', 'ada' => ['30000002'], 'tidak' => ['30000001']],
        ['mrp' => 'beda', 'ada' => ['30000003'], 'tidak' => ['30000002']],
        ['kode' => '30000004', 'ada' => ['30000004'], 'tidak' => ['30000003']],
        ['deskripsi' => 'SEAL', 'ada' => ['30000002'], 'tidak' => ['30000003']],
        ['min_stok' => '1', 'max_stok' => '2', 'ada' => ['30000005'], 'tidak' => ['30000003']],
    ];

    foreach ($kasus as $saringan) {
        $ada    = $saringan['ada'];
        $tidak  = $saringan['tidak'];
        unset($saringan['ada'], $saringan['tidak']);

        $halaman = $this->actingAs($this->admin)
            ->get(route('sparepart.index', $saringan))
            ->assertStatus(200);

        foreach ($ada as $kode) {
            $halaman->assertSee('data-kode-material="' . $kode . '"', false);
        }

        foreach ($tidak as $kode) {
            $halaman->assertDontSee('data-kode-material="' . $kode . '"', false);
        }
    }
});

test('form filter header dan History PR tampil di halaman', function () {
    $this->actingAs($this->admin)
        ->get(route('sparepart.index'))
        ->assertStatus(200)
        ->assertSee('name="kode"', false)
        ->assertSee('name="deskripsi"', false)
        ->assertSee('name="min_stok_min"', false)
        ->assertSee('name="dari"', false)
        ->assertSee('Semua kelompok')
        ->assertSee('Filter lanjutan')
        ->assertSee('Detail material')
        ->assertSee('Klik nomor PR untuk membuka History PR di popup')
        ->assertSee('History PR (EXPORT ME5A)')
        ->assertSee('1000000001');
});
