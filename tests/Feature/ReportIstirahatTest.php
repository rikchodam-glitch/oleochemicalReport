<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Shift\JamKerjaService;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Pemotongan istirahat pada DURASI laporan (man hour).
 *
 * Aturan yang dikonfirmasi pemilik sistem 13/10/2026:
 *   - hanya pekerja NON-SHIFT (reguler & reguler staff) yang dipotong,
 *   - jendela istirahat dari config('cm.man_hour.jam_kerja'): Senin-Kamis &
 *     Sabtu 12:00-13:00, Jumat 11:00-13:00 (2 jam),
 *   - reguler pulang 16:00 (Sabtu 14:00), reguler staff pulang 17:00 dan
 *     Sabtu libur (hari libur tidak punya jendela istirahat),
 *   - pekerja shift (grub A-D) tetap 8 jam penuh tanpa potong istirahat
 *     (keputusan 26/09/2026),
 *   - laporan tanpa jam mulai/selesai tidak pernah dihitung ulang.
 *
 * Tanggal uji sengaja DIPATOK supaya hasilnya tidak bergantung hari saat suite
 * dijalankan: 2026-09-28 Senin, 2026-10-02 Jumat, 2026-09-26 Sabtu.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->reguler = Technician::create([
        'name'   => 'Teknisi Reguler Istirahat',
        'nik'    => 'IST001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->staf = Technician::create([
        'name'   => 'Teknisi Reguler Staff Istirahat',
        'nik'    => 'IST002',
        'group'  => 'reguler_staff',
        'status' => 'active',
    ]);

    $this->shift = Technician::create([
        'name'   => 'Teknisi Shift Istirahat',
        'nik'    => 'IST003',
        'group'  => 'grub_a',
        'status' => 'active',
    ]);
});

/**
 * Buat laporan uji berjam dengan nomor urut sendiri.
 *
 * @param  Technician  $teknisi  Teknisi pemilik laporan
 * @param  string      $tanggal  Tanggal laporan (Y-m-d)
 * @param  string|null $mulai    Jam mulai (null = tanpa jam)
 * @param  string|null $selesai  Jam selesai (null = tanpa jam)
 * @param  int|null    $durasi   Durasi tersimpan (menit) sebelum disamakan
 * @return Report                Laporan yang dibuat
 */
function laporanIstirahat(Technician $teknisi, string $tanggal, ?string $mulai, ?string $selesai, ?int $durasi): Report
{
    static $urut = 0;

    $urut++;

    return Report::create([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => $tanggal,
        'report_code'           => 'IST-' . str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
        'work_description'      => 'Uji potong istirahat',
        'work_duration_minutes' => $durasi,
        'start_time'            => $mulai,
        'end_time'              => $selesai,
        'status'                => 'completed',
    ]);
}

test('durasi non-shift dipotong istirahat Senin-Kamis 12:00-13:00', function () {
    $jam = app(JamKerjaService::class);

    // Contoh yang dilaporkan pemilik sistem: 10:00-16:00 = 6 jam, dikurangi
    // istirahat 1 jam = 5 jam = 300 menit (bukan 360).
    expect($jam->durasiKerja('reguler', Carbon::parse('2026-09-28'), '10:00', '16:00'))->toBe(300)
        ->and($jam->durasiKerja('reguler_staff', Carbon::parse('2026-09-29'), '08:00', '17:00'))->toBe(480)
        // Rentang yang mengapit istirahat sebagian: 12:30-13:30 = 60 - 30.
        ->and($jam->durasiKerja('reguler', Carbon::parse('2026-09-30'), '12:30', '13:30'))->toBe(30)
        ->and($jam->menitIstirahat('reguler', Carbon::parse('2026-09-28'), '10:00', '16:00'))->toBe(60);
});

test('Jumat istirahat dua jam 11:00-13:00', function () {
    $jam = app(JamKerjaService::class);

    expect($jam->durasiKerja('reguler', Carbon::parse('2026-10-02'), '08:00', '16:00'))->toBe(360)
        ->and($jam->durasiKerja('reguler_staff', Carbon::parse('2026-10-02'), '08:00', '17:00'))->toBe(420)
        ->and($jam->menitIstirahat('reguler', Carbon::parse('2026-10-02'), '08:00', '16:00'))->toBe(120);
});

test('Sabtu reguler dipotong, reguler staff Sabtu libur tidak dipotong', function () {
    $jam = app(JamKerjaService::class);

    // Sabtu reguler 08:00-14:00 dikurangi istirahat 12:00-13:00 = 300 menit.
    expect($jam->durasiKerja('reguler', Carbon::parse('2026-09-26'), '08:00', '14:00'))->toBe(300)
        // Sabtu adalah hari libur reguler staff: tidak ada jendela istirahat,
        // jadi rentangnya dipakai apa adanya.
        ->and($jam->durasiKerja('reguler_staff', Carbon::parse('2026-09-26'), '08:00', '17:00'))->toBe(540)
        ->and($jam->istirahatHari('reguler_staff', Carbon::parse('2026-09-26')))->toBe([]);
});

