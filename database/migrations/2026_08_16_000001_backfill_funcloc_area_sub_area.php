<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill area_id & sub_area_id pada tabel functional_locations.
 *
 * Latar belakang (Sesi 7 audit integritas data lokasi):
 * Asset menyimpan area_id yang benar, tetapi sebagian besar node
 * FunctionalLocation level Area (L2) dan Section (L3) memiliki area_id
 * NULL — 55 dari 98 node. Ini membuat hierarki lokasi tidak sinkron
 * dengan data equipment dan merusak pencarian lokasi eksplisit di bot
 * Telegram (pre-filter lokasi tidak bisa memprioritaskan kandidat di
 * area yang disebut teknisi).
 *
 * Aturan backfill (hanya mengisi node yang masih NULL, idempoten):
 * 1. area_id  : cocokkan setiap segmen kode funcloc (dipisah '-') dengan
 *    kode Area yang ada di tabel areas. Segmen paling akhir yang cocok
 *    dipakai (paling spesifik). Contoh:
 *      EPE-PROD-TF01-6010  -> segmen TF01 cocok dengan area TF01
 *      EPE-PROD-BD01-6163  -> segmen BD01 cocok dengan area BD01
 *    Node level Site (L0)/Departemen (L1) sengaja dibiarkan NULL karena
 *    bukan representasi area.
 * 2. sub_area_id : untuk node level Section (L3), ambil segmen terakhir
 *    kode (bagian numerik) lalu cocokkan dengan SubArea yang code-nya sama
 *    DAN area_id-nya sama dengan area yang sudah di-backfill/diketahui.
 *    Kode SubArea bisa duplikat antar area (mis. 6153 di BD01 & BD02),
 *    sehingga area harus ikut dijadikan pembeda.
 *
 * Migration dipakai juga untuk keperluan deploy server — jalankan via
 * `php artisan migrate` di environment mana pun.
 */
return new class extends Migration
{
    /**
     * Jalankan migration: isi area_id & sub_area_id yang masih NULL.
     */
    public function up(): void
    {
        // Kumpulkan data referensi sekali, bukan per-row.
        $areaRows   = DB::table('areas')->select('id', 'code', 'name')->get();
        $areaByCode = $areaRows->groupBy(fn ($a) => strtoupper((string) $a->code));

        // Jumlah pemakaian area_id di tabel assets — sumber kebenaran area
        // equipment yang dipakai untuk menyelesaikan kode area duplikat.
        $assetCounts = DB::table('assets')
            ->selectRaw('area_id, count(*) as total')
            ->whereNotNull('area_id')
            ->groupBy('area_id')
            ->pluck('total', 'area_id');

        $subAreas = DB::table('sub_areas')->select('id', 'code', 'area_id')->get();

        $funclocs = DB::table('functional_locations')
            ->select('id', 'code', 'level', 'area_id', 'sub_area_id')
            ->get();

        foreach ($funclocs as $fl) {
            $code = strtoupper(trim((string) $fl->code));

            // === 1. area_id — node yang masih NULL dan punya segmen area ===
            if ($fl->area_id === null && (int) $fl->level >= 2) {
                $areaId = $this->resolveAreaId($code, $areaByCode, $assetCounts, $subAreas);
                if ($areaId !== null) {
                    DB::table('functional_locations')
                        ->where('id', $fl->id)
                        ->update(['area_id' => $areaId]);
                    $fl->area_id = $areaId;
                }
            }

            // === 2. sub_area_id — node level Section (L3) yang masih NULL ===
            if ($fl->sub_area_id === null && (int) $fl->level === 3 && $fl->area_id !== null) {
                $subAreaId = $this->matchSubAreaIdFromSegments($code, (int) $fl->area_id, $subAreas);
                if ($subAreaId !== null) {
                    DB::table('functional_locations')
                        ->where('id', $fl->id)
                        ->update(['sub_area_id' => $subAreaId]);
                }
            }
        }
    }

    /**
     * Batalkan migration: kosongkan kembali kolom yang hanya diisi oleh
     * migration ini. Karena backfill hanya menyentuh baris yang sebelumnya
     * NULL, rollback cukup mengembalikan ke NULL untuk node level Area/Section
     * yang area_id/sub_area_id-nya bisa diisi ulang lewat up().
     */
    public function down(): void
    {
        DB::table('functional_locations')
            ->whereIn('level', [2, 3])
            ->update([
                'area_id'     => null,
                'sub_area_id' => null,
            ]);
    }

    /**
     * Resolusi area_id untuk satu kode funcloc.
     *
     * Iterasi segmen dari paling dalam (paling kanan) agar segmen yang paling
     * spesifik menang. Contoh "EPE-PROD-TF01-6010": segmen "6010" bukan kode
     * area, lanjut ke "TF01" yang cocok -> area TF01.
     *
     * @param  string                                                $code       Kode funcloc, sudah uppercase
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, object>>  $areaByCode Kode area (uppercase) -> baris areas
     * @param  \Illuminate\Support\Collection<int, int>              $assetCounts area_id -> jumlah asset
     * @param  \Illuminate\Support\Collection<int, object>           $subAreas    Semua SubArea
     * @return int|null                                              ID area yang dipilih, atau null
     */
    private function resolveAreaId(string $code, $areaByCode, $assetCounts, $subAreas): ?int
    {
        $segments = explode('-', $code);

        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $seg = $segments[$i];
            if ($seg === '' || !$areaByCode->has($seg)) {
                continue;
            }

            $candidates = $areaByCode->get($seg)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            if ($candidates->count() === 1) {
                return $candidates->first();
            }

            // Kode area duplikat: coba selesaikan dengan sub-area pembeda bila
            // segmen berikutnya numerik dan cocok dengan SubArea di area kandidat.
            $next = $segments[$i + 1] ?? null;
            if ($next !== null && preg_match('/^\d+$/', $next)) {
                foreach ($subAreas as $subArea) {
                    if ((string) $subArea->code === $next && $candidates->contains((int) $subArea->area_id)) {
                        return (int) $subArea->area_id;
                    }
                }
            }

            // Selesaikan dengan id yang paling banyak dipakai tabel assets.
            $bestId    = null;
            $bestCount = -1;
            foreach ($candidates as $id) {
                $count = (int) ($assetCounts[$id] ?? 0);
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestId    = $id;
                }
            }

            if ($bestId !== null) {
                return $bestId;
            }

            // Tie-breaker: id terkecil.
            return $candidates->min();
        }

        return null;
    }

    /**
     * Cocokkan segmen terakhir kode funcloc Section dengan SubArea.
     *
     * Segmen terakhir (mis. "6153" pada "EPE-PROD-BD01-6153") harus cocok
     * dengan code SubArea DAN area SubArea harus sama dengan area node.
     * Kode SubArea bisa duplikat antar area, jadi area adalah pembeda.
     *
     * @param  string  $code     Kode funcloc, sudah uppercase
     * @param  int     $areaId   ID area node (hasil backfill/diketahui)
     * @param  \Illuminate\Support\Collection<int, object>  $subAreas Semua SubArea
     * @return int|null          ID SubArea yang cocok, atau null
     */
    private function matchSubAreaIdFromSegments(string $code, int $areaId, $subAreas): ?int
    {
        $segments = explode('-', $code);
        $last     = end($segments);

        if ($last === false || $last === '' || !preg_match('/^\d+$/', $last)) {
            return null;
        }

        foreach ($subAreas as $subArea) {
            if ((string) $subArea->code === $last && (int) $subArea->area_id === $areaId) {
                return (int) $subArea->id;
            }
        }

        return null;
    }
};
