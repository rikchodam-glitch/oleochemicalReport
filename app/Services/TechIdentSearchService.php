<?php

namespace App\Services;

use App\Models\Asset;
use App\Services\Traits\TechIdentTokenizerTrait;
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
 *
 * Logika tokenisasi dan normalisasi teks ada di TechIdentTokenizerTrait.
 */
class TechIdentSearchService
{
    use TechIdentTokenizerTrait;

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
        $extracted  = $this->extractTypeSuffixAndSequence($rawText);
        $typeSuffix = $extracted['suffix'];
        $seqNumber  = $extracted['sequence'];

        if ($section && $typeSuffix) {
            $normalizedSuffix = strtoupper($typeSuffix);

            $matches = $assets->filter(function ($a) use ($section, $normalizedSuffix) {
                $normalized = $this->normalize($a->tech_ident_no);
                return str_contains($normalized, $section) && str_contains($normalized, $normalizedSuffix);
            })->values();

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

        // Pass 3b: prefix instrumen dari deskripsi + section code.
        // Menangani kasus seperti "Level Switch High 6600V2" => prefix=LSH, section=6600.
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
     *
     * @param  int|null  $areaId ID area untuk filter, null berarti semua area
     * @return Collection
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
        ])->all();
    }
}
