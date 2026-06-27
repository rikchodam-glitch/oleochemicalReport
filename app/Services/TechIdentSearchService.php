<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * Pencarian TechIdentNo 3-pass sesuai dokumen "E. Logika Pencarian TechIdentNo".
 *
 * Pass 1: exact match full string apa adanya.
 * Pass 2: exact match setelah normalisasi (uppercase, hapus spasi, strip prefix umum).
 * Pass 3: kombinasi section code 4-digit + tipe/unit alat disebut bersamaan.
 *
 * Pass 1 & 2 selalu auto-accept (confidence >= 95%, satu kandidat).
 * Pass 3 auto-accept jika kandidatnya tunggal, atau confirm (80-94%) jika ambigu.
 * Jika tidak ada match sama sekali, status no_match — alur selanjutnya
 * (tulis ulang / hierarki FuncLoc) ditangani oleh ClarificationService.
 */
class TechIdentSearchService
{
    /**
     * Prefix yang umum ditulis di depan TechIdentNo instrumen/komponen,
     * tapi sering tidak konsisten diketik teknisi di chat. Distrip di Pass 2.
     */
    protected const STRIPPABLE_PREFIXES = ['M-', 'VFD-', 'AT-'];

    /**
     * Peta kata kunci jenis instrumen/komponen (Indonesia & Inggris) yang sering
     * disebut eksplisit oleh teknisi, ke prefix TechIdentNo instrumen terkait
     * (lihat dokumen "E. Logika Pencarian TechIdentNo" -> tabel format TechIdentNo).
     *
     * Dipakai di Pass 3 untuk membedakan equipment dasar (mis. 6163E3A,
     * section+type+unit) dari instrumen yang menempel padanya (mis.
     * TCV-6163E3A-1) ketika keduanya sama-sama match section+suffix.
     * Tanpa peta ini, kata paling informatif yang diucapkan teknisi
     * ("control valve") tidak pernah dipakai sebagai sinyal pencarian.
     *
     * Urutan tidak penting; semua keyword yang match dikumpulkan.
     */
    protected const INSTRUMENT_KEYWORDS = [
    // === LEVEL — urut dari paling spesifik ke umum ===
    'level switch high high'     => 'LSHH',
    'level switch low low'       => 'LSLL',
    'level switch high'          => 'LSH',
    'level switch low'           => 'LSL',
    'high high level switch'     => 'LSHH',
    'low low level switch'       => 'LSLL',
    'high level switch'          => 'LSH',
    'low level switch'           => 'LSL',
    'saklar level tinggi tinggi' => 'LSHH',
    'saklar level rendah rendah' => 'LSLL',
    'saklar level tinggi'        => 'LSH',
    'saklar level rendah'        => 'LSL',
    'level indicator transmitter'=> 'LIT',
    'level transmitter'          => 'LT',
    'level indicator'            => 'LI',

    // === PRESSURE ===
    'pressure transmitter'       => 'PT',
    'pressure safety valve'      => 'PSV',
    'pressure control valve'     => 'PCV',
    'pressure switch'            => 'PS',
    'pressure gauge'             => 'PI',
    'pressure indicator'         => 'PI',
    'safety valve'               => 'PSV',
    'relief valve'               => 'PSV',
    'rupture disc'               => 'PSE',
    'rupture disk'               => 'PSE',
    'breather valve'             => 'BV',

    // === FLOW ===
    'flow transmitter'           => 'FT',
    'flow indicator'             => 'FI',
    'flow meter'                 => 'FT',
    'flowmeter'                  => 'FT',

    // === TEMPERATURE ===
    'temperature transmitter'    => 'TT',
    'temperature indicator'      => 'TI',
    'temperature gauge'          => 'TI',
    'thermocouple'               => 'TE',

    // === CONTROL & SOLENOID VALVE ===
    'temperature control valve'  => 'TCV',
    'level control valve'        => 'LCV',
    'flow control valve'         => 'FCV',
    'control valve'              => 'FCV',   // generic → FCV (flow control valve adalah yang paling umum)
    'solenoid valve'             => 'XV',
    'valve'                      => 'FCV',   // fallback generik — paling bawah

    // === ELECTRICAL / DRIVE ===
    'variable frequency drive'   => 'VFD',
    'motor control center'       => 'LCS',
    'local control station'      => 'LCS',
    'soft starter'               => 'SST',
    'softstarter'                => 'SST',
    'inverter'                   => 'VFD',
    'motor'                      => 'M',
    'vfd'                        => 'VFD',

    // === ANALYZER ===
    'ph meter'                   => 'AT',
    'analyzer'                   => 'AT',
    'analyser'                   => 'AT',
];

