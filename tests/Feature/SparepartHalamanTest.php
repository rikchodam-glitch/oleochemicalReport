<?php

use App\Models\SpBatch;
use App\Models\SpIntegrasi;
use App\Models\User;
use App\Support\AksesModul;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Siapkan satu batch hasil integrasi untuk pengujian halaman.
 *
 * @param  array<string, mixed> $ubah Nilai batch yang ingin ditimpa
 * @return SpBatch                     Batch yang sudah dibuat
 */
function spBatchUji(array $ubah = []): SpBatch
{
    return SpBatch::create(array_merge([
        'periode'           => 'Uji Okt 26',
        'folder'            => 'C:\\sumber\\Okt 26',
        'status'            => 'selesai',
        'ringkasan'         => [
            'jumlah' => ['monitoring' => 3, 'g001' => 5, 'pr' => 4, 'po' => 2, 'mrp' => 3, 'hasil' => 2],
            'aksi'   => [
                SpIntegrasi::AKSI_KEJAR   => 1,
                SpIntegrasi::AKSI_BUAT_PO => 1,
                SpIntegrasi::AKSI_CEK     => 0,
                SpIntegrasi::AKSI_BUAT_PR => 0,
            ],
            'stok'   => ['total' => 2, 'aman' => 1, 'reorder' => 1],
        ],
        'jumlah_monitoring' => 3,
        'jumlah_g001'       => 5,
        'jumlah_pr'         => 4,
        'jumlah_po'         => 2,
        'jumlah_mrp'        => 3,
        'jumlah_hasil'      => 2,
        'selesai_at'        => now(),
    ], $ubah));
}

/**
 * Satu baris rincian integrasi contoh.
 *
 * @param  SpBatch              $batch Batch pemilik baris
 * @param  array<string, mixed> $ubah  Nilai yang ingin ditimpa
 * @return SpIntegrasi                 Baris yang sudah dibuat
 */
function spBarisUji(SpBatch $batch, array $ubah = []): SpIntegrasi
{
    return SpIntegrasi::create(array_merge([
        'batch_id'        => $batch->id,
        'kode_material'   => '00030000001',
        'deskripsi'       => 'V-BELT UJI',
        'stok_min'        => 5,
        'stok_tersedia'   => 2,
        'reorder'         => true,
        'penanda'         => 'REORDER',
        'aksi'            => SpIntegrasi::AKSI_KEJAR,
        'pr_no'           => '10001234',
        'pr_status'       => 'Approved',
        'pr_qty'          => 10,
        'po_no'           => '45000123',
        'po_sisa'         => 8,
        'po_open'         => 1,
        'po_closed'       => 0,
        'po_tanggal_teks' => '01.10.2026',
        'vendor'          => 'PT VENDOR UJI',
        'purch'           => 'P01',
        'g_sisa'          => 2,
        'mrp_reorder'     => 4,
        'min_cmp'         => 'Beda',
        'ket'             => 'Catatan uji',
    ], $ubah));
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
});

test('halaman daftar periode menampilkan ringkasan periode terbaru', function () {
    $batch = spBatchUji();
    spBarisUji($batch);

    $this->actingAs($this->admin)
        ->get('/sparepart')
        ->assertStatus(200)
        ->assertSee('Integrasi PR/PO Spare Part')
        ->assertSee('Uji Okt 26')
        ->assertSee('data-periode-terbaru', false)
        ->assertSee('data-kartu-aksi="Kejar PO"', false)
        ->assertSee('data-batch-id="' . $batch->id . '"', false);
});

test('halaman daftar periode menampilkan petunjuk import saat belum ada data', function () {
    $this->actingAs($this->admin)
        ->get('/sparepart')
        ->assertStatus(200)
        ->assertSee('Belum ada data integrasi.')
        ->assertSee('sparepart:import');
});

test('halaman rincian menampilkan baris material satu periode', function () {
    $batch = spBatchUji();
    spBarisUji($batch);

    $this->actingAs($this->admin)
        ->get('/sparepart/' . $batch->id)
        ->assertStatus(200)
        ->assertSee('Rincian periode Uji Okt 26')
        ->assertSee('00030000001')
        ->assertSee('V-BELT UJI')
        ->assertSee('10001234')
        ->assertSee('45000123')
        ->assertSee('Kejar PO')
        ->assertSee('data-kode-material="00030000001"', false);
});

test('periode yang tidak ada berbalas 404', function () {
    $this->actingAs($this->admin)
        ->get('/sparepart/999999')
        ->assertStatus(404);
});

