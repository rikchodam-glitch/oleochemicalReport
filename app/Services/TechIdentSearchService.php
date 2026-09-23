<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Asset;
use App\Services\Traits\TechIdentTokenizerTrait;
use Illuminate\Support\Collection;

/**
 * Pencarian TechIdentNo 3-pass sesuai dokumen "E. Logika Pencarian TechIdentNo".
 *
 * Pass 1: exact match full string apa adanya.
 * Pass 2: exact match setelah normalisasi (uppercase, hapus separator, strip prefix).
 * Pass 3: kombinasi section code 4-digit + tipe/unit alat disebut bersamaan.
 * Pass 3b: prefix instrumen dari deskripsi + section code (tanpa suffix eksplisit).
 * Pass 4: partial/prefix match setelah normalisasi (mis. "UPSSER" -> UPS-SER01/02).
 *
 * Pass 1 & 2 selalu auto-accept (confidence >= 95%, satu kandidat).
 * Pass 3/3b auto-accept jika kandidatnya tunggal, atau confirm (80-94%) jika ambigu.
 * Jika tidak ada match sama sekali, status no_match — alur selanjutnya
 * (tulis ulang / hierarki FuncLoc) ditangani oleh ClarificationService.
 *
 * Kesadaran lokasi (Pass 3/3b): sebelum kandidat dipotong lewat ->take(),
 * dua sinyal lokasi dipakai bersamaan dengan $areaId yang sudah ada:
 *   - Segmen digit pembeda lokasi di depan section code (mis. "2" pada
 *     "2-6166C1"), hasil TechIdentTokenizerTrait::extractLocationSegment().
 *     Dipakai sebagai SYARAT TAMBAHAN saat memfilter $matches — bukan
 *     hanya section+suffix seperti sebelumnya.
 *   - Kode/nama Area yang disebut eksplisit di teks, hasil
 *     TechIdentTokenizerTrait::detectLocationHint(). Dipakai untuk
 *     MEMPRIORITASKAN kandidat di area tsb ke posisi teratas sebelum
 *     ->take(4)/->take(5), agar kandidat yang benar tidak hilang akibat
 *     pemotongan saat section+suffix cocok di banyak lokasi sekaligus.
 * Keduanya tidak pernah menghilangkan seluruh kandidat: jika sinyal lokasi
 * tidak ditemukan, atau ditemukan tapi tidak ada kandidat yang cocok, alur
 * kembali ke perilaku lama (tanpa filter/urutan lokasi) — tidak ada regresi
 * pada laporan yang memang tidak menyebut lokasi.
 *
 * Prioritas sinyal pada Pass 3 saat sequence number terdeteksi (mis. "-1" di
 * akhir "2-6166C1-1"): banyak aset berbeda jenis pada section+lokasi yang sama
 * kebetulan berakhiran sequence yang sama (mis. FCV-2-6166C1-1, FT-2-6166C1-1,
 * LT-2-6166C1-1, dst semuanya "-1"). Jika filter sequence tidak benar-benar
 * menyempitkan kandidat (masih >1), kode TIDAK langsung mengambil kandidat
 * pertama lewat ->take() — kata kunci instrumen eksplisit yang disebut
 * teknisi (mis. "control valve" -> FCV, lihat INSTRUMENT_KEYWORDS di
 * TechIdentTokenizerTrait) tetap dicoba dulu untuk menyempitkan lebih lanjut.
 * Baru jika kata kunci instrumen juga tidak menyempitkan apa pun (atau tidak
 * ada kata kunci sama sekali), alur jatuh ke kandidat hasil filter sequence
 * apa adanya (perilaku lama, tidak ada regresi). Lihat filterByInstrumentPrefixes().
 *
 * Logika tokenisasi dan normalisasi teks ada di TechIdentTokenizerTrait.
 */
class TechIdentSearchService
{
    use TechIdentTokenizerTrait;

    /**
     * Ambang jumlah kandidat: jika sebuah pass menemukan lebih dari ini DAN
     * kandidat tersebar di lebih dari satu tipe alat, hasil dikembalikan
     * sebagai pilihan tipe (type_confirm) alih-alih daftar kandidat individual.
     */
    public const TYPE_CONFIRM_THRESHOLD = 5;

