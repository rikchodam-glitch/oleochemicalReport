<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Izin tulis sebagian untuk Staff Grub A-D dan Reguler Staff (15/10/2026).
 *
 * Aturan yang dijaga di berkas ini:
 *   1. Group Staff Grub A-D / Reguler Staff boleh memakai metode tulis HANYA
 *      pada route kemampuan "laporan", plus import PO Spare Part dan sync data
 *      CM. Buat laporan baru dan import/ubah data lain tetap ditolak.
 *   2. Batas per-baris: hanya laporan yang pemiliknya berada di grup yang
 *      dinaungi (Staff Grub A -> grub_a; Reguler Staff -> reguler + reguler_staff).
 *   3. Administrator dan akun non-staff tidak berubah perilakunya.
 *   4. Laporan staf tampil pada tampilan Shift sesuai grubnya.
 */

/**
 * Bentuk teknisi uji.
 *
 * @param  string      $nama Nama teknisi
 * @param  string      $nik  NIK sekaligus penanda unik
 * @param  string|null $grup Kode group teknisi
 * @return Technician
 */
function sgTeknisi(string $nama, string $nik, ?string $grup): Technician
{
    return Technician::create([
        'name'   => $nama,
        'nik'    => $nik,
        'group'  => $grup,
        'status' => 'active',
    ]);
}

/**
 * Bentuk akun Bagian yang terhubung ke satu teknisi (group diambil dari teknisi).
 *
 * @param  string     $bagian   Kode bagian (section)
 * @param  string     $username Username akun
 * @param  Technician $teknisi  Teknisi yang dihubungkan
 * @return User
 */
function sgAkun(string $bagian, string $username, Technician $teknisi): User
{
    return User::create([
        'name'          => 'Akun ' . $username,
        'username'      => $username,
        'role'          => $bagian,
        'password'      => 'rahasia123',
        'technician_id' => $teknisi->id,
    ]);
}

/**
 * Bentuk laporan uji milik satu teknisi.
 *
 * @param  Technician $teknisi Pemilik laporan
 * @param  string     $kode    Kode laporan unik
 * @param  string     $tanggal Tanggal laporan
 * @return Report
 */
function sgLaporan(Technician $teknisi, string $kode, string $tanggal): Report
{
    return Report::create([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => $tanggal,
        'report_code'           => $kode,
        'work_description'      => 'Pekerjaan ' . $kode,
        'work_duration_minutes' => 60,
        'report_type'           => 'equipment_repair',
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'continue',
    ]);
}

beforeEach(function () {
    $this->seed();

    $this->admin   = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->tanggal = now()->toDateString();
});

test('Staff Grub hanya boleh menulis pada route yang diizinkan', function () {
    $staff = sgAkun('mekanik', 'SGUJI-A1', sgTeknisi('Staff Uji A', 'SGUJI-A1', 'grub_a_staff'));

    expect($staff->bolehMenulis())->toBeFalse()
        ->and($staff->kemampuanTulis())->toBe(['laporan', 'sparepart_impor', 'cm_sync'])
        // Kemampuan laporan: kelola laporan yang sudah ada.
        ->and($staff->bolehMenulisRoute('reports.update'))->toBeTrue()
        ->and($staff->bolehMenulisRoute('reports.update-status'))->toBeTrue()
        ->and($staff->bolehMenulisRoute('reports.destroy'))->toBeTrue()
        ->and($staff->bolehMenulisRoute('reports.add-photo'))->toBeTrue()
        ->and($staff->bolehMenulisRoute('reports.collaborators.store'))->toBeTrue()
        ->and($staff->bolehMenulisRoute('reports.multi-items.store'))->toBeTrue()
        // Pengecualian yang tetap boleh.
        ->and($staff->bolehMenulisRoute('sparepart.impor.proses'))->toBeTrue()
        ->and($staff->bolehMenulisRoute('cm.sync-google-sheets'))->toBeTrue()
        // Buat laporan baru dan data lain tetap ditolak.
        ->and($staff->bolehMenulisRoute('reports.store'))->toBeFalse()
        ->and($staff->bolehMenulisRoute('assets.import.execute'))->toBeFalse()
        ->and($staff->bolehMenulisRoute('assets.destroy'))->toBeFalse()
        ->and($staff->bolehMenulisRoute('it-assets.import.execute'))->toBeFalse()
        ->and($staff->bolehMenulisRoute('cm.import.upload'))->toBeFalse()
        ->and($staff->bolehMenulisRoute('users.store'))->toBeFalse();
});

