<?php

use App\Models\Report;
use App\Services\Shift\ManHourService;
use App\Services\Telegram\Traits\WizardUtilityTrait;
use App\Services\Traits\AiKeywordParserTrait;

/**
 * Satu sumber aturan jam kerja laporan (12/10/2026).
 *
 * Sebelum perbaikan, aturan rentang jam ditulis di EMPAT tempat dengan hasil
 * yang TIDAK sama:
 *   - Report::durasiDariJam()             : jam sama = 0 menit, 24:00 ditolak
 *   - WizardUtilityTrait::calcRangeDiff() : jam sama = 24 jam (salah)
 *   - AiKeywordParserTrait                : jam sama = 24 jam (salah)
 *   - ManHourService::menitDariJam()      : explode() tanpa pemeriksaan format
 * Uji di berkas ini menjaga agar ketiga jalur lain mendelegasikan ke Report
 * sehingga hasilnya sama di seluruh aplikasi (form web, wizard Telegram,
 * analisa AI, dan perhitungan man hour).
 */

/**
 * Kelas bantu: memakai trait wizard apa adanya supaya method protected-nya
 * bisa dipanggil langsung dari pengujian.
 *
 * @return object Objek anonim berisi method parse()
 */
function rjsWizard()
{
    return new class {
        use WizardUtilityTrait;

        /**
         * Buka parseWorkTimeRange() untuk pengujian.
         *
         * @param  string     $text Teks rentang waktu dari teknisi
         * @return array|null       Hasil parsing (start, end, minutes) atau null
         */
        public function parse(string $text): ?array
        {
            return $this->parseWorkTimeRange($text);
        }
    };
}

/**
 * Kelas bantu: memakai trait parser AI apa adanya untuk menguji pembacaan durasi.
 *
 * @return object Objek anonim berisi method durasi()
 */
function rjsParserAi()
{
    return new class {
        use AiKeywordParserTrait;

        /**
         * Buka parseWorkDurationMinutes() untuk pengujian.
         *
         * @param  string   $text Teks laporan
         * @return int|null       Menit hasil ekstraksi atau null
         */
        public function durasi(string $text): ?int
        {
            return $this->parseWorkDurationMinutes($text);
        }
    };
}

test('menitDariJam publik dan mengenali 24:00 sebagai akhir hari', function () {
    expect(Report::menitDariJam('08:05'))->toBe(485)
        ->and(Report::menitDariJam('00:00'))->toBe(0)
        ->and(Report::menitDariJam('24:00'))->toBe(1440)
        // Akhir hari hanya persis 24:00, bukan 24:30.
        ->and(Report::menitDariJam('24:30'))->toBeNull()
        ->and(Report::menitDariJam('25:00'))->toBeNull()
        ->and(Report::menitDariJam('08:60'))->toBeNull()
        ->and(Report::menitDariJam('pagi'))->toBeNull()
        ->and(Report::menitDariJam(''))->toBeNull()
        ->and(Report::menitDariJam(null))->toBeNull()
        ->and(Report::MENIT_SEHARI)->toBe(1440)
        ->and(Report::JAM_AKHIR_HARI)->toBe('24:00');
});

test('durasiDariJam memakai satu aturan: jam sama ditolak, 24:00 diterima', function () {
    expect(Report::durasiDariJam('08:00', '09:30'))->toBe(90)
        // Lintas tengah malam.
        ->and(Report::durasiDariJam('22:00', '06:00'))->toBe(480)
        // Jam selesai 24:00 = akhir hari.
        ->and(Report::durasiDariJam('23:00', '24:00'))->toBe(60)
        ->and(Report::durasiDariJam('16:00', '24:00'))->toBe(480)
        // Jam sama bukan rentang; jam mulai 24:00 tidak wajar.
        ->and(Report::durasiDariJam('08:00', '08:00'))->toBeNull()
        ->and(Report::durasiDariJam('24:00', '08:00'))->toBeNull()
        ->and(Report::durasiDariJam('24:00', '24:00'))->toBeNull();
});

test('wizard Telegram memakai aturan Report untuk rentang jam', function () {
    $wizard = rjsWizard();

    expect($wizard->parse('08:00 sampai 09:00')['minutes'])->toBe(60)
        ->and($wizard->parse('jam 9 - jam 10')['minutes'])->toBe(60)
        ->and($wizard->parse('pukul 09 sd 10.30')['minutes'])->toBe(90)
        // Rentang melewati tengah malam.
        ->and($wizard->parse('22:00 sampai 06:00')['minutes'])->toBe(480);

    // Jam selesai 24:00 diterima dan disimpan apa adanya.
    $akhirHari = $wizard->parse('23:00 sampai 24:00');

    expect($akhirHari['minutes'])->toBe(60)
        ->and($akhirHari['start'])->toBe('23:00')
        ->and($akhirHari['end'])->toBe('24:00');

    // Jam sama BUKAN rentang (dulu keliru dihitung 24 jam = 1440 menit) dan
    // jam mulai 24:00 juga ditolak.
    expect($wizard->parse('08:00 sampai 08:00'))->toBeNull()
        ->and($wizard->parse('jam 9 - jam 9'))->toBeNull()
        ->and($wizard->parse('24:00 sampai 06:00'))->toBeNull();
});

test('parser AI memakai aturan Report untuk rentang jam', function () {
    $parser = rjsParserAi();

    expect($parser->durasi('Pompa mati 08:00 sampai 09:30'))->toBe(90)
        ->and($parser->durasi('Kerja 08.00 s/d 09.30'))->toBe(90)
        ->and($parser->durasi('Shift 22:00-06:00'))->toBe(480)
        ->and($parser->durasi('Sampai 23:00-24:00'))->toBe(60)
        // Jam sama bukan rentang (dulu 1440 menit), jam mulai 24:00 ditolak.
        ->and($parser->durasi('Uji 08:00-08:00'))->toBeNull()
        ->and($parser->durasi('Uji 24:00-06:00'))->toBeNull();
});

test('ManHourService membaca jam lewat Report::menitDariJam()', function () {
    $layanan = app(ManHourService::class);
    $metode  = new ReflectionMethod($layanan, 'menitDariJam');
    $metode->setAccessible(true);

    // Jendela shift malam berakhir 24:00 (config cm.man_hour.windows).
    expect($metode->invoke($layanan, '24:00'))->toBe(1440)
        ->and($metode->invoke($layanan, '16:00'))->toBe(960)
        // explode() lama menghitung 25:00 = 1500 menit; kini ditolak jadi 0
        // supaya laporan berjam rusak tidak dianggap di dalam jendela shift.
        ->and($metode->invoke($layanan, '25:00'))->toBe(0);
});
