<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Field pelengkap pada mode edit halaman DETAIL laporan: Tipe Laporan dan
 * Status Pekerjaan.
 *
 * Aturan yang dijaga di sini:
 *   1. Halaman detail mode edit memuat pilihan tipe laporan & status.
 *   2. Menyimpan status 'continue' mengosongkan completed_at, sedangkan
 *      'completed' mengisi completed_at (nilai lama dipertahankan bila sudah ada).
 *   3. Status yang tidak dikirim form TIDAK mengubah status lama (pemanggil lama
 *      tetap kompatibel) dan nilai di luar daftar ditolak validasi.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Pelengkap',
        'nik'    => 'PLG001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'PLG-LAPORAN-01',
        'work_description'      => 'Pekerjaan uji field pelengkap',
        'work_duration_minutes' => 60,
        'report_type'           => 'general',
        'status'                => 'completed',
        'completed_at'          => now()->subDay(),
    ]);
});

test('halaman detail mode edit menampilkan pilihan tipe laporan dan status', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('Tipe & Status Laporan', false)
        ->assertSee('name="report_type"', false)
        ->assertSee('name="status"', false)
        ->assertSee('Perbaikan Equipment', false)
        ->assertSee('Belum Selesai (continue)', false);
});

test('menyimpan status continue mengosongkan waktu selesai', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pekerjaan uji field pelengkap',
            'work_duration_minutes' => 60,
            'report_date'           => now()->toDateString(),
            'report_type'           => 'equipment_repair',
            'status'                => 'continue',
        ])
        ->assertRedirect();

    $tersimpan = $this->laporan->fresh();

    expect($tersimpan->status)->toBe('continue')
        ->and($tersimpan->report_type)->toBe('equipment_repair')
        ->and($tersimpan->completed_at)->toBeNull();
});

test('menyimpan status completed mengisi waktu selesai', function () {
    $this->laporan->update(['status' => 'continue', 'completed_at' => null]);

    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pekerjaan uji field pelengkap',
            'work_duration_minutes' => 60,
            'report_date'           => now()->toDateString(),
            'status'                => 'completed',
        ])
        ->assertRedirect();

    $tersimpan = $this->laporan->fresh();

    expect($tersimpan->status)->toBe('completed')
        ->and($tersimpan->completed_at)->not->toBeNull();
});

test('status lama tidak berubah bila form tidak mengirim status', function () {
    $sebelum = $this->laporan->fresh();

    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pekerjaan uji field pelengkap',
            'work_duration_minutes' => 60,
            'report_date'           => now()->toDateString(),
        ])
        ->assertRedirect();

    $sesudah = $this->laporan->fresh();

    expect($sesudah->status)->toBe($sebelum->status)
        ->and($sesudah->report_type)->toBe($sebelum->report_type)
        ->and($sesudah->completed_at?->toDateTimeString())
        ->toBe($sebelum->completed_at?->toDateTimeString());
});

test('nilai tipe laporan dan status di luar daftar ditolak validasi', function () {
    $this->actingAs($this->admin)
        ->from('/reports/' . $this->laporan->id)
        ->put('/reports/' . $this->laporan->id, [
            'work_description'      => 'Pekerjaan uji field pelengkap',
            'work_duration_minutes' => 60,
            'report_date'           => now()->toDateString(),
            'report_type'           => 'tipe_ngawur',
            'status'                => 'status_ngawur',
        ])
        ->assertSessionHasErrors(['report_type', 'status']);

    $tersimpan = $this->laporan->fresh();

    expect($tersimpan->report_type)->toBe('general')
        ->and($tersimpan->status)->toBe('completed');
});
