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
 *   - extractLocationSegment()     : Ekstrak segmen digit pembeda lokasi di depan section code
 *   - detectLocationHint()         : Deteksi kode/nama Area yang disebut eksplisit di teks bebas
 *   - detectInstrumentPrefixes()   : Deteksi prefix instrumen dari kata kunci eksplisit
 *
 * Dikelompokkan bersama karena semuanya bertugas menguraikan teks mentah
 * menjadi komponen pencarian yang terstruktur, tanpa menyentuh database.
 * Khusus detectLocationHint(), daftar kandidat Area/SubArea tetap harus
 * disediakan oleh pemanggil (bukan trait ini yang melakukan query).
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
     * Ekstrak segmen digit pembeda lokasi yang mendahului section code
     * langsung sebelum tanda '-' (contoh: "2-6166C1" -> segmen lokasi "2",
     * section "6166"). Segmen ini dipakai untuk membedakan equipment yang
     * section+suffix-nya sama tapi berada di lokasi/train berbeda (mis.
     * FCV-2-6166C1-1 di BD02 vs FCV-6166C1-1 di BD01).
     *
     * Method ini melengkapi extractTypeSuffixAndSequence(), yang hanya
     * menangkap sequence number di BELAKANG section+suffix (-N di akhir),
     * bukan digit pembeda yang berada di DEPAN section code.
     *
     * Contoh:
     *   "Control Valve Glycerine 2-6166C1 BD 2" => "2"
     *   "FCV-2-6166C1-1"                        => "2"
     *   "FT-6166C1-2"                           => null (tidak ada digit di depan section)
     *   "6163E3A"                               => null
     *
     * @param  string      $text Teks bebas dari teknisi
     * @return string|null       Segmen digit pembeda lokasi jika ditemukan, null jika tidak
     */
    protected function extractLocationSegment(string $text): ?string
    {
        // Cari 1-2 digit yang langsung diikuti tanda '-' lalu section code
        // 4-digit (pola yang sama dengan extractSectionCode()/
        // extractTypeSuffixAndSequence() untuk section code itu sendiri).
        if (preg_match('/\b(\d{1,2})-\d{4}(?=[A-Za-z]|\b)/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Deteksi kode Area yang disebut secara eksplisit di teks bebas teknisi,
     * baik lewat kode (mis. "BD 2", "BD02", tanpa leading zero pun tetap cocok)
     * maupun lewat nama Area (mis. "Boiler Deaerator" jika itu nama Area-nya).
     *
     * Method ini TIDAK menyentuh database — daftar kandidat Area/SubArea
     * harus disediakan oleh pemanggil (biasanya hasil query Area::all()
     * atau cache serupa), agar trait ini tetap murni mengurai teks.
     *
     * Prioritas hasil: kandidat dengan kecocokan paling spesifik (nama/kode
     * terpanjang yang cocok) yang dikembalikan, agar kode/nama yang lebih
     * umum tidak menutupi kecocokan yang lebih tepat.
     *
     * Contoh:
     *   "Control Valve Glycerine 2-6166C1 BD 2", area code "BD02" => "BD02"
     *   "cek area Boiler Deaerator", area code "BD02" nama "Boiler Deaerator" => "BD02"
     *   "laporan pompa 6163P4" (tidak ada sinyal lokasi)          => null
     *
     * @param  string                                        $text  Teks bebas dari teknisi
     * @param  iterable<object{code:string,name:?string}|array{code:string,name?:?string}> $areas Daftar kandidat Area (code & name)
     * @return string|null Kode Area (Area->code) yang paling cocok, atau null jika tidak ada
     */
    protected function detectLocationHint(string $text, iterable $areas): ?string
    {
        $textUpper = strtoupper($text);
        $candidates = [];

        foreach ($areas as $area) {
            $code = is_array($area) ? ($area['code'] ?? null) : ($area->code ?? null);
            $name = is_array($area) ? ($area['name'] ?? null) : ($area->name ?? null);

            if ($code === null || $code === '') {
                continue;
            }

            if ($this->areaCodeMatchesText($textUpper, $code)) {
                $candidates[] = ['code' => $code, 'specificity' => strlen($code)];
                continue;
            }

            if ($name !== null && $name !== '' && $this->areaNameMatchesText($textUpper, $name)) {
                $candidates[] = ['code' => $code, 'specificity' => strlen($name)];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Urutkan berdasarkan kecocokan paling spesifik (kode/nama terpanjang) dulu
        usort($candidates, fn ($a, $b) => $b['specificity'] <=> $a['specificity']);

        return $candidates[0]['code'];
    }

    /**
     * Cocokkan kode Area terhadap teks bebas, mendukung penulisan tanpa
     * leading zero (mis. kode "BD02" tetap cocok dengan teks "BD 2" atau "BD-2").
     *
     * @param  string $textUpper Teks bebas yang sudah di-uppercase
     * @param  string $code      Kode Area, mis. "BD02"
     * @return bool              True jika kode Area disebut di teks
     */
    protected function areaCodeMatchesText(string $textUpper, string $code): bool
    {
        $codeUpper = strtoupper($code);

        if (str_contains($textUpper, $codeUpper)) {
            return true;
        }

        // Pisahkan prefix huruf dan digit numerik agar leading zero bisa diabaikan
        // (mis. "BD02" -> huruf "BD", digit "02" -> numerik 2), lalu izinkan
        // spasi/tanda '-' opsional di antara huruf dan digitnya di teks.
        if (!preg_match('/^([A-Z]+)(\d+)$/', $codeUpper, $m)) {
            return false;
        }

        [, $letters, $digits] = $m;
        $numeric = (int) $digits;

        $pattern = '/\b' . preg_quote($letters, '/') . '[\s\-]*0*' . $numeric . '(?!\d)/';

        return (bool) preg_match($pattern, $textUpper);
    }

    /**
     * Cocokkan nama Area terhadap teks bebas (word boundary, case-insensitive
     * karena kedua sisi sudah di-uppercase-kan oleh pemanggil).
     *
     * @param  string $textUpper Teks bebas yang sudah di-uppercase
     * @param  string $name      Nama Area, mis. "Boiler Deaerator"
     * @return bool              True jika nama Area disebut di teks
     */
    protected function areaNameMatchesText(string $textUpper, string $name): bool
    {
        $nameUpper = trim(strtoupper($name));

        if ($nameUpper === '') {
            return false;
        }

        $pattern = '/\b' . preg_quote($nameUpper, '/') . '\b/';

        return (bool) preg_match($pattern, $textUpper);
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
