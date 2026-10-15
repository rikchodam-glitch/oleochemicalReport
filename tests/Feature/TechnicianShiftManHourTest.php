<?php

use App\Models\Report;
use App\Models\ShiftSchedule;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\User;
use App\Services\Shift\JamKerjaService;
use App\Services\Shift\ManHourService;
use App\Services\Shift\ShiftPatternService;
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
        // Kode R memakai jam kerja reguler: Sabtu 26/09 5 jam, Minggu 27/09 libur,
        // Senin 28/09 7 jam (08.00-16.00 dikurangi istirahat 1 jam).
        ->and($staf->map(fn (TechnicianShift $baris): int => $baris->targetMenit())->all())->toBe([300, 0, 420]);

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

    // Laporan hari OFF (2026-09-27, 300 menit tanpa jam selesai) seluruhnya
    // dihitung LEMBUR sehingga man hour normalnya 0: 480 + 0 + 480 = 960 menit.
    // Sebelum perbaikan 14/10/2026 durasi itu ikut dihitung jam kerja normal.
    expect($ringkasan['total_menit'])->toBe(960)
        ->and($ringkasan['total_lembur_menit'])->toBe(300)
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

    // Daftar teknisi: panel jadwal + kolom jam kerja.
    $this->actingAs($this->admin)
        ->get('/technicians')
        ->assertStatus(200)
        ->assertSee('Jam Kerja', false)
        ->assertSee('Jadwal Shift', false)
        // Tampilan import sudah dihapus: hanya tombol lihat jadwal.
        ->assertDontSee('Buat Jadwal Otomatis', false)
        ->assertDontSee('Riwayat Import Jadwal', false)
        ->assertDontSee('Upload jadwal', false)
        ->assertSee('Lihat Kalender Teknisi', false)
        ->assertSee('Kalender Teknisi', false)
        ->assertSee('modal-jadwal-bulanan', false)
        ->assertSee('Shift 1 (08.00-16.00)', false);

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

test('pola rotasi 20 hari cocok dengan jadwal asli 4 periode', function () {
    $pola = app(ShiftPatternService::class);

    // Siklus: 5 hari Shift 1, 2 OFF, 5 hari Shift 3, 2 OFF, 5 hari Shift 2, 1 OFF.
    expect(implode('', $pola->siklus()))->toBe('11111xx33333xx22222x');

    // Acuan 26 Juni 2026 (terverifikasi: 976 dari 976 hari-orang pada 4 file cocok).
    expect($pola->kode('grub_a', Carbon::parse('2026-06-26')))->toBe('1')
        ->and($pola->kode('grub_b', Carbon::parse('2026-06-26')))->toBe('x')
        ->and($pola->kode('grub_c', Carbon::parse('2026-06-26')))->toBe('2')
        ->and($pola->kode('grub_d', Carbon::parse('2026-06-26')))->toBe('3')
        // Periode 26 September - 25 Oktober 2026 (sesuai file jadwal terakhir).
        ->and($pola->kode('grub_a', Carbon::parse('2026-09-26')))->toBe('2')
        ->and($pola->kode('grub_b', Carbon::parse('2026-09-26')))->toBe('1')
        ->and($pola->kode('grub_c', Carbon::parse('2026-09-26')))->toBe('3')
        ->and($pola->kode('grub_d', Carbon::parse('2026-09-26')))->toBe('x')
        ->and($pola->kode('grub_a', Carbon::parse('2026-10-01')))->toBe('x')
        // Teknisi non-shift tidak diproyeksikan lewat pola.
        ->and($pola->kode('reguler', Carbon::parse('2026-09-26')))->toBeNull()
        ->and($pola->kode('reguler_staff', Carbon::parse('2026-09-26')))->toBeNull();
});

test('target reguler dan reguler staff mengikuti jam kerja dan istirahat', function () {
    $jam = app(JamKerjaService::class);

    // Senin 28/09/2026: reguler 08.00-16.00 istirahat 1 jam (7 jam),
    // reguler staff 08.00-17.00 istirahat 1 jam (8 jam).
    expect($jam->targetMenit('reguler', Carbon::parse('2026-09-28')))->toBe(420)
        ->and($jam->targetMenit('reguler_staff', Carbon::parse('2026-09-28')))->toBe(480)
        // Jumat 02/10/2026: istirahat 2 jam (11.00-13.00).
        ->and($jam->targetMenit('reguler', Carbon::parse('2026-10-02')))->toBe(360)
        ->and($jam->targetMenit('reguler_staff', Carbon::parse('2026-10-02')))->toBe(420)
        // Sabtu 26/09/2026: reguler 08.00-14.00 istirahat 1 jam (5 jam), staff libur.
        ->and($jam->targetMenit('reguler', Carbon::parse('2026-09-26')))->toBe(300)
        ->and($jam->targetMenit('reguler_staff', Carbon::parse('2026-09-26')))->toBe(0)
        // Minggu 27/09/2026 libur untuk keduanya.
        ->and($jam->targetMenit('reguler', Carbon::parse('2026-09-27')))->toBe(0)
        ->and($jam->targetMenit('reguler_staff', Carbon::parse('2026-09-27')))->toBe(0);
});

