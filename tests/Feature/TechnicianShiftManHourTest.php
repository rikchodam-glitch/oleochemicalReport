<?php

use App\Models\Report;
use App\Models\ShiftSchedule;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\User;
use App\Services\Shift\ManHourService;
use App\Services\Shift\ShiftScheduleImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Jadwal shift & man hour teknisi:
 * - Import Excel jadwal (periode 26-25) mengisi technician_shifts per tanggal.
 * - Target man hour 8 jam/hari mengikuti kode shift (x = OFF, 1/2/3 = shift,
 *   R = reguler) dan baris salinan multi alat tidak ikut dihitung.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisiStaf = Technician::create([
        'name'         => 'Teknisi Staf Uji',
        'nik'          => 'NIK-STAF-1',
        'group'        => 'reguler',
        'status'       => 'active',
        'telegram_id'  => '66001',
    ]);

    $this->teknisiShift = Technician::create([
        'name'         => 'Teknisi Shift Uji',
        'nik'          => 'NIK-LAIN-2',
        'group'        => 'grub_a',
        'status'       => 'active',
        'telegram_id'  => '66002',
    ]);
});

/**
 * Buat file Excel jadwal shift sederhana (blok NAME STAF dan NAME SHIFT).
 *
 * @param  string $nikStaf   NIK pada blok staf (reguler)
 * @param  string $nikShift  NIK pada blok shift
 * @param  string $namaShift Nama pada blok shift (untuk uji pencocokan lewat nama)
 * @return string            Path file xlsx sementara
 */