test('pekerja shift dan rentang di luar jendela istirahat tidak dipotong', function () {
    $jam = app(JamKerjaService::class);

    // Pekerja shift grub A: 8 jam penuh (keputusan 26/09/2026).
    expect($jam->durasiKerja('grub_a', Carbon::parse('2026-09-28'), '08:00', '16:00'))->toBe(480)
        ->and($jam->durasiKerja('grub_d', Carbon::parse('2026-10-02'), '16:00', '24:00'))->toBe(480)
        ->and($jam->istirahatHari('grub_a', Carbon::parse('2026-09-28')))->toBe([])
        // Rentang yang tidak bersinggungan dengan jendela istirahat.
        ->and($jam->durasiKerja('reguler', Carbon::parse('2026-09-28'), '08:00', '11:30'))->toBe(210)
        // Lintas tengah malam tidak menyentuh istirahat siang.
        ->and($jam->durasiKerja('reguler', Carbon::parse('2026-09-28'), '22:00', '06:00'))->toBe(480)
        // Group kosong / teknisi tanpa group: tidak dipotong (tidak bisa dinilai).
        ->and($jam->durasiKerja(null, Carbon::parse('2026-09-28'), '10:00', '16:00'))->toBe(360);
});

test('form edit menyimpan durasi yang sudah dipotong istirahat', function () {
    $laporan = laporanIstirahat($this->reguler, '2026-09-28', null, null, 120);

    $this->actingAs($this->admin)
        ->put('/reports/' . $laporan->id, [
            'work_description' => 'Pemeriksaan pompa uji potong istirahat',
            'report_date'      => '2026-09-28',
            'start_time'       => '10:00',
            'end_time'         => '16:00',
        ])
        ->assertRedirect();

    // 10:00-16:00 = 360 menit, dikurangi istirahat 12:00-13:00 = 300 menit.
    expect($laporan->fresh()->work_duration_minutes)->toBe(300);
});

test('form edit tidak memotong istirahat pekerja shift', function () {
    $laporan = laporanIstirahat($this->shift, '2026-09-28', null, null, 120);

    $this->actingAs($this->admin)
        ->put('/reports/' . $laporan->id, [
            'work_description' => 'Pekerjaan shift uji potong istirahat',
            'report_date'      => '2026-09-28',
            'start_time'       => '08:00',
            'end_time'         => '16:00',
        ])
        ->assertRedirect();

    expect($laporan->fresh()->work_duration_minutes)->toBe(480);
});

test('halaman detail menampilkan besarnya potongan istirahat', function () {
    $laporan = laporanIstirahat($this->reguler, '2026-09-28', '10:00', '16:00', 300);

    $this->actingAs($this->admin)
        ->get('/reports/' . $laporan->id)
        ->assertStatus(200)
        ->assertSee('id="potongan_istirahat"', false)
        ->assertSee('Istirahat dikurangi', false)
        ->assertSee('12:00-13:00', false)
        ->assertSee('jendelaIstirahat', false);
});

test('wizard Telegram memakai aturan istirahat pada rentang yang diketik', function () {
    $this->reguler->update(['telegram_id' => '77001']);

    $wizard = app(ReportWizardService::class);

    $wizard->saveState('77001', [
        'chat_id'                 => '77001',
        'text'                    => 'Pompa bocor',
        'step'                    => ReportWizardService::STEP_WORK_DURATION,
        'step_history'            => [],
        'work_classification'     => 'zpm3',
        'jenis_pekerjaan'         => 'CM/PM',
        'equipment_id'            => null,
        'equipment_ident'         => 'IST-P-01',
        'equipment_desc'          => 'Pompa Uji Istirahat',
        'report_date'             => '2026-09-28',
        'work_duration_minutes'   => null,
        'start_time'              => null,
        'end_time'                => null,
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => [],
        'collaborator_names'      => [],
        'created_at'              => now()->toIso8601String(),
    ]);

    $wizard->handleTextInput('77001', '10:00 sampai 16:00');

    $state = $wizard->getState('77001');

    expect($state['work_duration_minutes'])->toBe(300)
        ->and($state['start_time'])->toBe('10:00')
        ->and($state['end_time'])->toBe('16:00')
        ->and($state['step'])->toBe(ReportWizardService::STEP_FINDING);

    $wizard->destroyWizard('77001');
});

test('perintah pangkas istirahat tanpa --execute tidak mengubah data', function () {
    $laporan = laporanIstirahat($this->reguler, '2026-09-28', '10:00', '16:00', 360);

    $this->artisan('reports:pangkas-istirahat')->assertExitCode(0);

    expect($laporan->fresh()->work_duration_minutes)->toBe(360);
});

test('perintah pangkas istirahat --execute menyamakan durasi laporan lama', function () {
    $reguler  = laporanIstirahat($this->reguler, '2026-09-28', '10:00', '16:00', 360);
    $shift    = laporanIstirahat($this->shift, '2026-09-28', '10:00', '16:00', 360);
    $tanpaJam = laporanIstirahat($this->reguler, '2026-09-28', null, null, 120);

    $berkas = tempnam(sys_get_temp_dir(), 'pangkas-istirahat') . '.csv';

    // Parameter dikirim sebagai array (bukan satu string) supaya karakter
    // backslash pada jalur Windows tidak dimakan parser Symfony StringInput.
    $this->artisan('reports:pangkas-istirahat', [
        '--execute' => true,
        '--laporan' => $berkas,
    ])->assertExitCode(0);

    expect($reguler->fresh()->work_duration_minutes)->toBe(300)
        // Pekerja shift tetap dihitung penuh (360 menit).
        ->and($shift->fresh()->work_duration_minutes)->toBe(360)
        // Laporan tanpa jam mulai/selesai tidak pernah dihitung ulang.
        ->and($tanpaJam->fresh()->work_duration_minutes)->toBe(120)
        ->and(file_exists($berkas))->toBeTrue();

    $isi = file_get_contents($berkas);

    expect($isi)->toContain('potongan_istirahat')
        ->and($isi)->toContain($reguler->report_code)
        ->and($isi)->toContain('berubah');

    @unlink($berkas);
});