test('akun bagian biasa tetap hanya baca (tanpa kemampuan tulis sebagian)', function () {
    $biasa = User::create([
        'name'     => 'Akun Biasa',
        'username' => 'SGUJI-BIASA',
        'role'     => 'mekanik',
        'password' => 'rahasia123',
    ]);

    expect($biasa->kemampuanTulis())->toBe([])
        ->and($biasa->bolehMenulisRoute('reports.update'))->toBeFalse();
});

test('Staff Grub A hanya boleh mengelola laporan grub A', function () {
    $pelaporA = sgTeknisi('Pelapor Grub A', 'SGUJI-PA', 'grub_a');
    $pelaporB = sgTeknisi('Pelapor Grub B', 'SGUJI-PB', 'grub_b');
    $staff    = sgAkun('mekanik', 'SGUJI-A2', sgTeknisi('Staff Uji A2', 'SGUJI-A2', 'grub_a_staff'));

    $laporanA = sgLaporan($pelaporA, 'SGUJI-A-001', $this->tanggal);
    $laporanB = sgLaporan($pelaporB, 'SGUJI-B-001', $this->tanggal);

    expect($staff->bolehKelolaLaporan($laporanA))->toBeTrue()
        ->and($staff->bolehKelolaLaporan($laporanB))->toBeFalse();
});

test('Reguler Staff mengelola laporan reguler dan reguler staff, bukan shift', function () {
    $reguler      = sgTeknisi('Pelapor Reguler', 'SGUJI-PR', 'reguler');
    $regulerStaff = sgTeknisi('Pelapor Reg Staff', 'SGUJI-PRS', 'reguler_staff');
    $grubA        = sgTeknisi('Pelapor Grub A RS', 'SGUJI-PA2', 'grub_a');
    $staff        = sgAkun('ei', 'SGUJI-RS1', sgTeknisi('Reg Staff Uji', 'SGUJI-RS1', 'reguler_staff'));

    expect($staff->bolehKelolaLaporan(sgLaporan($reguler, 'SGUJI-R-001', $this->tanggal)))->toBeTrue()
        ->and($staff->bolehKelolaLaporan(sgLaporan($regulerStaff, 'SGUJI-RS-001', $this->tanggal)))->toBeTrue()
        ->and($staff->bolehKelolaLaporan(sgLaporan($grubA, 'SGUJI-A-002', $this->tanggal)))->toBeFalse();
});

test('Staff Grub A bisa mengubah laporan grub A lewat HTTP', function () {
    $pelaporA = sgTeknisi('Pelapor Grub A3', 'SGUJI-PA3', 'grub_a');
    $staff    = sgAkun('mekanik', 'SGUJI-A3', sgTeknisi('Staff Uji A3', 'SGUJI-A3', 'grub_a_staff'));
    $laporanA = sgLaporan($pelaporA, 'SGUJI-A-003', $this->tanggal);

    $this->actingAs($staff)
        ->put('/reports/' . $laporanA->id, [
            'work_description'      => 'Diubah oleh Staff Grub A',
            'report_date'           => $this->tanggal,
            'work_duration_minutes' => 90,
        ])
        ->assertRedirect(route('reports.show', $laporanA));

    expect($laporanA->fresh()->work_description)->toBe('Diubah oleh Staff Grub A');
});