    /**
     * Jalankan pencarian 3-pass terhadap teks mentah dari teknisi.
     *
     * @param  string   $rawText Teks laporan awal dari teknisi
     * @param  int|null $areaId  Batasi pencarian ke area tertentu jika sudah diketahui
     * @param  bool     $itOnly  true = hanya perangkat yang berasal dari data IT Assets
     *                           (baris cermin dengan data_source 'it_sync'), dipakai
     *                           saat teknisi memilih jenis pekerjaan IT di wizard
     * @return array{status: string, pass: ?string, confidence: int, candidates: array}
     */
    public function search(string $rawText, ?int $areaId = null, bool $itOnly = false): array
    {
        $assets = $this->candidateAssets($areaId, $itOnly);
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
                // Kode ini juga bisa menjadi bagian dari TechIdentNo alat lain
                // (mis. "6400P4" muncul di "FCV-6400P4-1", "PI-6400P4-1", dst).
                // Jika ada lebih dari satu, kembalikan pilihan tipe.
                $related = $this->relatedAssets($assets, $token);
                if ($related->count() > 1) {
                    return $this->confirmOrTypeConfirm($related, 'pass1', 85);
                }

                return $this->autoAccept('pass1', 100, [$hit]);
            }
        }

        // Pass 2: exact match setelah normalisasi
        foreach ($tokens as $token) {
            $normalizedToken = $this->normalize($token);
            $hit = $assets->first(fn ($a) => $this->normalize($a->tech_ident_no) === $normalizedToken);
            if ($hit) {
                $related = $this->relatedAssets($assets, $token);
                if ($related->count() > 1) {
                    return $this->confirmOrTypeConfirm($related, 'pass2', 88);
                }

                return $this->autoAccept('pass2', 98, [$hit]);
            }
        }

        // Sinyal lokasi (Sesi 2): dihitung sekali sebelum Pass 3/3b, dipakai
        // di kedua pass. $rankingAreaId memakai $areaId yang sudah diketahui
        // jika ada; jika tidak, coba deteksi dari kode/nama Area yang
        // disebut eksplisit di teks (lihat resolveAreaIdForRanking()).
        $locationSegment = $this->extractLocationSegment($rawText);
        $rankingAreaId   = $this->resolveAreaIdForRanking($areaId, $rawText);

        // Kata kunci instrumen eksplisit (mis. "control valve" -> FCV). Dihitung
        // sekali di sini karena dipakai baik oleh blok sequence-number maupun
        // blok section+suffix umum di bawahnya.
        $instrumentPrefixes = $this->detectInstrumentPrefixes($rawText);

        // Pass 3: section code 4-digit + tipe/unit alat cocok bersamaan
        $section    = $this->extractSectionCode($rawText);
        $extracted  = $this->extractTypeSuffixAndSequence($rawText);
        $typeSuffix = $extracted['suffix'];
        $seqNumber  = $extracted['sequence'];

        if ($section && $typeSuffix) {
            $normalizedSuffix = strtoupper($typeSuffix);

            $matches = $assets->filter(function ($a) use ($section, $normalizedSuffix) {
                $normalized = $this->normalize($a->tech_ident_no);
                return str_contains($normalized, $section) && str_contains($normalized, $normalizedSuffix);
            })->values();

            // Sinyal lokasi: segmen digit pembeda lokasi jadi syarat tambahan
            // saat memfilter $matches (mis. "2" menyaring FCV-2-6166C1-1 dari
            // FCV-6166C1-1 yang section+suffix-nya sama tapi lokasinya beda).
            $matches = $this->narrowByLocationSegment($matches, $locationSegment, $section);

            // Sinyal lokasi: kandidat di area yang terdeteksi/diketahui
            // diprioritaskan ke depan agar tidak hilang akibat ->take() di bawah.
            $matches = $this->prioritizeByArea($matches, $rankingAreaId);

            // Jika ada sequence number dan kandidat lebih dari satu,
            // filter ke instance yang spesifik (misal: -2 dari PT-2-6163C1-2).
            if ($seqNumber !== null && $matches->count() > 1) {
                $seqMatches = $matches->filter(
                    fn ($a) => preg_match('/-' . $seqNumber . '$/', $a->tech_ident_no)
                )->values();

                if ($seqMatches->count() === 1) {
                    return $this->autoAccept('pass3_seq', 95, [$seqMatches->first()]);
                }

                if ($seqMatches->count() > 1) {
                    // Sequence number saja belum tentu cukup menyempitkan — banyak
                    // aset berbeda jenis kebetulan berakhiran sequence yang sama
                    // (mis. FCV/FT/LT/LSLL/PT/TT semuanya "-1"). Coba sempitkan lagi
                    // dengan kata kunci instrumen eksplisit sebelum jatuh ke
                    // kandidat acak hasil urutan query database lewat take().
                    $seqInstrumentMatches = $this->filterByInstrumentPrefixes($seqMatches, $instrumentPrefixes);

                    if ($seqInstrumentMatches->count() === 1) {
                        return $this->autoAccept('pass3_seq', 95, [$seqInstrumentMatches->first()]);
                    }

                    if ($seqInstrumentMatches->count() > 1) {
                        return $this->confirm('pass3_seq', 85, $seqInstrumentMatches->take(4)->all());
                    }

                    // Kata kunci instrumen tidak ditemukan atau tidak menyempitkan
                    // apa pun — fallback ke perilaku lama (tidak ada regresi pada
                    // laporan yang memang tidak menyebut jenis instrumen).
                    return $this->confirm('pass3_seq', 85, $seqMatches->take(4)->all());
                }
                // Jika tidak ada yang cocok dengan sequence itu, lanjut ke logika umum di bawah
            }

            if (!empty($instrumentPrefixes) && $matches->count() > 1) {
                $instrumentMatches = $this->filterByInstrumentPrefixes($matches, $instrumentPrefixes);

                if ($instrumentMatches->count() === 1) {
                    return $this->autoAccept('pass3', 95, [$instrumentMatches->first()]);
                }

                if ($instrumentMatches->count() > 1) {
                    return $this->confirmOrTypeConfirm($instrumentMatches, 'pass3', 85);
                }
            }

            if ($matches->count() === 1) {
                return $this->autoAccept('pass3', 95, [$matches->first()]);
            }

            if ($matches->count() > 1) {
                return $this->confirmOrTypeConfirm($matches, 'pass3', 85);
            }
        }

        // Pass 3b: prefix instrumen dari deskripsi + section code.
        // Menangani kasus seperti "Level Switch High 6600V2" => prefix=LSH, section=6600.
        // Berguna ketika teknisi menulis nama panjang instrumen tanpa kode singkat.
        if ($section) {
            $detectedPrefixes = $instrumentPrefixes;

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

                // Sinyal lokasi (sama seperti Pass 3): segmen digit pembeda
                // lokasi sebagai syarat tambahan, lalu prioritaskan area yang
                // terdeteksi/diketahui sebelum kandidat dipotong ->take(5).
                $prefixMatches = $this->narrowByLocationSegment($prefixMatches, $locationSegment, $section);
                $prefixMatches = $this->prioritizeByArea($prefixMatches, $rankingAreaId);

                if ($prefixMatches->count() === 1) {
                    return $this->autoAccept('pass3b', 92, [$prefixMatches->first()]);
                }

                if ($prefixMatches->count() > 1) {
                    return $this->confirmOrTypeConfirm($prefixMatches, 'pass3b', 80);
                }
            }
        }

        // Pass 4: partial/prefix match — teknisi hanya mengetik sebagian kode
        // (mis. "UPSSER" -> UPS-SER01, UPS-SER02). Dijalankan sebagai fallback
        // terakhir setelah pass terstruktur, dengan token minimal 4 karakter
        // supaya tidak membanjiri kandidat.
        foreach ($tokens as $token) {
            $partialToken = $this->normalize($token);

            if (strlen($partialToken) < 4) {
                continue;
            }

            $partialMatches = $assets->filter(function ($a) use ($partialToken) {
                return str_starts_with($this->normalize($a->tech_ident_no), $partialToken);
            })->values();

            if ($partialMatches->count() === 1) {
                return $this->autoAccept('pass4_partial', 90, [$partialMatches->first()]);
            }

            if ($partialMatches->count() > 1) {
                return $this->confirmOrTypeConfirm($partialMatches, 'pass4_partial', 80);
            }
        }

        return $this->noMatch();
    }

    /**
     * Ambil daftar asset kandidat, dibatasi ke area tertentu jika diketahui
     * (mempersempit ruang pencarian dan menghindari hasil dari area lain), dan
     * dibatasi ke perangkat IT bila $itOnly aktif.
     *
     * @param  int|null  $areaId ID area untuk filter, null berarti semua area
     * @param  bool      $itOnly true = hanya baris cermin IT Assets (data_source 'it_sync')
     * @return Collection
     */
    protected function candidateAssets(?int $areaId, bool $itOnly = false): Collection
    {
        $query = Asset::whereNotNull('tech_ident_no');

        if ($itOnly) {
            $query->where('data_source', 'it_sync');
        }

        if ($areaId) {
            $query->where('area_id', $areaId);
        }

        return $query->get(['id', 'tech_ident_no', 'description', 'functional_loc', 'area_id', 'equipment_type']);
    }

    /**
     * Ambil semua asset yang TechIdentNo-nya (setelah normalisasi) mengandung
     * token. Dipakai untuk mendeteksi ambiguitas saat sebuah kode cocok persis
     * tapi juga menjadi bagian dari kode alat lain (mis. "6400P4" muncul di
     * "FCV-6400P4-1", "PI-6400P4-1", dst).
     *
     * @param  Collection $assets Koleksi kandidat asset
     * @param  string     $token  Token yang dicari
     * @return Collection
     */
    protected function relatedAssets(Collection $assets, string $token): Collection
    {
        $normalizedToken = $this->normalize($token);

        return $assets->filter(
            fn ($a) => str_contains($this->normalize($a->tech_ident_no), $normalizedToken)
        )->values();
    }

    /**
     * Persempit kandidat berdasarkan segmen digit pembeda lokasi (hasil
     * TechIdentTokenizerTrait::extractLocationSegment()), dengan mensyaratkan
     * pola struktural "-{segmen}-{section}" pada TechIdentNo yang sudah
     * dinormalisasi — bukan sekadar str_contains digitnya di mana saja.
     *
     * Tidak pernah menghilangkan seluruh kandidat: jika tidak ada satupun
     * yang cocok dengan segmen lokasi tsb, kembalikan $matches apa adanya
     * (fallback ke perilaku lama) supaya tidak menimbulkan regresi ketika
     * format TechIdentNo di data ternyata sedikit berbeda dari yang diduga.
     *
     * @param  Collection   $matches          Kandidat hasil filter section+suffix
     * @param  string|null  $locationSegment  Segmen digit pembeda lokasi, null jika tidak terdeteksi
     * @param  string       $section          Section code 4-digit yang sedang dicocokkan
     * @return Collection
     */
    protected function narrowByLocationSegment(Collection $matches, ?string $locationSegment, string $section): Collection
    {
        if ($locationSegment === null || $matches->count() <= 1) {
            return $matches;
        }

        $needle = '-' . $locationSegment . '-' . $section;

        $narrowed = $matches->filter(
            fn ($a) => str_contains($this->normalize($a->tech_ident_no), $needle)
        )->values();

        return $narrowed->isEmpty() ? $matches : $narrowed;
    }

    /**
     * Urutkan kandidat supaya yang berada di $areaId muncul lebih dulu,
     * tanpa menghilangkan kandidat area lain. Dipakai sebelum ->take() agar
     * kandidat yang benar tidak terpotong hanya karena urutannya belakangan.
     *
     * @param  Collection  $candidates Kandidat yang akan diurutkan
     * @param  int|null    $areaId     Area yang diprioritaskan, null berarti tidak ada prioritas
     * @return Collection
     */
    protected function prioritizeByArea(Collection $candidates, ?int $areaId): Collection
    {
        if ($areaId === null || $candidates->count() <= 1) {
            return $candidates;
        }

        return $candidates->sortByDesc(fn ($a) => (int) ($a->area_id === $areaId))->values();
    }

    /**
     * Persempit kandidat berdasarkan prefix instrumen yang terdeteksi dari kata
     * kunci eksplisit di teks teknisi (mis. "control valve" -> FCV, lihat
     * TechIdentTokenizerTrait::detectInstrumentPrefixes()).
     *
     * Dipakai baik oleh blok sequence-number (saat sequence saja belum cukup
     * menyempitkan) maupun blok section+suffix umum, supaya logikanya tidak
     * ditulis dua kali dan tidak saling menyimpang.
     *
     * Tidak pernah menghilangkan seluruh kandidat secara implisit: jika tidak
     * ada prefix terdeteksi, atau tidak ada kandidat yang cocok prefix-nya,
     * method ini mengembalikan collection kosong — pemanggil yang menentukan
     * fallback-nya (biasanya kembali ke $matches/$seqMatches apa adanya).
     *
     * @param  Collection     $candidates Kandidat yang akan disempitkan
     * @param  array<string>  $prefixes   Daftar prefix instrumen terdeteksi, bisa kosong
     * @return Collection     Kandidat yang cocok salah satu prefix, atau kosong jika tidak ada yang cocok
     */
    protected function filterByInstrumentPrefixes(Collection $candidates, array $prefixes): Collection
    {
        if (empty($prefixes)) {
            return collect();
        }

        return $candidates->filter(function ($a) use ($prefixes) {
            $normalized = $this->normalize($a->tech_ident_no);
            foreach ($prefixes as $prefix) {
                if (str_starts_with($normalized, strtoupper($prefix))) {
                    return true;
                }
            }
            return false;
        })->values();
    }

    /**
     * Tentukan area mana yang dipakai untuk memprioritaskan kandidat di
     * Pass 3/3b. Memakai $areaId yang sudah diketahui pemanggil jika ada
     * (mis. dari state wizard); jika tidak ada, coba deteksi dari kode/nama
     * Area yang disebut eksplisit di teks lewat detectLocationHint().
     *
     * @param  int|null  $areaId  Area yang sudah diketahui pemanggil, jika ada
     * @param  string    $rawText Teks laporan awal dari teknisi
     * @return int|null           ID Area untuk prioritas, null jika tidak ada sinyal
     */
    protected function resolveAreaIdForRanking(?int $areaId, string $rawText): ?int
    {
        if ($areaId !== null) {
            return $areaId;
        }

        $areas = Area::select('id', 'code', 'name')->get();
        if ($areas->isEmpty()) {
            return null;
        }

        $detectedCode = $this->detectLocationHint($rawText, $areas);
        if ($detectedCode === null) {
            return null;
        }

        $area = $areas->first(fn ($a) => $a->code === $detectedCode);

        return $area?->id;
    }

    /**
     * Kembalikan struktur hasil auto-accept (Pass 1, 2, atau 3 dengan satu kandidat).
     *
     * @param  string  $pass       Identifier pass yang berhasil (pass1, pass2, pass3, dst.)
     * @param  int     $confidence Skor kepercayaan (0-100)
     * @param  array   $candidates Daftar objek Asset yang match
     * @return array
     */
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

    /**
     * Kembalikan struktur hasil confirm (Pass 3 dengan lebih dari satu kandidat).
     *
     * @param  string  $pass       Identifier pass
     * @param  int     $confidence Skor kepercayaan
     * @param  array   $candidates Daftar objek Asset yang ambigu
     * @return array
     */
    protected function confirm(string $pass, int $confidence, array $candidates): array
    {
        return [
            'status'     => 'confirm',
            'pass'       => $pass,
            'confidence' => $confidence,
            'candidates' => $this->formatCandidates($candidates),
        ];
    }

    /**
     * Putuskan apakah hasil sebuah pass ditampilkan sebagai daftar kandidat
     * individual (confirm) atau sebagai pilihan tipe alat (type_confirm).
     *
     * @param  Collection $matches    Koleksi asset yang cocok
     * @param  string     $pass       Identifier pass
     * @param  int        $confidence Skor kepercayaan
     * @param  int        $take       Maksimal kandidat individual
     * @return array
     */
    protected function confirmOrTypeConfirm(Collection $matches, string $pass, int $confidence, int $take = 4): array
    {
        $distinctTypes = $matches->pluck('equipment_type')->unique()->count();

        if ($matches->count() > self::TYPE_CONFIRM_THRESHOLD && $distinctTypes > 1) {
            return $this->typeConfirmResult($matches, $pass, $confidence);
        }

        return $this->confirm($pass, $confidence, $matches->take($take)->all());
    }

    /**
     * Kembalikan hasil type_confirm: kelompokkan kandidat berdasarkan tipe
     * alat agar teknisi memilih tipe dulu sebelum memilih equipment spesifik.
     *
     * @param  Collection $matches    Koleksi asset yang cocok
     * @param  string     $pass       Identifier pass
     * @param  int        $confidence Skor kepercayaan
     * @return array
     */
    protected function typeConfirmResult(Collection $matches, string $pass, int $confidence): array
    {
        $grouped = $matches->groupBy(fn ($a) => $a->equipment_type ?: 'Tanpa Tipe');

        $typeGroups = [];
        foreach ($grouped as $type => $list) {
            $typeGroups[] = [
                'type'  => $type,
                'count' => $list->count(),
            ];
        }

        // Urutkan dari jumlah terbanyak agar tipe paling relevan tampil di atas.
        usort($typeGroups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'status'      => 'type_confirm',
            'pass'        => $pass,
            'confidence'  => $confidence,
            'type_groups' => $typeGroups,
            'candidates'  => $this->formatCandidates($matches->all()),
        ];
    }

    /**
     * Kembalikan struktur hasil no_match — tidak ada asset yang cocok.
     *
     * @return array
     */
    protected function noMatch(): array
    {
        return [
            'status'     => 'no_match',
            'pass'       => null,
            'confidence' => 0,
            'candidates' => [],
        ];
    }

    /**
     * Format daftar objek Asset menjadi array asosiatif ringkas
     * untuk dikembalikan sebagai candidates di hasil pencarian.
     *
     * @param  array  $assets Daftar objek Asset
     * @return array
     */
    protected function formatCandidates(array $assets): array
    {
        return collect($assets)->map(fn ($a) => [
            'id'             => $a->id,
            'tech_ident_no'  => $a->tech_ident_no,
            'description'    => $a->description,
            'functional_loc' => $a->functional_loc,
            'equipment_type' => $a->equipment_type,
        ])->all();
    }
}
