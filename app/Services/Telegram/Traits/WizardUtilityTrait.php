<?php

namespace App\Services\Telegram\Traits;

use App\Enums\WorkClassification;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Services\LocationResolver;
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
 *   - classificationLabel()    : Label ringkas klasifikasi pekerjaan (CM/PM/dCM/Fabrikasi/Sipil) dari state
 *   - createInitialState()     : Buat state awal wizard baru
 *   - errorResponse()          : Bangun struktur respons error standar
 *   - pushStepHistory()        : Catat step saat ini ke riwayat sebelum wizard maju
 *   - popStepHistory()         : Ambil dan hapus step terakhir dari riwayat
 *   - navigationButtons()      : Bangun tombol Kembali + Batalkan Laporan yang konsisten
 *
 * Trait ini tidak bergantung pada method atau properti lain dari kelas pemakai
 * (self-contained), sehingga aman di-use di kelas manapun dalam wizard.
 *
 * CATATAN — Mekanisme navigasi Kembali (step_history):
 * State wizard menyimpan array 'step_history' berisi urutan step yang pernah
 * dilalui teknisi. Setiap kali wizard MAJU ke step berikutnya, step yang
 * sedang ditinggalkan wajib di-push lebih dulu lewat pushStepHistory()
 * sebelum $state['step'] diubah. Saat teknisi menekan tombol "Kembali"
 * (callback_data 'wizard:back', ditangani WizardCallbackHandlerTrait),
 * popStepHistory() dipanggil untuk mengambil step terakhir dari riwayat
 * dan membangun ulang prompt step tersebut — nilai field yang sudah pernah
 * diisi TIDAK dihapus, hanya ditampilkan ulang sebagai draft yang bisa
 * ditimpa. Setiap method buildXxxPrompt() yang menyertakan navigasi wajib
 * memakai navigationButtons() agar tombol Kembali otomatis hilang saat
 * riwayat masih kosong (mis. di step paling awal alur linear).
 */
trait WizardUtilityTrait
{
    /**
     * Batas maksimal jumlah hari mundur yang diizinkan untuk tanggal laporan.
     * Tanggal yang terdeteksi lebih lampau dari ini akan ditolak (status 'too_old').
     * Nilai 7 hari untuk mengakomodasi teknisi shift dan libur akhir pekan.
     */
    public const REPORT_DATE_MAX_BACKDATE_DAYS = 7;

    // =========================================================
    // FORMAT & PARSING DURASI
    // =========================================================

    /**
     * Parse teks rentang waktu menjadi durasi dalam menit.
     *
     * Hanya format rentang waktu yang diterima (jam awal - jam akhir).
     * Format durasi langsung seperti "2 jam", "30 menit", atau angka saja
     * tidak lagi didukung dan akan mengembalikan null.
     *
     * Delegasikan ke parseWorkTimeRange() yang juga menangkap jam mulai
     * dan jam selesai.
     *
     * @param  string   $text Input teks dari teknisi
     * @return int|null       Durasi dalam menit, null jika format tidak dikenali
     */
    public function parseDurationToMinutes(string $text): ?int
    {
        $range = $this->parseWorkTimeRange($text);

        return $range ? $range['minutes'] : null;
    }