test('Staff Grub A tidak bisa mengubah atau menghapus laporan grub B lewat HTTP', function () {
    $pelaporB = sgTeknisi('Pelapor Grub B3', 'SGUJI-PB3', 'grub_b');
    $staff    = sgAkun('mekanik', 'SGUJI-A4', sgTeknisi('Staff Uji A4', 'SGUJI-A4', 'grub_a_staff'));
    $laporanB = sgLaporan($pelaporB, 'SGUJI-B-003', $this->tanggal);

    $this->actingAs($staff)
        ->put('/reports/' . $laporanB->id, [
            'work_description'      => 'TIDAK BOLEH DIUBAH',
            'report_date'           => $this->tanggal,
            'work_duration_minutes' => 999,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($staff)
        ->delete('/reports/' . $laporanB->id)
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($laporanB->fresh()->work_description)->toBe('Pekerjaan SGUJI-B-003')
        ->and(Report::find($laporanB->id))->not->toBeNull();
});

test('Staff Grub diblok 403 saat import aset dan buat laporan baru', function () {
    $staff = sgAkun('mekanik', 'SGUJI-A5', sgTeknisi('Staff Uji A5', 'SGUJI-A5', 'grub_a_staff'));

    $this->actingAs($staff)->post('/assets/import/execute', [])->assertStatus(403);
    $this->actingAs($staff)->post('/reports', [])->assertStatus(403);
});

test('Staff Grub tetap boleh membuka import PO Spare Part', function () {
    $staff = sgAkun('mekanik', 'SGUJI-A6', sgTeknisi('Staff Uji A6', 'SGUJI-A6', 'grub_a_staff'));

    // Tanpa berkas, controller membalas 302 (validasi); 403 akan berarti
    // middleware menolak, jadi 302 membuktikan route-nya diizinkan.
    $this->actingAs($staff)->post('/sparepart/impor', [])->assertStatus(302);
});

test('administrator tetap bisa mengubah laporan grub mana pun', function () {
    $pelaporB = sgTeknisi('Pelapor Grub B4', 'SGUJI-PB4', 'grub_b');
    $laporanB = sgLaporan($pelaporB, 'SGUJI-B-004', $this->tanggal);

    $this->actingAs($this->admin)
        ->put('/reports/' . $laporanB->id, [
            'work_description'      => 'Diubah administrator',
            'report_date'           => $this->tanggal,
            'work_duration_minutes' => 45,
        ])
        ->assertRedirect(route('reports.show', $laporanB));

    expect($laporanB->fresh()->work_description)->toBe('Diubah administrator');
});

test('halaman detail menandai boleh/tidaknya kelola laporan bagi staf', function () {
    $pelaporA = sgTeknisi('Pelapor Grub A5', 'SGUJI-PA5', 'grub_a');
    $pelaporB = sgTeknisi('Pelapor Grub B5', 'SGUJI-PB5', 'grub_b');
    $staff    = sgAkun('mekanik', 'SGUJI-A7', sgTeknisi('Staff Uji A7', 'SGUJI-A7', 'grub_a_staff'));
    $laporanA = sgLaporan($pelaporA, 'SGUJI-A-007', $this->tanggal);
    $laporanB = sgLaporan($pelaporB, 'SGUJI-B-007', $this->tanggal);

    $this->actingAs($staff)->get('/reports/' . $laporanA->id)
        ->assertStatus(200)
        ->assertSee('var BOLEH_KELOLA_HALAMAN = true;', false);

    $this->actingAs($staff)->get('/reports/' . $laporanB->id)
        ->assertStatus(200)
        ->assertSee('var BOLEH_KELOLA_HALAMAN = false;', false);
});

test('tampilan Shift memuat laporan staf sesuai grubnya', function () {
    $staffA = sgTeknisi('Staff Grub A tampil', 'SGUJI-SA', 'grub_a_staff');
    $staffB = sgTeknisi('Staff Grub B tampil', 'SGUJI-SB', 'grub_b_staff');

    sgLaporan($staffA, 'SGUJI-STAFF-A', $this->tanggal);
    sgLaporan($staffB, 'SGUJI-STAFF-B', $this->tanggal);

    $this->actingAs($this->admin)
        ->get('/reports?tampilan=shift&grub=grub_a&tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->assertSee('Pekerjaan SGUJI-STAFF-A')
        ->assertDontSee('Pekerjaan SGUJI-STAFF-B');
});
