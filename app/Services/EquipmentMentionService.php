<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CmEquipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pendeteksi penyebutan kode equipment (tech ident no / equipment tag) di dalam
 * teks laporan teknisi.
 *
 * Latar belakang (insiden 19/10/2026): laporan teknisi dari wizard Telegram
 * hanya menyimpan asset_id, TIDAK menyimpan equipment_tag. Backfill lama juga
 * mengisi equipment_tag dengan assets.equipment_no (mis. 10002840), padahal
 * Condition Monitoring memakai assets.tech_ident_no (mis. 6400P5). Akibatnya
 * halaman "Laporan Teknisi" pada detail equipment CM selalu 0 laporan.
 *
 * Service ini dipakai untuk:
 *   1. mencocokkan tag di teks laporan secara PERSIS (bukan LIKE wildcard),
 *      sehingga "2-6163P8" TIDAK dianggap sebagai "6163P8";
 *   2. melengkapi equipment_tag laporan lama lewat command
 *      `php artisan reports:tag-equipment`.
 *
 * Aturan pencocokan:
 *   - huruf besar/kecil diabaikan;
 *   - pemisah di dalam tag boleh berbeda (spasi, titik, garis miring, strip):
 *     "6811P05" cocok dengan "6811 P05", "6811-P05", "6811/P05", dst.;
 *   - tag harus berada di batas kata (karakter sebelum/sesudah bukan huruf/angka);
 *   - bila dalam satu teks ada tag yang LEBIH PANJANG dan memuat tag ini,
 *     penyebutan tag pendek di dalamnya diabaikan (longest match wins).
 */
class EquipmentMentionService
{
    /**
     * Panjang minimum kode (setelah karakter pemisah dibuang) agar layak
     * dianggap penyebutan equipment. Kode pendek seperti "P1" terlalu rawan
     * menabrak kata biasa.
     */
    public const MIN_LENGTH = 4;

    /** Karakter pemisah yang boleh muncul di antara karakter kode. */
    private const SEPARATOR = '[\s._\/-]*';

    /**
     * Cache daftar tag yang dikenal pada satu request/command.
     *
     * @var array<int, string>|null
     */
    private ?array $knownTags = null;

    /**
     * Seluruh kode equipment yang dikenal sistem: cm_equipment.equipment_tag
     * (kunci utama halaman CM) dan assets.tech_ident_no.
     *
     * @return array<int, string>
     */
    public function knownTags(): array
    {
        if ($this->knownTags !== null) {
            return $this->knownTags;
        }

        $cmTags = CmEquipment::query()
            ->whereNotNull('equipment_tag')
            ->where('equipment_tag', '<>', '')
            ->pluck('equipment_tag');

        $assetTags = Asset::query()
            ->whereNotNull('tech_ident_no')
            ->where('tech_ident_no', '<>', '')
            ->pluck('tech_ident_no');

        $unik = [];

        foreach ($cmTags->merge($assetTags) as $tag) {
            $tag = trim((string) $tag);

            if ($tag === '') {
                continue;
            }

            $kunci = $this->normalize($tag);

            if ($kunci === '' || strlen($kunci) < self::MIN_LENGTH) {
                continue;
            }

            // Bentuk pertama yang ditemukan dipakai sebagai bentuk kanonik.
            $unik[$kunci] ??= $tag;
        }

        return $this->knownTags = array_values($unik);
    }

