<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ============================================================
// TAMPILAN LAPORAN: KOLABORASI SHIFT / STAFF GRUB
// ============================================================
// Laporan yang dibuat Reguler/Reguler Staff tetapi mencantumkan kolaborator
// shift (Grub A-D) atau Staff Grub harus tampil di tab Shift, dan TIDAK di
// tab Reguler umum. Laporan tetap satu baris (induk), bukan salinan.

/**
 * Buat teknisi uji.
 */
function teknisiGrupUji(string $nama, string $nik, string $grup): Technician
{
    return Technician::create([
        'name'    => $nama,
        'nik'     => $nik,
        'group'   => $grup,
        'section' => 'mekanik',
        'status'  => 'active',
    ]);
}

/**
 * Buat laporan induk untuk tanggal uji.
 */
function laporanIndukUji(Technician $pelapor, string $deskripsi, string $tanggal = '2026-09-24'): Report
{
    return Report::create([
        'technician_id'         => $pelapor->id,
        'creator_id'            => $pelapor->id,
        'report_date'           => $tanggal,
        'work_description'      => $deskripsi,
        'work_duration_minutes' => 60,
        'jenis_pekerjaan'       => 'EI',
        'status'                => 'completed',
        'submitted_at'          => now(),
    ]);
}

/**
 * Buat salinan kolaborator (baris collaborator_of).
 */
function salinanKolaboratorUji(Report $induk, Technician $kolaborator): Report
{
    return Report::create([
        'technician_id'         => $kolaborator->id,
        'creator_id'            => $induk->technician_id,
        'collaborator_of'       => $induk->id,
        'report_date'           => $induk->report_date->toDateString(),
        'work_description'      => $induk->work_description,
        'work_duration_minutes' => $induk->work_duration_minutes,
        'jenis_pekerjaan'       => $induk->jenis_pekerjaan,
        'status'                => 'completed',
        'submitted_at'          => now(),
    ]);
}

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->tanggal = '2026-09-24';

    $this->reguler       = teknisiGrupUji('Reguler Uji', 'KOL001', 'reguler');
    $this->regulerStaff  = teknisiGrupUji('Reguler Staff Uji', 'KOL002', 'reguler_staff');
    $this->grubB         = teknisiGrupUji('Grub B Uji', 'KOL003', 'grub_b');
    $this->grubBStaff    = teknisiGrupUji('Staff Grub B Uji', 'KOL004', 'grub_b_staff');
    $this->grubD         = teknisiGrupUji('Grub D Uji', 'KOL005', 'grub_d');

    // 1. Reguler + kolaborator Grub B -> Shift saja.
    $this->laporanRegulerGrubB = laporanIndukUji($this->reguler, 'Reguler dengan kolaborator Grub B', $this->tanggal);
    salinanKolaboratorUji($this->laporanRegulerGrubB, $this->grubB);

    // 2. Reguler Staff + kolaborator Staff Grub B -> Shift saja.
    $this->laporanStaffStaffGrub = laporanIndukUji($this->regulerStaff, 'Reguler Staff dengan kolaborator Staff Grub B', $this->tanggal);
    salinanKolaboratorUji($this->laporanStaffStaffGrub, $this->grubBStaff);

    // 3. Reguler + kolaborator Reguler Staff -> tetap Reguler.
    $this->laporanRegulerSaja = laporanIndukUji($this->reguler, 'Reguler hanya dengan kolaborator Reguler Staff', $this->tanggal);
    salinanKolaboratorUji($this->laporanRegulerSaja, $this->regulerStaff);

    // 4. Shift Grub B + kolaborator Reguler -> tetap Shift.
    $this->laporanShift = laporanIndukUji($this->grubB, 'Shift Grub B dengan kolaborator Reguler', $this->tanggal);
    salinanKolaboratorUji($this->laporanShift, $this->reguler);

    // 5. Reguler + dua kolaborator shift -> Shift, tetap SATU baris.
    $this->laporanDuaShift = laporanIndukUji($this->reguler, 'Reguler dengan dua kolaborator shift', $this->tanggal);
    salinanKolaboratorUji($this->laporanDuaShift, $this->grubB);
    salinanKolaboratorUji($this->laporanDuaShift, $this->grubD);

    // 6. Reguler tanpa kolaborator -> tetap Reguler.
    $this->laporanRegulerBersih = laporanIndukUji($this->reguler, 'Reguler tanpa kolaborator', $this->tanggal);
});