test('man hour memakai proyeksi pola saat jadwal belum diimport', function () {
    TechnicianShift::query()->delete();

    $harian = app(ManHourService::class)->harian(
        $this->teknisiShift,
        Carbon::parse('2026-09-30'),
        Carbon::parse('2026-10-02')
    );

    expect($harian)->toHaveCount(3)
        // Group A: 30/09 = hari ke-5 Shift 2, 01/10 = OFF, 02/10 = Shift 1.
        ->and($harian[0]['label_shift'])->toBe('Shift 2 (16.00-24.00)')
        ->and($harian[0]['target_menit'])->toBe(480)
        ->and($harian[1]['kode'])->toBe('X')
        ->and($harian[1]['target_menit'])->toBe(0)
        ->and($harian[2]['label_shift'])->toBe('Shift 1 (08.00-16.00)')
        ->and($harian[2]['target_menit'])->toBe(480);
});

test('jadwal shift dipastikan otomatis sampai beberapa bulan ke depan dan idempoten', function () {
    TechnicianShift::query()->delete();

    $pola   = app(ShiftPatternService::class);
    $sampai = now()->startOfMonth()->addMonths(3)->endOfMonth();

    $jumlah = $pola->pastikanSampai($sampai);
    $hari   = (int) now()->startOfDay()->diffInDays($sampai) + 1;

    expect($jumlah)->toBe($hari)
        ->and(TechnicianShift::where('technician_id', $this->teknisiShift->id)->count())->toBe($hari)
        ->and(TechnicianShift::where('technician_id', $this->teknisiShift->id)->orderBy('shift_date')->first()->section_label)->toBe('POLA')
        // Teknisi reguler tidak dibuatkan jadwal shift.
        ->and(TechnicianShift::where('technician_id', $this->teknisiStaf->id)->count())->toBe(0);

    // Dijalankan ulang tidak menambah baris (idempoten).
    expect($pola->pastikanSampai($sampai))->toBe(0)
        ->and(TechnicianShift::where('technician_id', $this->teknisiShift->id)->count())->toBe($hari);
});

test('preview jadwal bulanan berisi 4 group dan rekap hari', function () {
    $jadwal = app(ShiftPatternService::class)->jadwalBulan(Carbon::parse('2026-10-01'));

    // Okktober 2026 = 31 hari, 4 group pekerja shift.
    expect($jadwal['label'])->toBe('Oktober 2026')
        ->and($jadwal['hari'])->toHaveCount(31)
        ->and($jadwal['grup'])->toHaveCount(4)
        // Group A: 01/10 OFF, 26/10 Shift 1 (sesuai file jadwal asli).
        ->and($jadwal['grup'][0]['label'])->toBe('Grub A')
        ->and($jadwal['grup'][0]['kode'][0])->toBe('x')
        ->and($jadwal['grup'][0]['kode'][25])->toBe('1')
        // Rekap hari group A: 10 Shift 1, 5 Shift 2, 8 Shift 3, 8 OFF (total 31).
        ->and($jadwal['grup'][0]['jumlah'])->toBe(['1' => 10, '2' => 5, '3' => 8, 'x' => 8])
        ->and($jadwal['grup'][0]['hari_kerja'])->toBe(23)
        ->and($jadwal['grup'][0]['hari_off'])->toBe(8)
        // Navigasi bulan sebelumnya & berikutnya.
        ->and($jadwal['bulan_sebelumnya'])->toBe('2026-09')
        ->and($jadwal['bulan_berikutnya'])->toBe('2026-11');
});

test('panel jadwal menampilkan ringkasan data aktual bukan angka import lama', function () {
    TechnicianShift::create([
        'technician_id' => $this->teknisiShift->id,
        'nik'           => 'NIK-LAIN-2',
        'nama'          => 'Teknisi Shift Uji',
        'group_code'    => 'A',
        'section_label' => 'POLA',
        'shift_date'    => '2026-09-26',
        'shift_code'    => '2',
    ]);

    // Satu baris tanpa teknisi (mis. NIK belum terdaftar) tetap terhitung.
    TechnicianShift::create([
        'nik'           => 'NIK-BELUM-ADA',
        'nama'          => 'Belum Terdaftar',
        'section_label' => 'POLA',
        'shift_date'    => '2026-09-26',
        'shift_code'    => '1',
    ]);

    $this->actingAs($this->admin)
        ->get('/technicians')
        ->assertStatus(200)
        ->assertSee('Jadwal tersimpan: 2 baris', false)
        ->assertSee('untuk 1 teknisi', false)
        ->assertSee('1 baris belum tersambung ke teknisi', false)
        // Angka historis hasil import tidak ditampilkan lagi.
        ->assertDontSee('orang dibaca', false)
        ->assertDontSee('belum cocok', false);
});

