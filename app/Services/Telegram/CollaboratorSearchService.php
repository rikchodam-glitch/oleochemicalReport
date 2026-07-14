<?php

namespace App\Services\Telegram;

use App\Models\Technician;
use Illuminate\Support\Collection;

/**
 * CollaboratorSearchService
 *
 * Mencari kandidat teknisi kolaborator berdasarkan NIK (exact match) atau
 * nama (fuzzy match LIKE). Hanya teknisi berstatus aktif yang disertakan,
 * dan teknisi pengirim laporan bisa dikecualikan dari hasil agar tidak
 * bisa menambahkan dirinya sendiri sebagai kolaborator.
 *
 * Pola service ini mengikuti TechIdentSearchService::search() — method
 * search() mengembalikan struktur array standar (exact match jika ada,
 * daftar kandidat, jumlah kandidat) sehingga pemanggil (WizardStepHandlerTrait)
 * bisa memutuskan alur berikutnya: langsung tambahkan, tampilkan keyboard
 * pilihan, atau tawarkan hierarki Dept/Section.
 */
class CollaboratorSearchService
{
    /**
     * Batas maksimum kandidat nama yang dikembalikan, agar keyboard pilihan
     * di Telegram tidak overflow di layar mobile.
     */
    const MAX_CANDIDATES = 4;

    /**
     * Panjang minimum query yang bisa diproses. Query yang terlalu pendek
     * (1-2 karakter) menghasilkan terlalu banyak false positive sehingga
     * tidak berguna sebagai pencarian nama.
     */
    const MIN_QUERY_LENGTH = 3;

    /**
     * Cari teknisi berdasarkan NIK (exact match) atau nama (fuzzy match).
     * NIK dicoba lebih dulu karena presisi lebih tinggi. Jika tidak ada
     * NIK yang cocok persis, baru dicoba pencarian nama dengan LIKE.
     * Hanya teknisi berstatus aktif yang disertakan.
     *
     * Query dengan panjang di bawah MIN_QUERY_LENGTH hanya diproses sebagai
     * NIK exact match, tidak sebagai pencarian nama, untuk menghindari
     * false positive yang terlalu banyak.
     *
     * @param  string      $query      Teks pencarian dari teknisi (NIK atau nama)
     * @param  string|null $excludeNik NIK yang dikecualikan dari hasil (biasanya NIK pengirim laporan)
     * @return array{exact: Technician|null, candidates: array<int, Technician>, count: int}
     */
    public function search(string $query, ?string $excludeNik = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return $this->emptyResult();
        }

        // Coba NIK exact match terlebih dahulu — berlaku untuk semua panjang query
        $exactByNik = $this->baseQuery($excludeNik)
            ->where('nik', $query)
            ->first();

        if ($exactByNik) {
            return [
                'exact'      => $exactByNik,
                'candidates' => [$exactByNik],
                'count'      => 1,
            ];
        }

        // Pencarian nama hanya dilakukan jika query cukup panjang untuk
        // menghindari hasil yang terlalu ambigu (misalnya query "A" atau "Ab")
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return $this->emptyResult();
        }

        $candidates = $this->baseQuery($excludeNik)
            ->where('name', 'like', '%' . $query . '%')
            ->orderBy('name')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        if ($candidates->isEmpty()) {
            return $this->emptyResult();
        }

        // Hanya satu kandidat nama yang cocok: perlakukan seperti exact match,
        // langsung bisa ditambahkan tanpa perlu keyboard pilihan.
        if ($candidates->count() === 1) {
            return [
                'exact'      => $candidates->first(),
                'candidates' => [$candidates->first()],
                'count'      => 1,
            ];
        }

        return [
            'exact'      => null,
            'candidates' => $candidates->all(),
            'count'      => $candidates->count(),
        ];
    }

    /**
     * Bangun query dasar teknisi aktif, dengan pengecualian NIK opsional.
     * Scope active() diasumsikan sudah tersedia di model Technician
     * (mengembalikan hanya teknisi dengan status = 'active').
     *
     * @param  string|null $excludeNik NIK yang dikecualikan dari hasil
     * @return \Illuminate\Database\Eloquent\Builder<Technician>
     */
    private function baseQuery(?string $excludeNik)
    {
        return Technician::active()
            ->when($excludeNik, fn ($q) => $q->where('nik', '!=', $excludeNik));
    }

    /**
     * Bangun struktur hasil kosong (tidak ada kandidat ditemukan sama sekali).
     *
     * @return array{exact: null, candidates: array, count: int}
     */
    private function emptyResult(): array
    {
        return [
            'exact'      => null,
            'candidates' => [],
            'count'      => 0,
        ];
    }
}