    /**
     * Cari semua tag yang benar-benar disebut di teks, urut sesuai kemunculan.
     *
     * @param  string  $text  Deskripsi pekerjaan / teks bebas lainnya
     * @return array<int, string> Daftar tag dalam bentuk aslinya (kanonik)
     */
    public function mentions(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $upper = strtoupper($text);
        $flatText = $this->normalize($text);

        if ($flatText === '') {
            return [];
        }

        $temuan = [];

        foreach ($this->knownTags() as $tag) {
            $flatTag = $this->normalize($tag);

            // Saringan cepat: tanpa kemunculan berurutan, tidak mungkin cocok.
            if ($flatTag === '' || ! str_contains($flatText, $flatTag)) {
                continue;
            }

            $pattern = $this->phpPattern($tag);

            if ($pattern === null || ! preg_match($pattern, $upper, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $temuan[] = [
                'tag' => $tag,
                'start' => $match[1][1],
                'end' => $match[1][1] + strlen($match[1][0]),
            ];
        }

        if ($temuan === []) {
            return [];
        }

        // Longest match wins: buang tag pendek yang rentangnya berada di dalam
        // rentang tag yang lebih panjang (mis. "6163P8" di dalam "2-6163P8").
        usort($temuan, fn (array $a, array $b) => ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']));

        $dipakai = [];

        foreach ($temuan as $item) {
            $bertabrakan = false;

            foreach ($dipakai as $lain) {
                if ($item['start'] < $lain['end'] && $lain['start'] < $item['end']) {
                    $bertabrakan = true;
                    break;
                }
            }

            if (! $bertabrakan) {
                $dipakai[] = $item;
            }
        }

        usort($dipakai, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return array_values(array_map(fn (array $item) => $item['tag'], $dipakai));
    }

    /**
     * Tag pertama yang disebut di teks (dipakai untuk mengisi equipment_tag
     * laporan lama yang hanya menyebut satu equipment).
     */
    public function primary(string $text): ?string
    {
        return $this->mentions($text)[0] ?? null;
    }

    /**
     * Tambahkan kondisi "deskripsi menyebut tag ini" ke query.
     *
     * Hanya dipakai untuk laporan lama yang BELUM punya equipment_tag dan
     * asset_id — laporan yang sudah tertaut tidak pernah dicocokkan lewat teks
     * supaya tidak salah equipment (permintaan user 14/10/2026).
     */
    public function whereMentions(Builder $query, string $tag, string $column = 'work_description'): Builder
    {
        return $this->applyMentionConstraint($query, $tag, $column, 'and');
    }

    /**
     * Sama seperti whereMentions(), tetapi digabung dengan OR.
     */
    public function orWhereMentions(Builder $query, string $tag, string $column = 'work_description'): Builder
    {
        return $this->applyMentionConstraint($query, $tag, $column, 'or');
    }

    /**
     * Pola REGEXP MySQL/SQLite untuk satu tag (null bila tag tidak layak).
     */
    public function sqlPattern(string $tag): ?string
    {
        $core = $this->corePattern($tag);

        if ($core === null) {
            return null;
        }

        return '(^|[^0-9A-Z])'.$core.'([^0-9A-Z]|$)';
    }

    /**
     * Normalisasi kode: huruf besar tanpa karakter pemisah.
     */
    public function normalize(string $tag): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($tag)));
    }

    /**
     * Bangun kondisi query penyebutan tag.
     *
     * @param  string  $boolean  'and' atau 'or'
     */
    private function applyMentionConstraint(Builder $query, string $tag, string $column, string $boolean): Builder
    {
        $pattern = $this->sqlPattern($tag);

        if ($pattern === null) {
            return $query;
        }

        $pengecualian = $this->sqlConflictPatterns($tag);

        $callback = function (Builder $q) use ($column, $pattern, $pengecualian): void {
            $q->whereRaw("UPPER(COALESCE({$column}, '')) REGEXP ?", [$pattern]);

            foreach ($pengecualian as $polaLain) {
                $q->whereRaw("UPPER(COALESCE({$column}, '')) NOT REGEXP ?", [$polaLain]);
            }
        };

        return $boolean === 'or' ? $query->orWhere($callback) : $query->where($callback);
    }

    /**
     * Pola tag lain yang lebih panjang dan memuat tag ini. Teks yang memuat tag
     * panjang tersebut tidak boleh dihitung sebagai penyebutan tag pendek.
     *
     * @return array<int, string>
     */
    private function sqlConflictPatterns(string $tag): array
    {
        $flatTag = $this->normalize($tag);

        if ($flatTag === '') {
            return [];
        }

        $hasil = [];

        foreach ($this->knownTags() as $lain) {
            $flatLain = $this->normalize($lain);

            if ($flatLain === $flatTag || strlen($flatLain) <= strlen($flatTag)) {
                continue;
            }

            if (! str_contains($flatLain, $flatTag)) {
                continue;
            }

            $pola = $this->sqlPattern($lain);

            if ($pola !== null) {
                $hasil[] = $pola;
            }

            // Batasi jumlah kondisi supaya query tetap ringkas.
            if (count($hasil) >= 20) {
                break;
            }
        }

        return $hasil;
    }

    /**
     * Pola PCRE untuk mendeteksi tag pada teks yang sudah di-uppercase.
     */
    private function phpPattern(string $tag): ?string
    {
        $core = $this->corePattern($tag);

        if ($core === null) {
            return null;
        }

        return '#(?<![0-9A-Z])('.$core.')(?![0-9A-Z])#';
    }

    /**
     * Inti pola: setiap karakter kode dipisah pemisah opsional, sehingga
     * "6811P05", "6811 P05", dan "6811-P05" sama-sama cocok.
     */
    private function corePattern(string $tag): ?string
    {
        $flat = $this->normalize($tag);

        if ($flat === '' || strlen($flat) < self::MIN_LENGTH) {
            return null;
        }

        return implode(self::SEPARATOR, str_split($flat));
    }
}