test('saringan pencarian, aksi, dan reorder menyaring daftar rincian', function () {
    $batch = spBatchUji();
    spBarisUji($batch, ['kode_material' => '00030000001', 'deskripsi' => 'V-BELT UJI']);
    spBarisUji($batch, [
        'kode_material' => '00030000009',
        'deskripsi'     => 'BEARING UJI',
        'aksi'          => SpIntegrasi::AKSI_BUAT_PR,
        'reorder'       => false,
        'penanda'       => null,
    ]);

    $cari = $this->actingAs($this->admin)->get('/sparepart/' . $batch->id . '?search=BEARING');
    $cari->assertStatus(200)
        ->assertSee('data-kode-material="00030000009"', false)
        ->assertDontSee('data-kode-material="00030000001"', false);

    $aksi = $this->actingAs($this->admin)->get('/sparepart/' . $batch->id . '?aksi=' . urlencode(SpIntegrasi::AKSI_KEJAR));
    $aksi->assertStatus(200)
        ->assertSee('data-kode-material="00030000001"', false)
        ->assertDontSee('data-kode-material="00030000009"', false);

    $reorder = $this->actingAs($this->admin)->get('/sparepart/' . $batch->id . '?reorder=tidak');
    $reorder->assertStatus(200)
        ->assertSee('data-kode-material="00030000009"', false)
        ->assertDontSee('data-kode-material="00030000001"', false);
});

test('nilai saringan aksi yang tidak dikenal diabaikan', function () {
    $batch = spBatchUji();
    spBarisUji($batch);

    $this->actingAs($this->admin)
        ->get('/sparepart/' . $batch->id . '?aksi=' . urlencode('Aksi Karangan'))
        ->assertStatus(200)
        ->assertSee('data-kode-material="00030000001"', false);
});

test('halaman rincian menampilkan pesan kosong saat saringan tidak menemukan baris', function () {
    $batch = spBatchUji();
    spBarisUji($batch);

    $this->actingAs($this->admin)
        ->get('/sparepart/' . $batch->id . '?search=TIDAKADAKODEINIXYZ')
        ->assertStatus(200)
        ->assertSee('Tidak ada material yang cocok dengan saringan.');
});

test('ekspor Excel memakai nama berkas periode dan memuat kepala kolom rincian', function () {
    $batch = spBatchUji();
    spBarisUji($batch);

    $respons = $this->actingAs($this->admin)->get('/sparepart/' . $batch->id . '/export');

    $respons->assertStatus(200)
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect((string) $respons->headers->get('content-disposition'))->toContain('integrasi-pr-po-uji-okt-26');

    $berkas = tempnam(sys_get_temp_dir(), 'sp-export-') . '.xlsx';
    file_put_contents($berkas, $respons->streamedContent());

    $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($berkas);
    $sheet       = $spreadsheet->getActiveSheet();

    expect((string) $sheet->getCell('A1')->getValue())->toContain('INTEGRASI PR/PO SPARE PART')
        ->and($sheet->getCell('A7')->getValue())->toBe('No')
        ->and($sheet->getCell('B7')->getValue())->toBe('Kode Material')
        ->and((string) $sheet->getCell('B8')->getValue())->toBe('00030000001')
        ->and($sheet->getCell('B8')->getDataType())->toBe(PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)
        ->and((string) $sheet->getCell('J8')->getValue())->toBe('10001234')
        ->and((string) $sheet->getCell('O8')->getValue())->toBe('45000123');

    $spreadsheet->disconnectWorksheets();
    @unlink($berkas);
});

test('modul sparepart terdaftar pada hak akses dan menunjuk menu sidebar', function () {
    expect(config('akses.modul.sparepart'))->toBeArray()
        ->and(config('akses.modul.sparepart.link'))->toBe('sparepart.index')
        ->and(config('akses.modul.sparepart.grup'))->toBe('Reliability & Maintenance')
        ->and(AksesModul::kunciUntukRoute('sparepart.index'))->toBe('sparepart')
        ->and(AksesModul::kunciUntukRoute('sparepart.export'))->toBe('sparepart');
});

test('menu sidebar memuat tautan integrasi PR/PO untuk administrator', function () {
    $this->actingAs($this->admin)
        ->get('/sparepart')
        ->assertStatus(200)
        ->assertSee('sidebar-link-text">Integrasi PR/PO Spare Part', false);
});

