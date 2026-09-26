<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Status 'draft' sudah tidak dipakai lagi: laporan selalu 'completed' (Done)
 * atau 'continue'. Seluruh draft lama dipindahkan ke 'completed' lewat migrasi
 * 2026_09_26_000005, dan tidak ada lagi tombol/filter Draft di halaman laporan.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Uji Draft',
        'nik'         => 'DRAFT-1',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '67001',
    ]);
});

test('status draft tidak diterima lagi saat mengubah status laporan', function () {
    $laporan = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->toDateString(),
        'report_code'      => 'RPT-DRAFT-1',
        'work_description' => 'Uji status draft',
        'status'           => 'completed',
    ]);

    $this->actingAs($this->admin)
        ->post(route('reports.update-status', $laporan), ['status' => 'draft'])
        ->assertSessionHasErrors('status');

    expect($laporan->fresh()->status)->toBe('completed');
});

test('menandai selesai mengisi completed_at dan halaman laporan tanpa opsi draft', function () {
    $laporan = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->toDateString(),
        'report_code'      => 'RPT-DRAFT-2',
        'work_description' => 'Uji tandai selesai',
        'status'           => 'continue',
    ]);

    $this->actingAs($this->admin)
        ->post(route('reports.update-status', $laporan), ['status' => 'completed'])
        ->assertRedirect();

    expect($laporan->fresh()->status)->toBe('completed')
        ->and($laporan->fresh()->completed_at)->not->toBeNull();

    // Halaman laporan tidak lagi menawarkan status draft.
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString())
        ->assertStatus(200)
        ->assertDontSee('>Draft</option>', false)
        ->assertSee('Done (selesai)', false)
        ->assertSee('Continue (belum selesai)', false);

    // Halaman detail tidak lagi punya tombol "Tandai Draft".
    $this->actingAs($this->admin)
        ->get('/reports/' . $laporan->id)
        ->assertStatus(200)
        ->assertDontSee('Tandai Draft', false)
        ->assertSee('Tandai Selesai', false);
});
