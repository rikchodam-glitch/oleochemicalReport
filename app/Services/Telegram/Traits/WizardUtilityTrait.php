<?php

namespace App\Services\Telegram\Traits;

use Illuminate\Support\Carbon;

/**
 * WizardUtilityTrait
 *
 * Kumpulan utilitas umum yang dipakai di seluruh wizard:
 *   - parseDurationToMinutes() : Parse teks durasi ke integer menit
 *   - formatDuration()         : Format menit ke string ramah-pengguna
 *   - parseDateFromText()      : Parse teks tanggal laporan, hasil berupa array
 *                                ['date' => Y-m-d|null, 'status' => ...]
 *   - formatIndonesianDate()   : Format tanggal Y-m-d ke string Bahasa Indonesia
 *   - equipmentLabel()         : Label ringkas equipment/area dari state
 *   - createInitialState()     : Buat state awal wizard baru
 *   - errorResponse()          : Bangun struktur respons error standar
 *
 * Trait ini tidak bergantung pada method atau properti lain dari kelas pemakai
 * (self-contained), sehingga aman di-use di kelas manapun dalam wizard.
 */
trait WizardUtilityTrait
{
    /**
     * Batas maksimal jumlah hari mundur yang diizinkan untuk tanggal laporan.
     * Tanggal yang terdeteksi lebih lampau dari ini akan ditolak (status 'too_old').
     */
    public const REPORT_DATE_MAX_BACKDATE_DAYS = 3;

    // =========================================================
    // FORMAT & PARSING DURASI
    // =========================================================

    /**
     * Parse teks durasi menjadi menit.
     * Format yang didukung:
     *   - "2 jam 30 menit"
     *   - "2 jam" atau "1.5 jam" atau "1,5 jam"
     *   - "30 menit"
     *   - Rentang waktu: "08:00 sampai 09:00", "8:00-9:00", "08.00 s/d 09.30", "08:00 sd 10:00"
     *   - Jam:menit numerik: "1:30" (= 90 menit), "0:45" (= 45 menit)
     *   - Format singkat: "1h30m", "1h 30m", "0h45m"
     *   - "90" (angka saja, dianggap menit)
     * Hasil divalidasi: tidak boleh 0/negatif dan tidak boleh lebih dari 24 jam (1440 menit).
     *
     * @param  string   $text Input teks durasi dari teknisi
     * @return int|null       Durasi dalam menit, null jika format tidak dikenali/tidak valid
     */
    public function parseDurationToMinutes(string $text): ?int
    {
        $text = strtolower(trim($text));

        // Format rentang waktu: "08:00 sampai 09:00", "8:00-9:00", "08.00 s/d 09.30", "08:00 sd 10:00"
        // Dicek lebih dulu karena mengandung pola jam:menit yang bisa salah tertangkap pola lain
        if (preg_match('/(\d{1,2})[:.](\d{2})\s*(?:sampai|s\/d|sd|hingga|\-)\s*(\d{1,2})[:.](\d{2})/', $text, $m)) {
            $startMinutes = ((int) $m[1]) * 60 + (int) $m[2];
            $endMinutes   = ((int) $m[3]) * 60 + (int) $m[4];
            $diff         = $endMinutes - $startMinutes;

            if ($diff <= 0) {
                $diff += 1440; // Rentang melewati tengah malam
            }

            return $this->validateDurationRange($diff);
        }

        // Format: "X jam Y menit"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam\s*(\d+)\s*menit/', $text, $m)) {
            $hours   = (float) str_replace(',', '.', $m[1]);
            $minutes = (int) $m[2];

            return $this->validateDurationRange((int) round($hours * 60) + $minutes);
        }

