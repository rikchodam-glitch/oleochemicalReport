<?php

namespace App\Services\Traits;

/**
 * Trait TechIdentTokenizerTrait
 *
 * Berisi logika tokenisasi dan normalisasi teks bebas teknisi untuk
 * keperluan pencarian TechIdentNo (dipakai oleh TechIdentSearchService).
 *
 * Method yang ada:
 *   - extractTokens()              : Ekstrak token/kode potensial dari teks bebas
 *   - normalize()                  : Normalisasi TechIdentNo/token ke bentuk baku
 *   - extractSectionCode()         : Ambil kode section 4-digit pertama dari teks
 *   - extractTypeSuffixAndSequence(): Ekstrak suffix tipe alat dan sequence number
 *   - detectInstrumentPrefixes()   : Deteksi prefix instrumen dari kata kunci eksplisit
 *
 * Dikelompokkan bersama karena semuanya bertugas menguraikan teks mentah
 * menjadi komponen pencarian yang terstruktur, tanpa menyentuh database.
 */
trait TechIdentTokenizerTrait
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
     * Ekstrak token/kode potensial dari teks bebas teknisi.
     * Diurutkan dari yang paling panjang — token panjang lebih besar
     * kemungkinan merupakan TechIdentNo utuh dibanding token pendek.
     *
     * @param  string  $text Teks bebas dari teknisi
     * @return array<string> Daftar token yang terdeteksi, diurutkan panjang descending
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
     *
     * @param  string $value Token atau TechIdentNo yang akan dinormalisasi
     * @return string        Nilai yang sudah dinormalisasi
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
     * Regex memakai lookahead '(?=[A-Za-z]|\b)' sehingga 4-digit yang langsung
     * diikuti huruf (mis. "6600P2A1" atau "6163E3A") tetap diekstrak sebagai
     * section code, tidak hanya yang berdiri sendiri.
     *
     * @param  string      $text Teks bebas dari teknisi
     * @return string|null       Kode section 4-digit jika ditemukan, null jika tidak
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
     *   "6600P2A1"      => ['suffix' => 'P2A1', 'sequence' => null]
     *   "6400E6"        => ['suffix' => 'E6',   'sequence' => null]
     *   "6163E3A"       => ['suffix' => 'E3A',  'sequence' => null]
     *   "PT 6163C1-2"   => ['suffix' => 'C1',   'sequence' => 2]
     *   "LSH 6600V2-1"  => ['suffix' => 'V2',   'sequence' => 1]
     *   "pompa 6163P4"  => ['suffix' => 'P4',   'sequence' => null]
     *   "laporan area"  => ['suffix' => null,    'sequence' => null]
     *
     * @param  string $text Teks bebas dari teknisi
     * @return array{suffix: ?string, sequence: ?int}
     */
    protected function extractTypeSuffixAndSequence(string $text): array
    {
        // Prioritas: suffix yang langsung menempel setelah 4-digit section code,
        // dengan sequence number opsional di akhir (-N).
        // Contoh: "6600P2A1" => suffix=P2A1 | "6163C1-2" => suffix=C1, seq=2
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
     * @param  string        $text Teks bebas dari teknisi
     * @return array<string>       Daftar prefix unik yang terdeteksi (bisa kosong)
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
}
