<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\FuncLocSyncLog;
use App\Models\FunctionalLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FuncLocSyncService
{
    /**
     * Analisis seluruh assets.functional_loc untuk menemukan node
     * FunctionalLocation yang belum ada dan asset yang belum tertaut
     * funcloc_id, tanpa menyimpan perubahan apapun ke database.
     *
     * @return array{
     *     nodes: array,
     *     new_node_count: int,
     *     existing_node_count: int,
     *     assets_to_link: array,
     *     assets_to_link_count: int,
     *     invalid_codes: array,
     *     invalid_code_count: int,
     *     total_scanned: int
     * }
     */
    public function analyze(): array
    {
        $rawCodes = Asset::whereNotNull('functional_loc')
            ->where('functional_loc', '!=', '')
            ->distinct()
            ->pluck('functional_loc');

        if ($rawCodes->isEmpty()) {
            return $this->emptyAnalysis();
        }

        $assetCountsByRawCode = Asset::whereNotNull('functional_loc')
            ->where('functional_loc', '!=', '')
            ->whereNull('funcloc_id')
            ->selectRaw('functional_loc, COUNT(*) as total')
            ->groupBy('functional_loc')
            ->pluck('total', 'functional_loc');

        $nodeLevels = collect();
        $invalidCodes = collect();
        $assetsToLinkByCode = collect();

        foreach ($rawCodes as $rawCode) {
            $code = strtoupper(trim($rawCode));

            if ($code === '') {
                continue;
            }

            $segments = $this->splitSegments($code);
            $level = count($segments) - 1;
            $linkCount = (int) ($assetCountsByRawCode->get($rawCode) ?? 0);

            if ($level < FunctionalLocation::LEVEL_SITE || $level > FunctionalLocation::LEVEL_SECTION) {
                $invalidCodes->put($code, ($invalidCodes->get($code) ?? 0) + $linkCount);
                continue;
            }

            $current = null;

            foreach ($segments as $segmentLevel => $segment) {
                $current = $current !== null ? $current . '-' . $segment : $segment;
                $nodeLevels->put($current, $segmentLevel);
            }

            if ($linkCount > 0) {
                $assetsToLinkByCode->put($code, ($assetsToLinkByCode->get($code) ?? 0) + $linkCount);
            }
        }

        $existingCodes = FunctionalLocation::whereIn('code', $nodeLevels->keys())
            ->pluck('code')
            ->flip();

        $nodes = $nodeLevels
            ->map(fn ($level, $code) => [
                'code'   => $code,
                'level'  => $level,
                'exists' => $existingCodes->has($code),
            ])
            ->values()
            ->sortBy('level')
            ->values()
            ->all();

        return [
            'nodes'                => $nodes,
            'new_node_count'       => collect($nodes)->where('exists', false)->count(),
            'existing_node_count'  => collect($nodes)->where('exists', true)->count(),
            'assets_to_link'       => $this->collectionToPairs($assetsToLinkByCode),
            'assets_to_link_count' => $assetsToLinkByCode->sum(),
            'invalid_codes'        => $this->collectionToPairs($invalidCodes),
            'invalid_code_count'   => $invalidCodes->sum(),
            'total_scanned'        => $rawCodes->count(),
        ];
    }

    /**
     * Eksekusi hasil analyze(): buat node FunctionalLocation yang belum ada,
     * tautkan funcloc_id pada asset yang cocok, lalu catat hasilnya sebagai
     * log. Seluruh proses berjalan dalam satu transaksi database.
     *
     * @param  array  $analysis  Hasil dari analyze()
     * @param  bool   $dryRun    Jika true, node tetap dibuat di dalam transaksi
     *                           namun asset.funcloc_id tidak diupdate dan log
     *                           tidak disimpan (dipakai oleh command dry-run)
     * @return array{
     *     status: string,
     *     node_created_count: int,
     *     asset_linked_count: int,
     *     asset_skipped_empty_count: int,
     *     asset_missing_node_count: int
     * }
     */
    public function execute(array $analysis, bool $dryRun = false): array
    {
        $nodeResult = ['cache' => [], 'created' => 0];
        $linkResult = [0, 0, 0];

        DB::transaction(function () use ($analysis, $dryRun, &$nodeResult, &$linkResult) {
            $nodeResult = $this->createMissingNodes($analysis['nodes'] ?? []);
            $linkResult = $this->linkAssets($nodeResult['cache'], $dryRun);

            [$linkedCount, $skippedEmptyCount, $missingNodeCount] = $linkResult;

            if (! $dryRun) {
                FuncLocSyncLog::create([
                    'executed_by'                => auth()->id(),
                    'total_scanned'               => $analysis['total_scanned'] ?? 0,
                    'node_created_count'          => $nodeResult['created'],
                    'asset_linked_count'          => $linkedCount,
                    'asset_skipped_empty_count'   => $skippedEmptyCount,
                    'asset_missing_node_count'    => $missingNodeCount,
                    'detail_json'                 => $analysis,
                ]);
            }
        });

        [$linkedCount, $skippedEmptyCount, $missingNodeCount] = $linkResult;

        return [
            'status'                    => 'success',
            'node_created_count'        => $nodeResult['created'],
            'asset_linked_count'        => $linkedCount,
            'asset_skipped_empty_count' => $skippedEmptyCount,
            'asset_missing_node_count'  => $missingNodeCount,
        ];
    }

    /**
     * Buat seluruh node FunctionalLocation yang belum ada berdasarkan daftar
     * node hasil analyze(), diurutkan dari level teratas agar parent selalu
     * tersedia sebelum child dibuat.
     *
     * @param  array<int, array{code: string, level: int, exists?: bool}>  $nodes
     * @return array{cache: array<string, FunctionalLocation>, created: int}
     */
    public function createMissingNodes(array $nodes): array
    {
        $sorted = collect($nodes)->sortBy('level')->values();
        $cache = [];
        $created = 0;

        foreach ($sorted as $nodeData) {
            $code = $nodeData['code'];

            $existing = $cache[$code] ?? FunctionalLocation::where('code', $code)->first();

            if ($existing !== null) {
                $cache[$code] = $existing;
                continue;
            }

            $segments = $this->splitSegments($code);
            $segment = end($segments);
            $parentCode = count($segments) > 1 ? implode('-', array_slice($segments, 0, -1)) : null;
            $parent = $parentCode !== null ? ($cache[$parentCode] ?? null) : null;

            $node = FunctionalLocation::create([
                'code'      => $code,
                'segment'   => $segment,
                'name'      => $code,
                'level'     => $nodeData['level'],
                'parent_id' => $parent?->id,
                'is_active' => true,
            ]);

            $cache[$code] = $node;
            $created++;
        }

        return ['cache' => $cache, 'created' => $created];
    }

    /**
     * Tautkan assets.funcloc_id berdasarkan assets.functional_loc, memakai
     * cache node yang sudah dibuat/diambil pada createMissingNodes().
     *
     * @param  array<string, FunctionalLocation>  $nodeCache
     * @param  bool  $dryRun  Jika true, hitung saja tanpa menyimpan update
     * @return array{0: int, 1: int, 2: int}  [linked, skippedEmpty, missingNode]
     */
    public function linkAssets(array $nodeCache, bool $dryRun = false): array
    {
        $assets = Asset::whereNotNull('functional_loc')
            ->where('functional_loc', '!=', '')
            ->whereNull('funcloc_id')
            ->get();

        $linked = 0;
        $skippedEmpty = 0;
        $missingNode = 0;

        foreach ($assets as $asset) {
            $code = strtoupper(trim($asset->functional_loc));

            if ($code === '') {
                $skippedEmpty++;
                continue;
            }

            $node = $nodeCache[$code] ?? FunctionalLocation::where('code', $code)->first();

            if ($node === null) {
                $missingNode++;
                continue;
            }

            if (! $dryRun) {
                $asset->update(['funcloc_id' => $node->id]);
            }

            $linked++;
        }

        return [$linked, $skippedEmpty, $missingNode];
    }

    /**
     * Pecah kode FuncLoc (dipisah tanda strip) menjadi array segment,
     * membuang segment kosong akibat spasi berlebih.
     *
     * @param  string  $code
     * @return array<int, string>
     */
    private function splitSegments(string $code): array
    {
        return array_values(array_filter(
            array_map('trim', explode('-', $code)),
            fn ($segment) => $segment !== ''
        ));
    }

    /**
     * Ubah Collection [code => count] menjadi array pasangan siap pakai
     * untuk response JSON, terurut berdasarkan code.
     *
     * @param  Collection<string, int>  $collection
     * @return array<int, array{code: string, count: int}>
     */
    private function collectionToPairs(Collection $collection): array
    {
        return $collection
            ->map(fn ($count, $code) => ['code' => $code, 'count' => $count])
            ->values()
            ->sortBy('code')
            ->values()
            ->all();
    }

    /**
     * Struktur hasil analyze() saat tidak ada data assets.functional_loc
     * sama sekali.
     *
     * @return array
     */
    private function emptyAnalysis(): array
    {
        return [
            'nodes'                => [],
            'new_node_count'       => 0,
            'existing_node_count'  => 0,
            'assets_to_link'       => [],
            'assets_to_link_count' => 0,
            'invalid_codes'        => [],
            'invalid_code_count'   => 0,
            'total_scanned'        => 0,
        ];
    }
}