test('tab Shift memuat laporan non-shift yang berkolaborasi shift dan tidak menduplikasi baris', function () {
    $html = $this->actingAs($this->admin)
        ->get(route('reports.index', ['tampilan' => 'shift', 'tanggal' => $this->tanggal]))
        ->assertOk()
        ->getContent();

    foreach ([$this->laporanRegulerGrubB, $this->laporanStaffStaffGrub, $this->laporanShift, $this->laporanDuaShift] as $laporan) {
        expect(substr_count($html, 'data-laporan-id="' . $laporan->id . '"'))->toBe(1);
    }

    expect($html)->toContain('Reguler dengan kolaborator Grub B')
        ->and($html)->toContain('Reguler Staff dengan kolaborator Staff Grub B')
        ->and($html)->toContain('Reguler dengan dua kolaborator shift')
        ->and($html)->toContain('Kolaborasi Grub B')
        ->and($html)->toContain('Kolaborasi Staff Grub B')
        ->and($html)->toContain('Kolaborasi Shift')
        ->and($html)->not->toContain('Reguler hanya dengan kolaborator Reguler Staff')
        ->and($html)->not->toContain('Reguler tanpa kolaborator');
});

test('tab Reguler tidak memuat laporan yang sudah dipindahkan ke Shift', function () {
    $html = $this->actingAs($this->admin)
        ->get(route('reports.index', ['tampilan' => 'reguler', 'tanggal' => $this->tanggal]))
        ->assertOk()
        ->getContent();

    foreach ([$this->laporanRegulerGrubB, $this->laporanStaffStaffGrub, $this->laporanShift, $this->laporanDuaShift] as $laporan) {
        expect($html)->not->toContain('data-laporan-id="' . $laporan->id . '"');
    }

    expect($html)->toContain('data-laporan-id="' . $this->laporanRegulerSaja->id . '"')
        ->and($html)->toContain('data-laporan-id="' . $this->laporanRegulerBersih->id . '"');
});

test('filter Grub hanya memuat kolaborator dari grub tersebut', function () {
    $htmlB = $this->actingAs($this->admin)
        ->get(route('reports.index', ['tampilan' => 'shift', 'grub' => 'grub_b', 'tanggal' => $this->tanggal]))
        ->assertOk()
        ->getContent();

    expect($htmlB)->toContain('data-laporan-id="' . $this->laporanRegulerGrubB->id . '"')
        ->and($htmlB)->toContain('data-laporan-id="' . $this->laporanStaffStaffGrub->id . '"')
        ->and($htmlB)->toContain('data-laporan-id="' . $this->laporanDuaShift->id . '"')
        ->and($htmlB)->not->toContain('data-laporan-id="' . $this->laporanRegulerSaja->id . '"');

    $htmlD = $this->actingAs($this->admin)
        ->get(route('reports.index', ['tampilan' => 'shift', 'grub' => 'grub_d', 'tanggal' => $this->tanggal]))
        ->assertOk()
        ->getContent();

    expect($htmlD)->toContain('data-laporan-id="' . $this->laporanDuaShift->id . '"')
        ->and($htmlD)->not->toContain('data-laporan-id="' . $this->laporanRegulerGrubB->id . '"')
        ->and($htmlD)->not->toContain('data-laporan-id="' . $this->laporanStaffStaffGrub->id . '"');
});

test('filter satu teknisi reguler tetap menampilkan laporan miliknya agar hitungan man hour konsisten', function () {
    $html = $this->actingAs($this->admin)
        ->get(route('reports.index', [
            'tampilan'      => 'reguler',
            'technician_id' => $this->reguler->id,
            'tanggal'       => $this->tanggal,
        ]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-laporan-id="' . $this->laporanRegulerGrubB->id . '"')
        ->and($html)->toContain('data-laporan-id="' . $this->laporanDuaShift->id . '"');
});