    /**
     * Jalankan pencarian 3-pass terhadap teks mentah dari teknisi.
     *
     * @param  string   $rawText Teks laporan awal dari teknisi
     * @param  int|null $areaId  Batasi pencarian ke area tertentu jika sudah diketahui
     * @return array{status: string, pass: ?string, confidence: int, candidates: array}
     */
    public function search(string $rawText, ?int $areaId = null): array
{
    $assets = $this->candidateAssets($areaId);
    if ($assets->isEmpty()) {
        return $this->noMatch();
    }

    $tokens = $this->extractTokens($rawText);
    if (empty($tokens)) {
        return $this->noMatch();
    }

    // Pass 1: exact match full string
    foreach ($tokens as $token) {
        $hit = $assets->first(fn ($a) => strcasecmp($a->tech_ident_no, $token) === 0);
        if ($hit) {
            return $this->autoAccept('pass1', 100, [$hit]);
        }
    }

    // Pass 2: exact match setelah normalisasi
    foreach ($tokens as $token) {
        $normalizedToken = $this->normalize($token);
        $hit = $assets->first(fn ($a) => $this->normalize($a->tech_ident_no) === $normalizedToken);
        if ($hit) {
            return $this->autoAccept('pass2', 98, [$hit]);
        }
    }

    // Pass 3: section code 4-digit + tipe/unit alat cocok bersamaan
    $section    = $this->extractSectionCode($rawText);
    $extracted  = $this->extractTypeSuffixAndSequence($rawText);  // DIUBAH: pakai method baru
    $typeSuffix = $extracted['suffix'];
    $seqNumber  = $extracted['sequence'];

    if ($section && $typeSuffix) {
        $normalizedSuffix = strtoupper($typeSuffix);

        $matches = $assets->filter(function ($a) use ($section, $normalizedSuffix) {
            $normalized = $this->normalize($a->tech_ident_no);
            return str_contains($normalized, $section) && str_contains($normalized, $normalizedSuffix);
        })->values();

        // BARU: jika ada sequence number dan kandidat lebih dari satu,
        // filter ke instance yang spesifik (misal: -2 dari PT-2-6163C1-2).
        if ($seqNumber !== null && $matches->count() > 1) {
            $seqMatches = $matches->filter(
                fn ($a) => preg_match('/-' . $seqNumber . '$/', $a->tech_ident_no)
            )->values();

            if ($seqMatches->count() === 1) {
                return $this->autoAccept('pass3_seq', 95, [$seqMatches->first()]);
            }
            if ($seqMatches->count() > 1) {
                return $this->confirm('pass3_seq', 85, $seqMatches->take(4)->all());
            }
            // Jika tidak ada yang cocok dengan sequence itu, lanjut ke logika umum di bawah
        }

        $instrumentPrefixes = $this->detectInstrumentPrefixes($rawText);

        if (!empty($instrumentPrefixes) && $matches->count() > 1) {
            $instrumentMatches = $matches->filter(function ($a) use ($instrumentPrefixes) {
                $normalized = $this->normalize($a->tech_ident_no);
                foreach ($instrumentPrefixes as $prefix) {
                    if (str_starts_with($normalized, strtoupper($prefix))) {
                        return true;
                    }
                }
                return false;
            })->values();

            if ($instrumentMatches->count() === 1) {
                return $this->autoAccept('pass3', 95, [$instrumentMatches->first()]);
            }

            if ($instrumentMatches->count() > 1) {
                return $this->confirm('pass3', 85, $instrumentMatches->take(4)->all());
            }
        }

        if ($matches->count() === 1) {
            return $this->autoAccept('pass3', 95, [$matches->first()]);
        }

        if ($matches->count() > 1) {
            return $this->confirm('pass3', 85, $matches->take(4)->all());
        }
    }

    // Pass 3b: BARU — prefix instrumen dari deskripsi + section code.
    // Menangani kasus seperti "Level Switch High 6600V2" → prefix=LSH, section=6600.
    // Berguna ketika teknisi menulis nama panjang instrumen tanpa kode singkat.
    if ($section) {
        $detectedPrefixes = $this->detectInstrumentPrefixes($rawText);

        if (!empty($detectedPrefixes)) {
            $prefixMatches = $assets->filter(function ($a) use ($section, $detectedPrefixes) {
                foreach ($detectedPrefixes as $prefix) {
                    // Normalisasi: hilangkan tanda '-' agar LSHH dan LSH- sama-sama match
                    $normalPrefix = strtoupper(str_replace('-', '', $prefix));
                    $normalTI     = strtoupper(str_replace('-', '', $a->tech_ident_no));
                    $normalSect   = $this->normalize($a->tech_ident_no);

                    if (str_starts_with($normalTI, $normalPrefix) && str_contains($normalSect, $section)) {
                        return true;
                    }
                }
                return false;
            })->values();

            if ($prefixMatches->count() === 1) {
                return $this->autoAccept('pass3b', 92, [$prefixMatches->first()]);
            }

            if ($prefixMatches->count() > 1) {
                return $this->confirm('pass3b', 80, $prefixMatches->take(5)->all());
            }
        }
    }

    return $this->noMatch();
}