test('sel shift 3 pada popup jadwal memakai warna biru yang terbaca', function () {
    // Warna lama (indigo) tidak ada di CSS build sehingga sel tampil putih
    // dengan tulisan putih (tak terbaca); sekarang memakai biru.
    $this->actingAs($this->admin)
        ->get('/technicians')
        ->assertStatus(200)
        ->assertSee('bg-blue-600 text-white', false)
        ->assertSee('w-4 h-4 rounded bg-blue-600', false)
        ->assertDontSee('bg-indigo-500', false);
});

test('target shift 8 jam penuh tanpa potong istirahat', function () {
    // Aturan yang dikonfirmasi 26/09/2026: pekerja shift tidak dipotong istirahat.
    foreach (['1' => 'Shift 1 (08.00-16.00)', '2' => 'Shift 2 (16.00-24.00)', '3' => 'Shift 3 (00.00-08.00)'] as $kode => $label) {
        $shift = new TechnicianShift([
            'technician_id' => $this->teknisiShift->id,
            'nik'           => 'NIK-LAIN-2',
            'nama'          => 'Teknisi Shift Uji',
            'shift_date'    => '2026-09-28',
            'shift_code'    => $kode,
        ]);

        expect($shift->label('grub_a'))->toBe($label)
            ->and($shift->targetMenit('grub_a'))->toBe(480)
            ->and($shift->jendela('grub_a'))->toBe(['mulai' => (string) config('cm.man_hour.windows.' . $kode . '.mulai'), 'selesai' => (string) config('cm.man_hour.windows.' . $kode . '.selesai')]);
    }

    // Hari OFF tetap tanpa target.
    $libur = new TechnicianShift([
        'technician_id' => $this->teknisiShift->id,
        'nik'           => 'NIK-LAIN-2',
        'nama'          => 'Teknisi Shift Uji',
        'shift_date'    => '2026-09-28',
        'shift_code'    => 'x',
    ]);

    expect($libur->targetMenit('grub_a'))->toBe(0)
        ->and($libur->jendela('grub_a'))->toBeNull();
});

test('ringkasan man hour menyertakan laju per minggu dan per hari kerja', function () {
    // Jadwal 3 hari: 26 (Shift 2), 27 (OFF), 28 (Shift 1).
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

    buatLaporanManHour($this->teknisiShift, '2026-09-26', 450, '16:30');
    buatLaporanManHour($this->teknisiShift, '2026-09-27', 300, '09:00');
    buatLaporanManHour($this->teknisiShift, '2026-09-28', 480, '09:00');

    $layanan   = app(ManHourService::class);
    $ringkasan = $layanan->ringkasan($layanan->harian(
        $this->teknisiShift->fresh(),
        Carbon::parse('2026-09-26'),
        Carbon::parse('2026-09-28')
    ));

    // 3 hari = 3/7 minggu. Man hour normal hanya dari hari kerja: 450 (26/09) +
    // 480 (28/09) = 930 menit = 15,5 jam -> 15,5 / (3/7) = 36,2 j/minggu; 2 hari
    // kerja terhitung. Pekerjaan hari OFF (27/09) dihitung LEMBUR sehingga tidak
    // menambah realisasi normal (aturan 14/10/2026).
    expect($ringkasan['jumlah_hari'])->toBe(3)
        ->and($ringkasan['hari_kerja'])->toBe(2)
        ->and($ringkasan['realisasi_per_minggu'])->toBe(36.2)
        ->and($ringkasan['target_per_minggu'])->toBe(37.3)
        ->and($ringkasan['realisasi_per_hari_kerja'])->toBe(7.8)
        ->and($ringkasan['target_per_hari_kerja'])->toBe(8.0);
});

test('halaman teknisi menampilkan indikator laju per minggu', function () {
    TechnicianShift::create([
        'technician_id' => $this->teknisiStaf->id,
        'nik'           => 'NIK-STAF-1',
        'nama'          => 'Teknisi Staf Uji',
        'section_label' => 'NAME STAF',
        'shift_date'    => now()->toDateString(),
        'shift_code'    => 'R',
    ]);

    buatLaporanManHour($this->teknisiStaf, now()->toDateString(), 240, '08:00');

    // Daftar teknisi: laju target per minggu di kolom Jam Kerja.
    $this->actingAs($this->admin)
        ->get('/technicians')
        ->assertStatus(200)
        ->assertSee('j/minggu', false);

    // Detail teknisi: laju realisasi & target per minggu + per hari kerja.
    $this->actingAs($this->admin)
        ->get('/technicians/' . $this->teknisiStaf->id)
        ->assertStatus(200)
        ->assertSee('Laju per minggu', false)
        ->assertSee('per hari kerja', false)
        ->assertSee('Pembanding setara antar tipe', false);
});

test('popup jadwal bulanan bisa digeser ke bulan berikutnya', function () {
    $this->actingAs($this->admin)
        ->get('/technicians?jadwal_bulan=2026-11')
        ->assertStatus(200)
        ->assertSee('November 2026', false)
        ->assertSee('Kalender Teknisi', false);

    // Parameter tidak valid tidak membuat halaman error (pakai bulan berjalan).
    $this->actingAs($this->admin)
        ->get('/technicians?jadwal_bulan=ngawur')
        ->assertStatus(200)
        ->assertSee('Kalender Teknisi', false);
});