        // Format: "X jam"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam/', $text, $m)) {
            return $this->validateDurationRange((int) round((float) str_replace(',', '.', $m[1]) * 60));
        }

        // Format: "X menit"
        if (preg_match('/(\d+)\s*menit/', $text, $m)) {
            return $this->validateDurationRange((int) $m[1]);
        }

        // Format singkat: "1h30m", "1h 30m", "0h45m"
        if (preg_match('/\b(\d+)\s*h\s*(?:(\d+)\s*m)?\b/', $text, $m)) {
            $hours   = (int) $m[1];
            $minutes = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            return $this->validateDurationRange($hours * 60 + $minutes);
        }

        // Format jam:menit numerik: "1:30" (= 90 menit), "0:45" (= 45 menit)
        if (preg_match('/\b(\d{1,2}):(\d{2})\b/', $text, $m)) {
            $hours   = (int) $m[1];
            $minutes = (int) $m[2];

            return $this->validateDurationRange($hours * 60 + $minutes);
        }

        // Format: angka saja (anggap menit)
        if (preg_match('/^\d+$/', $text)) {
            return $this->validateDurationRange((int) $text);
        }

        return null;
    }

    /**
     * Format menit ke string ramah-pengguna.
     * Contoh: 90 -> "1 jam 30 menit", 60 -> "1 jam", 45 -> "45 menit".
     *
     * @param  int    $minutes Durasi dalam menit
     * @return string String durasi yang mudah dibaca
     */
    protected function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '-';
        }

        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours} jam {$mins} menit";
        }

        if ($hours > 0) {
            return "{$hours} jam";
        }

        return "{$minutes} menit";
    }

    /**
     * Validasi hasil parsing durasi.
     * Durasi tidak boleh 0/negatif dan tidak boleh lebih dari 24 jam (1440 menit).
     *
     * @param  int|null    $minutes Durasi mentah hasil parsing
     * @return int|null             Durasi jika valid, null jika di luar rentang
     */
    private function validateDurationRange(?int $minutes): ?int
    {
        if ($minutes === null || $minutes <= 0 || $minutes > 1440) {
            return null;
        }

        return $minutes;
    }

    // =========================================================
    // PARSING & FORMAT TANGGAL LAPORAN
    // =========================================================

    /**
     * Parse teks bebas dari teknisi untuk mendeteksi tanggal laporan.
     * Format yang didukung:
     *   - Kata kunci relatif: "kemarin", "hari ini"
     *   - Numerik murni     : "1/7/2026", "01-07-2026"
     *   - Tanggal + nama bulan: "1/Juli/2026", "01/juli/26", "1 Juli 2026"
     *   - Nama bulan + tanggal: "Juli 1 2026"
     * Nama bulan boleh lengkap atau singkatan, case-insensitive.
     * Tahun 2-digit otomatis diterjemahkan ke tahun 20XX.
     * Hasil divalidasi: tidak boleh tanggal masa depan dan tidak boleh
     * lebih dari REPORT_DATE_MAX_BACKDATE_DAYS hari ke belakang dari hari ini.
     *
     * Berbeda dari versi sebelumnya, method ini SELALU mengembalikan array
     * berisi tanggal (jika valid) dan status parsing, agar pemanggil bisa
     * membedakan antara "tidak ada tanggal terdeteksi" dan "tanggal terdeteksi
     * tapi ditolak karena di luar rentang yang diizinkan" — sehingga teknisi
     * bisa diberi notifikasi yang jelas, bukan diam-diam fallback ke hari ini.
     *
     * @param  string $text Teks laporan awal dari teknisi
     * @return array{date: string|null, status: string} Status salah satu dari:
     *         'ok'              - tanggal terdeteksi dan valid, 'date' berisi Y-m-d
     *         'future'          - tanggal terdeteksi tapi ada di masa depan
     *         'too_old'         - tanggal terdeteksi tapi lebih dari batas hari mundur
     *         'invalid_calendar'- pola tanggal cocok tapi bukan tanggal kalender valid
     *         'not_detected'    - tidak ada pola tanggal yang cocok di teks
     */
    public function parseDateFromText(string $text): array
    {
        $text = mb_strtolower(trim($text));

        // Kata kunci relatif
        if (preg_match('/\bkemarin\b/u', $text)) {
            return $this->resolveDateStatus(now()->subDay()->toDateString());
        }

        if (preg_match('/\bhari\s*ini\b/u', $text)) {
            return $this->resolveDateStatus(now()->toDateString());
        }

        $bulanMap = $this->indonesianMonthMap();
        $bulanPattern = implode('|', array_keys($bulanMap));

        // Format numerik murni: d/m/y atau d-m-y
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $text, $m)) {
            $day   = (int) $m[1];
            $month = (int) $m[2];
            $year  = $this->normalizeYearFragment($m[3]);

            return $this->buildDateStatus($day, $month, $year);
        }

        // Format: tanggal - nama bulan - tahun (contoh: 1/juli/2026, 1 juli 2026)
        if (preg_match('/\b(\d{1,2})[\/\-\s]+(' . $bulanPattern . ')[\/\-\s]+(\d{2,4})\b/u', $text, $m)) {
            $day   = (int) $m[1];
            $month = $bulanMap[$m[2]] ?? null;
            $year  = $this->normalizeYearFragment($m[3]);

            if ($month !== null) {
                return $this->buildDateStatus($day, $month, $year);
            }
        }

        // Format: nama bulan - tanggal - tahun (contoh: juli 1 2026)
        if (preg_match('/\b(' . $bulanPattern . ')\s+(\d{1,2})\s+(\d{2,4})\b/u', $text, $m)) {
            $month = $bulanMap[$m[1]] ?? null;
            $day   = (int) $m[2];
            $year  = $this->normalizeYearFragment($m[3]);

            if ($month !== null) {
                return $this->buildDateStatus($day, $month, $year);
            }
        }

        return ['date' => null, 'status' => 'not_detected'];
    }

    /**
     * Format tanggal Y-m-d ke string Bahasa Indonesia yang ramah-baca.
     * Contoh: "2026-07-01" -> "1 Juli 2026".
     *
     * @param  string $dateString Tanggal dalam format Y-m-d
     * @return string             Tanggal terformat Bahasa Indonesia
     */
    protected function formatIndonesianDate(string $dateString): string
    {
        $namaBulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $date = Carbon::parse($dateString);

        return $date->day . ' ' . $namaBulan[$date->month] . ' ' . $date->year;
    }

    /**
     * Peta nama bulan Bahasa Indonesia (lengkap & singkatan) ke nomor bulan.
     *
     * @return array<string, int>
     */
    private function indonesianMonthMap(): array
    {
        return [
            'januari' => 1, 'jan' => 1,
            'februari' => 2, 'feb' => 2,
            'maret' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'mei' => 5,
            'juni' => 6, 'jun' => 6,
            'juli' => 7, 'jul' => 7,
            'agustus' => 8, 'agu' => 8, 'ags' => 8,
            'september' => 9, 'sep' => 9, 'sept' => 9,
            'oktober' => 10, 'okt' => 10,
            'november' => 11, 'nov' => 11,
            'desember' => 12, 'des' => 12,
        ];
    }

    /**
     * Normalisasi fragmen tahun hasil regex ke integer 4-digit.
     * Tahun 2-digit dianggap tahun 20XX.
     *
     * @param  string $yearFragment Fragmen tahun mentah dari regex
     * @return int                  Tahun 4-digit
     */
    private function normalizeYearFragment(string $yearFragment): int
    {
        if (strlen($yearFragment) === 2) {
            return 2000 + (int) $yearFragment;
        }

        return (int) $yearFragment;
    }

    /**
     * Bangun string tanggal Y-m-d dari komponen day/month/year, lalu validasi rentang.
     * Mengembalikan status 'invalid_calendar' jika kombinasi tanggal tidak valid
     * secara kalender (misal 31 Februari), sebelum sempat divalidasi rentang.
     *
     * @param  int   $day   Tanggal
     * @param  int   $month Bulan
     * @param  int   $year  Tahun 4-digit
     * @return array{date: string|null, status: string} Lihat parseDateFromText() untuk daftar status
     */
    private function buildDateStatus(int $day, int $month, int $year): array
    {
        if (!checkdate($month, $day, $year)) {
            return ['date' => null, 'status' => 'invalid_calendar'];
        }

        $date = Carbon::create($year, $month, $day);

        return $this->resolveDateStatus($date->toDateString());
    }

    /**
     * Validasi rentang tanggal hasil parsing.
     * Tanggal tidak boleh berada di masa depan dan tidak boleh lebih dari
     * REPORT_DATE_MAX_BACKDATE_DAYS hari ke belakang dari hari ini.
     *
     * @param  string $dateString Tanggal dalam format Y-m-d
     * @return array{date: string|null, status: string} Lihat parseDateFromText() untuk daftar status
     */
    private function resolveDateStatus(string $dateString): array
    {
        $date  = Carbon::parse($dateString)->startOfDay();
        $today = now()->startOfDay();

        if ($date->greaterThan($today)) {
            return ['date' => null, 'status' => 'future'];
        }

        if ($date->lessThan($today->copy()->subDays(self::REPORT_DATE_MAX_BACKDATE_DAYS))) {
            return ['date' => null, 'status' => 'too_old'];
        }

        return ['date' => $date->toDateString(), 'status' => 'ok'];
    }

    // =========================================================
    // LABEL EQUIPMENT
    // =========================================================

    /**
     * Bangun label ringkas untuk equipment atau area yang terkunci di state.
     * Dipakai di pesan konfirmasi, prompt step, dan ringkasan laporan.
     *
     * @param  array  $state State wizard
     * @return string Label equipment atau area
     */
    protected function equipmentLabel(array $state): string
    {
        if (!empty($state['is_area_work'])) {
            $areaCode = $state['area_code'] ?? 'Area';
            return "Pekerjaan Area ({$areaCode})";
        }

        $label = $state['equipment_ident'] ?? 'Equipment';
        if (!empty($state['equipment_funcloc'])) {
            $label .= ' (' . $state['equipment_funcloc'] . ')';
        }

        return $label;
    }

    // =========================================================
    // STATE AWAL & RESPONS ERROR
    // =========================================================

    /**
     * Buat state awal wizard untuk sesi laporan baru.
     * Semua field diinisialisasi ke nilai default yang aman.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Teks laporan awal dari teknisi
     * @return array  State awal wizard
     */
    protected function createInitialState(string $chatId, string $text): array
    {
        return [
            'chat_id'                     => $chatId,
            'text'                        => $text,
            'step'                        => self::STEP_EQUIPMENT_SEARCH,
            'ai_analysis'                 => [],
            'equipment_id'                => null,
            'equipment_ident'             => null,
            'equipment_funcloc'           => null,
            'is_area_work'                => false,
            'area_id'                     => null,
            'area_code'                   => null,
            'search_confidence'           => null,
            'retype_attempts'             => 0,
            'using_clarification_service' => false,
            'work_duration_minutes'       => null,
            'root_cause'                  => null,
            'report_date'                 => null,
            'report_date_status'          => null,
            'initial_photo_file_id'       => null,
            'photo_documentation'         => [],
            'photo_hygiene_clearance'     => [],
            'created_at'                  => now()->toIso8601String(),
        ];
    }

    /**
     * Bangun struktur respons error standar.
     * Flag 'error' => true digunakan oleh PollTelegramUpdates untuk
     * memutuskan apakah perlu logging tambahan.
     *
     * @param  string $message Pesan error yang ditampilkan ke teknisi
     * @return array  Struktur respons error
     */
    protected function errorResponse(string $message): array
    {
        return [
            'message'  => $message,
            'keyboard' => [],
            'error'    => true,
        ];
    }
}