    /**
     * Ambil daftar asset kandidat, dibatasi ke area tertentu jika diketahui
     * (mempersempit ruang pencarian dan menghindari hasil dari area lain).
     */
    protected function candidateAssets(?int $areaId): Collection
    {
        $query = Asset::whereNotNull('tech_ident_no');
        if ($areaId) {
            $query->where('area_id', $areaId);
        }

        return $query->get(['id', 'tech_ident_no', 'description', 'functional_loc', 'area_id']);
    }

    /**
     * Ekstrak token/kode potensial dari teks bebas teknisi.
     * Diurutkan dari yang paling panjang — token panjang lebih besar
     * kemungkinan merupakan TechIdentNo utuh dibanding token pendek.
     */
    protected function extractTokens(string $text): array
    {
        $text = strtoupper(trim($text));
        preg_match_all('/[A-Z0-9][A-Z0-9\.\-\/]*[A-Z0-9]|[A-Z0-9]{2,}/', $text, $matches);

        $tokens = array_values(array_unique($matches[0]));
        usort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));

        return array_values(array_filter($tokens, fn ($t) => strlen($t) >= 2 && strlen($t) <= 30));
    }

    /**
     * Normalisasi TechIdentNo/token: uppercase, hapus spasi, strip prefix umum.
     */
    protected function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/', '', $value);

        foreach (self::STRIPPABLE_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));
                break;
            }
        }

        return $value;
    }

    /**
     * Ambil kode section 4-digit pertama yang disebut di teks (contoh: 6163, 6600).
     *
     * PERBAIKAN: regex lama '\b(\d{4})\b' membutuhkan word boundary SETELAH digit,
     * sehingga token seperti "6600P2A1" atau "6163E3A" tidak pernah match
     * karena huruf 'P'/'E' langsung menempel tanpa boundary.
     * Regex baru memakai lookahead '(?=[A-Za-z]|\b)' sehingga 4-digit yang
     * langsung diikuti huruf pun tetap diekstrak sebagai section code.
     */
    protected function extractSectionCode(string $text): ?string
    {
        // Prioritas: 4-digit yang diikuti huruf ATAU berdiri sendiri (word boundary)
        if (preg_match('/\b(\d{4})(?=[A-Za-z]|\b)/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Ekstrak token tipe+unit alat DAN sequence number dari teks bebas teknisi.
     *
     * Contoh:
     *   "6600P2A1"      → ['suffix' => 'P2A1', 'sequence' => null]
     *   "6400E6"        → ['suffix' => 'E6',   'sequence' => null]
     *   "6163E3A"       → ['suffix' => 'E3A',  'sequence' => null]
     *   "PT 6163C1-2"   → ['suffix' => 'C1',   'sequence' => 2]
     *   "LSH 6600V2-1"  → ['suffix' => 'V2',   'sequence' => 1]
     *   "pompa 6163P4"  → ['suffix' => 'P4',   'sequence' => null]
     *   "laporan area"  → ['suffix' => null,    'sequence' => null]
     *
     * PERBAIKAN: regex lama '\b([A-Za-z]{1,2}\d{1,2}[A-Za-z]?)\b' tidak menangkap
     * suffix yang menempel langsung setelah 4-digit section code tanpa spasi
     * (misalnya "6600P2A1" → suffix "P2A1" tidak pernah match karena didahului digit).
     * Regex baru secara eksplisit mencari pola \d{4} lalu mengambil karakter
     * alfanumerik yang langsung menempel sebagai suffix, termasuk format panjang
     * seperti P2A1 (huruf+digit+huruf+digit).
     *
     * @return array{suffix: ?string, sequence: ?int}
     */
    protected function extractTypeSuffixAndSequence(string $text): array
    {
        // Prioritas: suffix yang langsung menempel setelah 4-digit section code,
        // dengan sequence number opsional di akhir (-N).
        // Contoh: "6600P2A1" → suffix=P2A1 | "6163C1-2" → suffix=C1, seq=2
        if (preg_match('/\b\d{4}([A-Za-z][A-Za-z0-9]*)(?:-(\d+))?\b/', $text, $m)) {
            return [
                'suffix'   => $m[1],
                'sequence' => isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null,
            ];
        }

        // Fallback: suffix berdiri sendiri tanpa didahului section code
        // (jarang, tapi untuk kasus seperti "pompa P4 bocor")
        if (preg_match('/\b([A-Za-z]{1,2}\d{1,2}[A-Za-z]?)(?:-(\d+))?\b/', $text, $m)) {
            return [
                'suffix'   => $m[1],
                'sequence' => isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null,
            ];
        }

        return ['suffix' => null, 'sequence' => null];
    }

    /**
     * Deteksi prefix instrumen (TCV, M-, AT, VFD, dst.) dari kata kunci jenis
     * instrumen/komponen yang disebut eksplisit oleh teknisi di teks bebas.
     * Lihat INSTRUMENT_KEYWORDS untuk daftar pemetaan.
     *
     * @return array<string> Daftar prefix unik yang terdeteksi (bisa kosong)
     */
    protected function detectInstrumentPrefixes(string $text): array
    {
        $textLower = strtolower($text);
        $prefixes  = [];

        foreach (self::INSTRUMENT_KEYWORDS as $keyword => $prefix) {
            if (str_contains($textLower, $keyword)) {
                $prefixes[] = $prefix;
            }
        }

        return array_values(array_unique($prefixes));
    }

    protected function autoAccept(string $pass, int $confidence, array $candidates): array
{
    $formatted = $this->formatCandidates($candidates);

    return [
        'status'      => 'auto_accept',
        'pass'        => $pass,
        'confidence'  => $confidence,
        'candidates'  => $formatted,
        'exact_match' => $formatted[0] ?? null,  // alias — konsisten dengan consumer
    ];
}

    protected function confirm(string $pass, int $confidence, array $candidates): array
    {
        return [
            'status'     => 'confirm',
            'pass'       => $pass,
            'confidence' => $confidence,
            'candidates' => $this->formatCandidates($candidates),
        ];
    }

    protected function noMatch(): array
    {
        return [
            'status'     => 'no_match',
            'pass'       => null,
            'confidence' => 0,
            'candidates' => [],
        ];
    }

    protected function formatCandidates(array $assets): array
    {
        return collect($assets)->map(fn ($a) => [
            'id'             => $a->id,
            'tech_ident_no'  => $a->tech_ident_no,
            'description'    => $a->description,
            'functional_loc' => $a->functional_loc,
        ])->all();
    }
}