    /**
     * Parse teks rentang waktu menjadi array jam mulai, jam selesai, dan durasi.
     *
     * Format yang didukung:
     *   Rentang dengan menit di kedua sisi:
     *     "08:00 sampai 09:00", "08:00-09:00",
     *     "08.00 s/d 09.30",    "08:00 sd 10:00"
     *
     *   Rentang fleksibel (prefiks jam/pukul opsional, menit opsional
     *   di masing-masing sisi secara independen):
     *     "Jam 9 sampai Jam 10", "jam 9 - jam 10",
     *     "pukul 09 sd 10.30",   "09 sampai 10",
     *     "9.30 sampai 10",      "9 sampai 10.30"
     *
     * Rentang yang melewati tengah malam (mis. 23:00-01:00) diterima dan
     * dihitung sebagai rentang positif dengan menambahkan 1440 menit.
     * Hasil divalidasi: tidak boleh 0/negatif dan tidak boleh lebih dari
     * 24 jam (1440 menit).
     *
     * @param  string $text Input teks dari teknisi
     * @return array{start: string, end: string, minutes: int}|null
     */
    public function parseWorkTimeRange(string $text): ?array
    {
        $text = strtolower(trim($text));

        // Rentang lengkap: menit eksplisit di kedua sisi, dipisah kata atau tanda "-".
        // Contoh: "08:00 sampai 09:00", "8:00-9:00", "08.00 s/d 09.30", "08:00 sd 10:00".
        // Blok ini diutamakan agar format dua titik dua tidak salah tertangkap blok berikutnya.
        if (preg_match(
            '/(\d{1,2})[:.](\d{2})\s*(?:sampai|s\/d|sd|hingga|-)\s*(\d{1,2})[:.](\d{2})/',
            $text,
            $m
        )) {
            return $this->buildTimeRange(
                (int) $m[1] * 60 + (int) $m[2],
                (int) $m[3] * 60 + (int) $m[4]
            );
        }

        // Rentang fleksibel (2a): pemisah berupa kata (sampai / s/d / sd / hingga).
        // Prefiks "jam"/"pukul" opsional di masing-masing sisi.
        // Menit opsional di masing-masing sisi secara independen.
        // Contoh: "jam 9 sampai jam 10" (60 menit), "pukul 09 sd 10.30" (90 menit),
        //         "09 sampai 10" (60 menit), "9.30 sampai 10" (30 menit).
        if (preg_match(
            '/(?:jam|pukul)?\s*(\d{1,2})(?:[.:](\d{2}))?\s*(?:sampai|s\/d|sd|hingga)\s*(?:jam|pukul)?\s*(\d{1,2})(?:[.:](\d{2}))?\b/',
            $text,
            $m
        )) {
            $startMinutes = (int) $m[1] * 60 + (isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0);
            $endMinutes   = (int) $m[3] * 60 + (isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0);

            return $this->buildTimeRange($startMinutes, $endMinutes);
        }

        // Rentang fleksibel (2b): pemisah berupa tanda "-" yang diapit spasi.
        // Spasi wajib di kedua sisi "-" agar kode equipment seperti "LSH-2-6600V2"
        // atau "TCV-2-6166E2B-1" tidak salah dikenali sebagai rentang waktu.
        // Contoh: "jam 9 - jam 10" (60 menit), "jam 9.30 - jam 10.30" (60 menit).
        if (preg_match(
            '/(?:jam|pukul)?\s*(\d{1,2})(?:[.:](\d{2}))?\s+-\s+(?:jam|pukul)?\s*(\d{1,2})(?:[.:](\d{2}))?\b/',
            $text,
            $m
        )) {
            $startMinutes = (int) $m[1] * 60 + (isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0);
            $endMinutes   = (int) $m[3] * 60 + (isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0);

            return $this->buildTimeRange($startMinutes, $endMinutes);
        }

        return null;
    }

    /**
     * Bangun array hasil parsing rentang waktu dari menit-sejak-tengah-malam.
     * Durasi divalidasi lewat validateDurationRange().
     *
     * @param  int $startMinutes Waktu mulai dalam menit sejak pukul 00:00
     * @param  int $endMinutes   Waktu selesai dalam menit sejak pukul 00:00
     * @return array{start: string, end: string, minutes: int}|null
     */
    private function buildTimeRange(int $startMinutes, int $endMinutes): ?array
    {
        $minutes = $this->validateDurationRange($this->calcRangeDiff($startMinutes, $endMinutes));

        if ($minutes === null) {
            return null;
        }

        return [
            'start'   => $this->formatMinutesToHhMm($startMinutes),
            'end'     => $this->formatMinutesToHhMm($endMinutes),
            'minutes' => $minutes,
        ];
    }