function buatFileJadwalShift(string $nikStaf, string $nikShift, string $namaShift): string
{
    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $tulis = function (int $baris, array $kolom) use ($sheet): void {
        foreach ($kolom as $index => $nilai) {
            if ($nilai === null) {
                continue;
            }

            $sheet->setCellValue([$index + 1, $baris], $nilai);
        }
    };

    // Blok 1: staf reguler (hanya menulis REGULER di kolom tanggal pertama).
    $tulis(1, ['SHIFT SCHEDULE MAINTENANCE PERIODE 26 September - 28 September 2026']);
    $tulis(2, ['No', 'NIK', 'NAME STAF', null, 'September']);
    $tulis(3, [null, null, null, null, 'Sat', 'Sun', 'Mon']);
    $tulis(4, [null, null, null, null, 26, 27, 28]);
    $tulis(5, [1, $nikStaf, 'Teknisi Staf Uji', null, 'REGULER']);

    // Blok 2: pekerja shift group A.
    $tulis(7, ['SHIFT SCHEDULE MAINTENANCE PERIODE 26 September - 28 September 2026']);
    $tulis(8, ['No', 'NIK', 'NAME SHIFT', 'Group', 'September']);
    $tulis(9, [null, null, null, null, 'Sat', 'Sun', 'Mon']);
    $tulis(10, [null, null, null, null, 26, 27, 28]);
    $tulis(11, [1, $nikShift, $namaShift, 'A', 2, 'x', 1]);
    $tulis(12, [2, 'NIK-BELUM-ADA', 'Orang Belum Terdaftar', 'B', 1, 1, 1]);

    $path = tempnam(sys_get_temp_dir(), 'jadwal_') . '.xlsx';

    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

/**
 * Buat laporan uji man hour.
 *
 * @param  Technician $teknisi Teknisi pemilik laporan
 * @param  string     $tanggal Tanggal laporan Y-m-d
 * @param  int        $menit   Durasi kerja (menit)
 * @param  string|null $mulai  Jam mulai HH:MM (nullable)
 * @param  array      $tambahan Kolom tambahan (mis. multi_of)
 * @return Report              Laporan yang dibuat
 */
function buatLaporanManHour(Technician $teknisi, string $tanggal, int $menit, ?string $mulai = null, array $tambahan = []): Report
{
    return Report::create(array_merge([
        'technician_id'         => $teknisi->id,
        'creator_id'            => $teknisi->id,
        'report_date'           => $tanggal,
        'report_code'           => 'RPT-' . str_replace('-', '', $tanggal) . '-' . random_int(1000, 9999),
        'work_description'      => 'Pekerjaan uji man hour',
        'work_duration_minutes' => $menit,
        'start_time'            => $mulai,
        'status'                => 'completed',
    ], $tambahan));
}

test('import jadwal shift membaca blok staf & shift serta mencatat yang belum cocok', function () {
    $path = buatFileJadwalShift('NIK-STAF-1', 'NIK-BEDA-9', 'Teknisi Shift Uji');

    $hasil = app(ShiftScheduleImportService::class)->import($path, $this->admin->id);

    expect($hasil['periode_mulai'])->toBe('2026-09-26')
        ->and($hasil['periode_selesai'])->toBe('2026-09-28')
        ->and($hasil['jumlah_baris'])->toBe(3)
        ->and($hasil['jumlah_terpetakan'])->toBe(2)
        ->and($hasil['jumlah_tidak_cocok'])->toBe(1)
        ->and($hasil['per_section']['NAME STAF'])->toBe(1)
        ->and($hasil['per_section']['NAME SHIFT'])->toBe(2)
        ->and($hasil['per_kode']['R'])->toBe(3)
        ->and($hasil['per_kode']['x'])->toBe(1)
        ->and($hasil['dicocokkan_lewat_nama'])->toHaveCount(1);

    // Riwayat import tersimpan sebagai satu baris jadwal.
    $jadwal = ShiftSchedule::latest('id')->first();

    expect($jadwal)->not->toBeNull()
        ->and($jadwal->jumlah_terpetakan)->toBe(2)
        ->and($jadwal->jumlah_tidak_cocok)->toBe(1)
        ->and($jadwal->periode_label)->toBe('26/09/2026 - 28/09/2026');

    // Staf reguler: kode R untuk semua tanggal, terpetakan lewat NIK.
    $staf = TechnicianShift::where('nik', 'NIK-STAF-1')->orderBy('shift_date')->get();

    expect($staf)->toHaveCount(3)
        ->and($staf->pluck('shift_code')->unique()->all())->toBe(['R'])
        ->and($staf->first()->technician_id)->toBe($this->teknisiStaf->id)
        ->and($staf->first()->targetMenit())->toBe(480);

    // Pekerja shift: kode sesuai jadwal, terpetakan lewat nama karena NIK beda.
    $shift = TechnicianShift::where('nama', 'Teknisi Shift Uji')->orderBy('shift_date')->get();

    expect($shift->pluck('shift_code')->all())->toBe(['2', 'x', '1'])
        ->and($shift->first()->technician_id)->toBe($this->teknisiShift->id)
        ->and($shift->first()->group_code)->toBe('A')
        ->and($shift->first()->label())->toBe('Shift 2 (16.00-24.00)')
        ->and($shift->first()->targetMenit())->toBe(480)
        ->and($shift->get(1)->isOff())->toBeTrue()
        ->and($shift->get(1)->targetMenit())->toBe(0);

    // Orang yang belum terdaftar tetap tercatat tanpa technician_id.
    expect(TechnicianShift::where('nik', 'NIK-BELUM-ADA')->first()?->technician_id)->toBeNull();

    @unlink($path);
});

test('man hour memakai target jadwal, mengecualikan salinan multi alat, dan menandai anomali', function () {
    // Jadwal: 26 = shift 2, 27 = OFF, 28 = shift 1.
    foreach (['2026-09-26' => '2', '2026-09-27' => 'x', '2026-09-28' => '1'] as $tanggal => $kode) {
        TechnicianShift::create([
            'technician_id' => $this->teknisiShift->id,
            'nik'           => 'NIK-LAIN-2',
            'nama'          => 'Teknisi Shift Uji',
            'group_code'    => 'A',
            'section_label' => 'NAME SHIFT',
            'shift_date'    => $tanggal,
            'shift_code'    => $kode,
        ]);
    }

    // Hari kerja shift 2 dengan jam sesuai shift, plus salinan multi alat
    // (tidak boleh menambah jam kerja).
    $induk = buatLaporanManHour($this->teknisiShift, '2026-09-26', 480, '16:30');
    buatLaporanManHour($this->teknisiShift, '2026-09-26', 480, '16:30', ['multi_of' => $induk->id]);

    // Bekerja pada hari OFF dan laporan di luar jendela shift 1 (05:00).
    buatLaporanManHour($this->teknisiShift, '2026-09-27', 300, '09:00');
    buatLaporanManHour($this->teknisiShift, '2026-09-28', 480, '05:00');

    $layanan = app(ManHourService::class);
    $harian  = $layanan->harian(
        $this->teknisiShift->fresh(),
        Carbon::parse('2026-09-26'),
        Carbon::parse('2026-09-28')
    );

    expect($harian)->toHaveCount(3)
        ->and($harian[0]['menit'])->toBe(480)
        ->and($harian[0]['target_menit'])->toBe(480)
        ->and($harian[0]['status'])->toBe('terpenuhi')
        ->and($harian[0]['persen'])->toBe(100)
        ->and($harian[0]['catatan'])->toBe([])
        // Hari OFF: target nol, tetap ditandai bila ada pekerjaan.
        ->and($harian[1]['target_menit'])->toBe(0)
        ->and($harian[1]['status'])->toBe('lebih')
        ->and(implode(' ', $harian[1]['catatan']))->toContain('hari OFF')
        // Laporan di luar jam shift diberi catatan.
        ->and(implode(' ', $harian[2]['catatan']))->toContain('di luar jam');

    $ringkasan = $layanan->ringkasan($harian);

    expect($ringkasan['total_menit'])->toBe(1260)
        ->and($ringkasan['total_target_menit'])->toBe(960)
        ->and($ringkasan['hari_kerja'])->toBe(2)
        ->and($ringkasan['hari_libur'])->toBe(1)
        ->and($ringkasan['hari_kerja_off'])->toBe(1)
        ->and($ringkasan['laporan_luar_shift'])->toBe(1);
});

test('halaman teknisi menampilkan man hour, rincian harian, dan kolom jam kerja', function () {
    TechnicianShift::create([
        'technician_id' => $this->teknisiStaf->id,
        'nik'           => 'NIK-STAF-1',
        'nama'          => 'Teknisi Staf Uji',
        'section_label' => 'NAME STAF',
        'shift_date'    => now()->toDateString(),
        'shift_code'    => 'R',
    ]);

    buatLaporanManHour($this->teknisiStaf, now()->toDateString(), 240, '08:00');

    // Daftar teknisi: panel import jadwal + kolom jam kerja.
    $this->actingAs($this->admin)
        ->get('/technicians')
        ->assertStatus(200)
        ->assertSee('Jam Kerja', false)
        ->assertSee('Import Jadwal Shift', false)
        ->assertSee('Riwayat Import Jadwal', false);

    // Detail teknisi: kartu man hour, rincian harian, dan grafik.
    $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisiStaf->id)
        ->assertStatus(200)
        ->assertSee('Man Hour', false)
        ->assertSee('Rincian Man Hour Harian', false)
        ->assertSee('grafikManHour', false)
        ->assertSee('Pekerjaan uji man hour', false)
        ->assertSee('4,0 j', false);
});

test('upload jadwal shift lewat halaman teknisi menyimpan hasil import', function () {
    $path = buatFileJadwalShift('NIK-STAF-1', 'NIK-BEDA-9', 'Teknisi Shift Uji');

    $this->actingAs($this->admin)
        ->post(route('technicians.shift-schedule.store'), [
            'file' => new UploadedFile($path, 'jadwal-shift.xlsx', null, null, true),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(ShiftSchedule::count())->toBe(1)
        ->and(TechnicianShift::count())->toBe(9)
        ->and(TechnicianShift::where('technician_id', $this->teknisiStaf->id)->count())->toBe(3)
        ->and(TechnicianShift::where('technician_id', $this->teknisiShift->id)->count())->toBe(3);

    @unlink($path);
});

test('upload file bukan excel ditolak validasi', function () {
    $this->actingAs($this->admin)
        ->post(route('technicians.shift-schedule.store'), [
            'file' => UploadedFile::fake()->create('jadwal.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');

    expect(ShiftSchedule::count())->toBe(0)
        ->and(TechnicianShift::count())->toBe(0);
});