    /**
     * Format menit-sejak-tengah-malam menjadi string HH:MM.
     *
     * @param  int    $minutes Menit sejak pukul 00:00
     * @return string Format HH:MM
     */
    private function formatMinutesToHhMm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
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
     * @param  int|null $minutes Durasi mentah hasil parsing
     * @return int|null          Durasi jika valid, null jika di luar rentang
     */
    private function validateDurationRange(?int $minutes): ?int
    {
        if ($minutes === null || $minutes <= 0 || $minutes > 1440) {
            return null;
        }

        return $minutes;
    }

    /**
     * Hitung selisih menit antara waktu mulai dan waktu selesai.
     * Jika selisih nol atau negatif, diasumsikan rentang melewati tengah malam
     * sehingga ditambahkan 1440 menit (24 jam).
     *
     * @param  int $startMinutes Waktu mulai dalam menit sejak pukul 00:00
     * @param  int $endMinutes   Waktu selesai dalam menit sejak pukul 00:00
     * @return int               Selisih dalam menit (selalu positif)
     */
    private function calcRangeDiff(int $startMinutes, int $endMinutes): int
    {
        $diff = $endMinutes - $startMinutes;

        if ($diff <= 0) {
            $diff += 1440;
        }

        return $diff;
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

    /**
     * Deskripsi alat utama laporan.
     *
     * Diambil dari state (diisi saat alat dikunci: lockEquipmentAndAdvance,
     * finalizeClarificationSelection, startWizardForAsset) dengan jaring
     * pengaman dari database agar jalur lama yang belum mengisi
     * state['equipment_desc'] tetap menampilkan deskripsi.
     *
     * @param  array  $state State wizard
     * @return string Deskripsi alat, atau string kosong bila tidak ada
     */
    protected function equipmentDesc(array $state): string
    {
        $desc = trim((string) ($state['equipment_desc'] ?? ''));

        if ($desc !== '') {
            return $desc;
        }

        if (!empty($state['equipment_id'])) {
            return trim((string) (Asset::find($state['equipment_id'])?->description ?? ''));
        }

        return '';
    }

    /**
     * Label alat/lokasi UTAMA untuk daftar ringkas pekerjaan.
     * Format yang dipakai: "KODE — Deskripsi" (kode Functional Location tidak
     * disertakan supaya deskripsi tidak terpotong).
     *
     * @param  array  $state State wizard
     * @return string Label alat utama, atau label area bila pekerjaan area
     */
    protected function labelEquipmentUtama(array $state): string
    {
        if (!empty($state['is_area_work'])) {
            $kode = trim((string) ($state['funcloc_code'] ?? ''));
            $nama = trim((string) ($state['funcloc_name'] ?? ''));

            if ($kode === '') {
                return 'Pekerjaan Area (' . ($state['area_code'] ?? 'Area') . ')';
            }

            return 'Pekerjaan Area: ' . $kode . ($nama !== '' ? ' — ' . $nama : '');
        }

        $ident = trim((string) ($state['equipment_ident'] ?? '')) ?: 'Equipment';
        $desc  = $this->equipmentDesc($state);

        return $desc !== '' ? $ident . ' — ' . $desc : $ident;
    }

    /**
     * Label satu item alat/lokasi tambahan (label + keterangan).
     *
     * @param  array  $item Entri item (label, sub)
     * @return string Label item, mis. "MUL-M-02 — Motor Multi Kedua"
     */
    protected function labelItemMulti(array $item): string
    {
        $label = trim((string) ($item['label'] ?? '')) ?: '-';
        $sub   = trim((string) ($item['sub'] ?? ''));

        return $sub !== '' ? $label . ' — ' . $sub : $label;
    }

    /**
     * Daftar alat/lokasi pekerjaan dalam satu blok teks:
     *   Equipment :
     *   (kode alat + deskripsi)
     *
     *   Equipment Tambahan :
     *   1. ...
     *   2. ...
     *
     * Dipakai prompt Step 8b dan setiap layar hirarki mode multi supaya teknisi
     * selalu melihat alat utama beserta seluruh tambahannya di satu tempat.
     *
     * @param  array  $state State wizard
     * @return string Blok daftar alat/lokasi (teks Markdown Telegram)
     */
    protected function daftarEquipmentState(array $state): string
    {
        $daftar = $state['multi_items'] ?? [];
        $teks   = "*Equipment :*\n" . $this->labelEquipmentUtama($state) . "\n\n*Equipment Tambahan :*";

        if ($daftar === []) {
            return $teks . "\n_Belum ada alat/lokasi tambahan._";
        }

        foreach ($daftar as $index => $item) {
            $teks .= "\n" . ($index + 1) . '. ' . $this->labelItemMulti($item);
        }

        return $teks;
    }

    /**
     * Label tombol daftar alat: kode alat + deskripsi, dipotong 64 karakter
     * (batas aman panjang teks tombol inline Telegram).
     *
     * @param  string  $kode      Tech Ident No / Equipment No alat
     * @param  string  $deskripsi Deskripsi alat (opsional)
     * @param  string  $awalan    Awalan label, mis. "1. " untuk daftar bernomor
     * @return string Label tombol siap pakai
     */
    protected function tombolAlatLabel(string $kode, string $deskripsi = '', string $awalan = ''): string
    {
        $label = trim($awalan . $kode);
        $desc  = trim($deskripsi);

        if ($desc !== '') {
            $label .= ($label !== '' ? ' — ' : '') . $desc;
        }

        if (mb_strlen($label) > 64) {
            $label = mb_substr($label, 0, 61) . '...';
        }

        return $label;
    }

    // =========================================================
    // LABEL KLASIFIKASI PEKERJAAN
    // =========================================================

    /**
     * Bangun label ringkas klasifikasi pekerjaan yang dipilih teknisi di
     * step paling awal wizard. Dipakai di ringkasan konfirmasi (Step 8)
     * dan pesan sukses setelah laporan tersimpan.
     *
     * @param  array  $state State wizard
     * @return string Label klasifikasi pekerjaan, atau '-' jika belum dipilih
     */
    protected function classificationLabel(array $state): string
    {
        $value = $state['work_classification'] ?? null;

        if (!$value) {
            return '-';
        }

        $classification = WorkClassification::tryFrom($value);

        return $classification ? $classification->label() : $value;
    }

    /**
     * Tentukan nilai jenis_pekerjaan otomatis dari kode klasifikasi ZPM.
     * Mengembalikan null untuk ZPM 1/6 karena butuh pilihan EI vs Mekanik
     * dari teknisi di step tambahan wizard.
     *
     * ZPM 8 (Project) langsung menentukan jenis pekerjaan 'Project' tanpa step
     * tambahan.
     *
     * @param  string      $classification Kode ZPM (zpm1/zpm3/zpm5/zpm6/zpm7/zpm8)
     * @return string|null Nilai jenis_pekerjaan atau null
     */
    protected function autoJenisPekerjaan(string $classification): ?string
    {
        return match ($classification) {
            'zpm3' => 'CM/PM',
            'zpm5' => 'Fabrikasi',
            'zpm7' => 'Sipil',
            'zpm8' => 'Project',
            default => null,
        };
    }

    /**
     * Lengkapi state lokasi laporan supaya kolom funcloc_id & area_id SELALU
     * terisi bila datanya bisa diketahui.
     *
     * Sebelumnya jalur penguncian equipment hanya menyimpan kode lokasi sebagai
     * teks (equipment_funcloc) tanpa funcloc_id, sehingga banyak laporan
     * perbaikan alat tampil tanpa lokasi di halaman laporan. Area juga sering
     * kosong karena node FuncLoc/aset tidak selalu punya area_id — untuk kasus
     * itu area diturunkan dari segmen ke-3 kode FuncLoc (mis. EPE-PROD-BD02-6153
     * -> area BD02).
     *
     * @param  array                    $state  State wizard saat ini
     * @param  FunctionalLocation|null  $node   Node lokasi yang dikunci (bila ada)
     * @param  Asset|null               $aset   Aset yang dikunci (bila ada)
     * @return array  State dengan funcloc_id/area_id yang sudah dilengkapi
     */
    protected function lengkapiStateLokasi(array $state, ?FunctionalLocation $node, ?Asset $aset = null): array
    {
        return $this->locationResolver()->lengkapiState($state, $node, $aset);
    }

    /**
     * Turunkan area_id dari kode FuncLoc (segmen ke-3), dipakai bila node/aset
     * tidak punya area_id. Contoh: "EPE-PROD-BD02-6153" -> kode area "BD02".
     *
     * @param  string|null  $kode  Kode FunctionalLocation
     * @return int|null  ID Area, atau null bila tidak ditemukan
     */
    protected function areaIdDariKodeFuncLoc(?string $kode): ?int
    {
        return $this->locationResolver()->areaIdDariKode($kode);
    }

    /**
     * Resolver lokasi (funcloc_id/area_id) yang dipakai bersama command
     * perbaikan data laporan lama.
     *
     * @return LocationResolver
     */
    protected function locationResolver(): LocationResolver
    {
        return app(LocationResolver::class);
    }

    /**
     * Apakah pool pencarian equipment dibatasi ke perangkat IT saja.
     *
     * KEPUTUSAN BISNIS (revisi): sekarang SELALU false — teknisi dengan jenis
     * pekerjaan IT tetap bisa memilih SELURUH equipment, termasuk aset industri
     * milik PT mana pun (EPE/NPA/CES). Sebelumnya pilihan jenis pekerjaan IT
     * membatasi pool ke baris cermin perangkat IT (data_source 'it_sync'),
     * sehingga aset industri (mis. NPA/CES) tidak muncul padahal dikerjakan.
     *
     * Method ini sengaja dipertahankan sebagai satu-satunya tempat keputusan
     * tersebut supaya seluruh pemakai (pencarian TechIdentNo, keyboard tipe/area,
     * dan penjelajahan FuncLoc) ikut berubah tanpa perlu diubah satu per satu.
     *
     * @param  array  $state State wizard saat ini
     * @return bool  Selalu false (tidak ada pembatasan pool per jenis pekerjaan)
     */
    protected function isItPool(array $state): bool
    {
        return false;
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
            'step_history'                => [],
            'work_classification'         => null,
            'jenis_pekerjaan'             => null,
            'start_time'                  => null,
            'end_time'                    => null,
            'ai_analysis'                 => [],
            'equipment_id'                => null,
            'equipment_ident'             => null,
            'equipment_funcloc'           => null,
            'equipment_desc'              => null,
            'is_area_work'                => false,
            'area_id'                     => null,
            'area_code'                   => null,
            'search_confidence'           => null,
            'retype_attempts'             => 0,
            'using_clarification_service' => false,
            'work_duration_minutes'       => null,
            // Urutan kunci mengikuti alur wizard: temuan (5a) sebelum root cause (5b).
            'finding'                     => null,
            'root_cause'                  => null,
            'report_date'                 => null,
            'report_date_status'          => null,
            'initial_photo_file_id'       => null,
            'photo_documentation'         => [],
            'photo_hygiene_clearance'     => [],
            'collab_search_candidates'    => [],
            'collab_using_hierarchy'      => false,
            'collab_hierarchy_dept_id'    => null,
            'collab_hierarchy_section'    => null,
            'created_at'                  => now()->toIso8601String(),
            'last_activity_at'            => now()->toIso8601String(),
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

    // =========================================================
    // NAVIGASI STEP — RIWAYAT & TOMBOL KEMBALI/BATAL
    // =========================================================

    /**
     * Catat step yang sedang ditinggalkan ke riwayat, dipanggil sesaat
     * sebelum $state['step'] diubah ke step berikutnya. Riwayat dipakai
     * popStepHistory() untuk menentukan tujuan saat teknisi menekan
     * tombol Kembali.
     *
     * @param  array  $state       State wizard saat ini
     * @param  string $currentStep Step yang sedang ditinggalkan (nilai $state['step'] sebelum diubah)
     * @return array  State wizard dengan step_history yang sudah diperbarui
     */
    protected function pushStepHistory(array $state, string $currentStep): array
    {
        $state['step_history']   = $state['step_history'] ?? [];
        $state['step_history'][] = $currentStep;

        return $state;
    }

    /**
     * Ambil step terakhir dari riwayat dan hapus dari state (pop stack).
     * Dipanggil saat teknisi menekan tombol Kembali.
     *
     * @param  array $state State wizard saat ini
     * @return array{step: string|null, state: array} 'step' berisi step tujuan,
     *         null jika riwayat kosong (sudah di step paling awal).
     *         'state' berisi state wizard setelah riwayat di-pop.
     */
    protected function popStepHistory(array $state): array
    {
        $state['step_history'] = $state['step_history'] ?? [];
        $previousStep          = array_pop($state['step_history']);

        return [
            'step'  => $previousStep ?? null,
            'state' => $state,
        ];
    }

    /**
     * Bangun tombol navigasi standar (Kembali + Batalkan Laporan) yang dipakai
     * konsisten di seluruh step wizard. Tombol Kembali otomatis disembunyikan
     * jika belum ada riwayat step (mis. di step paling awal alur linear).
     *
     * @param  array $state       State wizard saat ini
     * @param  bool  $includeBack Set false untuk memaksa sembunyikan tombol Kembali
     *                            meskipun riwayat tidak kosong (jarang dipakai)
     * @return array Daftar tombol siap digabung ke keyboard step (array_merge)
     */
    protected function navigationButtons(array $state, bool $includeBack = true): array
    {
        $buttons = [];

        if ($includeBack && !empty($state['step_history'])) {
            $buttons[] = ['text' => 'Kembali', 'callback_data' => 'wizard:back'];
        }

        $buttons[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return $buttons;
    }

    /**
     * Peta kata kunci tipe alat (Indonesia & Inggris) ke nilai kolom
     * equipment_type di tabel assets. Dipakai untuk pencarian berbasis tipe
     * ketika teknisi menulis nama tipe alih-alih kode TechIdentNo.
     *
     * Urutan penting: kata kunci yang lebih spesifik ditaruh lebih dulu.
     */
    protected const TYPE_KEYWORDS = [
        'pressure transmitter'    => 'PRESSURE TRANSMITTER',
        'pressure gauge'          => 'PRESSURE GAUGE',
        'pressure indicator'      => 'PRESSURE GAUGE',
        'temperature transmitter' => 'TEMPERATURE TRANSMITTER',
        'temperature gauge'       => 'TEMPERATURE GAUGE',
        'level transmitter'       => 'LEVEL TRANSMITTER',
        'level switch'            => 'LEVEL SWITCH',
        'solenoid valve'          => 'SOLENOID VALVE',
        'control valve'           => 'CONTROL VALVE',
        'breather valve'          => 'BREATHER VALVE',
        'heat exchanger'          => 'HEAT EXCHANGER (HE)',
        'rupture disc'            => 'RUPTURE DISC',
        'rupture disk'            => 'RUPTURE DISC',
        'flow meter'              => 'FLOWMETER',
        'flowmeter'               => 'FLOWMETER',
        'ph meter'                => 'PH METER',
        'mixer'                   => 'MIXER & AGITATOR',
        'agitator'                => 'MIXER & AGITATOR',
        'compressor'              => 'COMPRESSOR',
        'forklift'                => 'FORKLIFT',
        'pump'                    => 'PUMP',
        'pompa'                   => 'PUMP',
    ];

    /**
     * Deteksi tipe alat dari teks bebas teknisi berdasarkan TYPE_KEYWORDS.
     * Mengembalikan nilai equipment_type yang cocok, atau null jika tidak ada.
     *
     * @param  string      $text Teks laporan dari teknisi
     * @return string|null Nilai equipment_type atau null
     */
    protected function detectEquipmentType(string $text): ?string
    {
        $textLower = strtolower($text);

        foreach (self::TYPE_KEYWORDS as $keyword => $type) {
            if (str_contains($textLower, $keyword)) {
                return $type;
            }
        }

        return null;
    }
}
