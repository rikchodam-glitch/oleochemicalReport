<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\SubArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedFuncLocFromAssets extends Command
{
    protected $signature = 'funcloc:seed-from-assets
                            {--dry-run : Tampilkan rencana tanpa menyimpan ke database}';

    protected $description = 'Populasi tabel functional_locations dari data companies, departments, areas, sub_areas, dan assets yang sudah ada.';

    // Cache node yang sudah diproses untuk menghindari query berulang
    private array $cache = [];

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[DRY RUN] Semua query dijalankan di dalam transaksi yang akan di-rollback.');
        }

        $this->info('Memulai seeding FuncLoc dari data yang sudah ada...');
        $this->newLine();

        // Dry-run tetap menggunakan transaksi nyata agar seluruh logika berjalan benar,
        // namun di-rollback di akhir sehingga tidak ada perubahan yang tersimpan.
        DB::beginTransaction();

        try {
            $this->seedFromCompanies();
            $this->seedFromDepartments();
            $this->seedFromAreas();
            $this->seedFromSubAreas();
            $this->seedOrphanNodesFromAssets();
            $this->linkAssetsToFuncLoc($isDryRun);

            if ($isDryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('[DRY RUN] Rollback selesai — tidak ada perubahan tersimpan.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('Seeding selesai dan berhasil disimpan.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Terjadi kesalahan: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // =========================================================
    // TAHAP 1 — L0: Site/Company
    // =========================================================

    /**
     * Buat satu node L0 untuk setiap company yang ada.
     *
     * @return void
     */
    private function seedFromCompanies(): void
    {
        $this->info('Tahap 1/6: Membuat node L0 (Site/Company)...');

        $companies = Company::all();

        foreach ($companies as $company) {
            $code = strtoupper(trim($company->code));

            $this->firstOrCreateNode(
                code: $code,
                segment: $code,
                name: $company->name,
                level: FunctionalLocation::LEVEL_SITE,
                parentId: null,
                extraAttributes: ['company_id' => $company->id]
            );

            $this->line("  [L0] {$code} — {$company->name}");
        }

        $this->info("  Selesai: {$companies->count()} company diproses.");
    }

    // =========================================================
    // TAHAP 2 — L1: Department
    // =========================================================

    /**
     * Buat satu node L1 untuk setiap department.
     *
     * @return void
     */
    private function seedFromDepartments(): void
    {
        $this->info('Tahap 2/6: Membuat node L1 (Department)...');

        $departments = Department::with('company')->get();
        $count = 0;

        foreach ($departments as $dept) {
            $companyCode = strtoupper(trim($dept->company->code));
            $deptCode    = strtoupper(trim($dept->code));
            $fullCode    = "{$companyCode}-{$deptCode}";

            $parentNode = $this->cache[$companyCode] ?? null;

            $this->firstOrCreateNode(
                code: $fullCode,
                segment: $deptCode,
                name: $dept->name,
                level: FunctionalLocation::LEVEL_DEPARTMENT,
                parentId: $parentNode?->id,
                extraAttributes: [
                    'company_id'    => $dept->company_id,
                    'department_id' => $dept->id,
                ]
            );

            $this->line("  [L1] {$fullCode} — {$dept->name}");
            $count++;
        }

        $this->info("  Selesai: {$count} department diproses.");
    }

    // =========================================================
    // TAHAP 3 — L2: Area
    // =========================================================

    /**
     * Buat node L2 untuk setiap area dan set area.funcloc_id.
     *
     * @return void
     */
    private function seedFromAreas(): void
    {
        $this->info('Tahap 3/6: Membuat node L2 (Area)...');

        $areas = Area::with('department.company')->get();
        $count = 0;

        foreach ($areas as $area) {
            $dept        = $area->department;
            $company     = $dept->company;
            $companyCode = strtoupper(trim($company->code));
            $deptCode    = strtoupper(trim($dept->code));
            $areaCode    = strtoupper(trim($area->code));
            $fullCode    = "{$companyCode}-{$deptCode}-{$areaCode}";
            $parentCode  = "{$companyCode}-{$deptCode}";

            $parentNode = $this->cache[$parentCode] ?? null;

            $node = $this->firstOrCreateNode(
                code: $fullCode,
                segment: $areaCode,
                name: $area->name,
                level: FunctionalLocation::LEVEL_AREA,
                parentId: $parentNode?->id,
                extraAttributes: [
                    'company_id'    => $company->id,
                    'department_id' => $dept->id,
                    'area_id'       => $area->id,
                ]
            );

            // Set area.funcloc_id jika belum terhubung
            if ($node !== null && $area->funcloc_id === null) {
                $area->update(['funcloc_id' => $node->id]);
            }

            $this->line("  [L2] {$fullCode} — {$area->name}");
            $count++;
        }

        $this->info("  Selesai: {$count} area diproses.");
    }

    // =========================================================
    // TAHAP 4 — L3: SubArea
    // =========================================================

    /**
     * Buat node L3 untuk setiap sub_area dan set sub_area.funcloc_id.
     *
     * @return void
     */
    private function seedFromSubAreas(): void
    {
        $this->info('Tahap 4/6: Membuat node L3 (SubArea/Section)...');

        $subAreas = SubArea::with('area.department.company')->get();
        $count    = 0;

        foreach ($subAreas as $subArea) {
            $area        = $subArea->area;
            $dept        = $area->department;
            $company     = $dept->company;
            $companyCode = strtoupper(trim($company->code));
            $deptCode    = strtoupper(trim($dept->code));
            $areaCode    = strtoupper(trim($area->code));
            $subCode     = strtoupper(trim($subArea->code));
            $fullCode    = "{$companyCode}-{$deptCode}-{$areaCode}-{$subCode}";
            $parentCode  = "{$companyCode}-{$deptCode}-{$areaCode}";

            $parentNode = $this->cache[$parentCode] ?? null;

            $node = $this->firstOrCreateNode(
                code: $fullCode,
                segment: $subCode,
                name: $subArea->name,
                level: FunctionalLocation::LEVEL_SECTION,
                parentId: $parentNode?->id,
                extraAttributes: [
                    'company_id'    => $company->id,
                    'department_id' => $dept->id,
                    'area_id'       => $area->id,
                    'sub_area_id'   => $subArea->id,
                ]
            );

            if ($node !== null && $subArea->funcloc_id === null) {
                $subArea->update(['funcloc_id' => $node->id]);
            }

            $this->line("  [L3] {$fullCode} — {$subArea->name}");
            $count++;
        }

        $this->info("  Selesai: {$count} sub_area diproses.");
    }

    // =========================================================
    // TAHAP 5 — Orphan nodes dari kolom assets.functional_loc
    // =========================================================

    /**
     * Scan kolom functional_loc di assets untuk menemukan node yang belum ada
     * di tabel functional_locations — mis. EPE-PROD-BD02 yang tidak punya Area record.
     * Buat node induk yang hilang secara rekursif.
     *
     * @return void
     */
    private function seedOrphanNodesFromAssets(): void
    {
        $this->info('Tahap 5/6: Membuat orphan nodes dari assets.functional_loc...');

        $funcLocs = Asset::whereNotNull('functional_loc')
            ->distinct()
            ->pluck('functional_loc');

        $created = 0;
        $skipped = 0;

        foreach ($funcLocs as $rawCode) {
            $code = strtoupper(trim($rawCode));

            if (empty($code)) {
                continue;
            }

            // Jika sudah ada di cache berarti sudah dibuat di tahap sebelumnya
            if (isset($this->cache[$code])) {
                $skipped++;
                continue;
            }

            $node = $this->ensureNodeExists($code);

            if ($node !== null) {
                $created++;
            }
        }

        $this->info("  Selesai: {$created} node baru dibuat, {$skipped} sudah ada.");
    }

    // =========================================================
    // TAHAP 6 — Link assets.funcloc_id
    // =========================================================

    /**
     * Update assets.funcloc_id berdasarkan assets.functional_loc (string).
     * Pada dry-run, hanya tampilkan ringkasan tanpa update.
     *
     * @param  bool  $isDryRun
     * @return void
     */
    private function linkAssetsToFuncLoc(bool $isDryRun): void
    {
        $this->info('Tahap 6/6: Menghubungkan assets.funcloc_id dari string functional_loc...');

        $assets = Asset::whereNotNull('functional_loc')
            ->whereNull('funcloc_id')
            ->get();

        $linked  = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($assets as $asset) {
            $code = strtoupper(trim($asset->functional_loc));

            // Lewati asset yang functional_loc-nya kosong atau hanya whitespace
            if ($code === '') {
                $this->warn("  [DILEWATI] functional_loc kosong — asset ID {$asset->id}");
                $skipped++;
                continue;
            }

            $node = $this->cache[$code] ?? FunctionalLocation::where('code', $code)->first();

            if ($node === null) {
                $this->warn("  [TIDAK DITEMUKAN] {$code} — asset ID {$asset->id}");
                $missing++;
                continue;
            }

            // Pada dry-run transaksi tetap dijalankan tapi akan di-rollback,
            // sehingga update ini aman untuk dieksekusi sebagai preview
            if (!$isDryRun) {
                $asset->update(['funcloc_id' => $node->id]);
            }

            $linked++;
        }

        $label = $isDryRun ? 'akan dihubungkan' : 'dihubungkan';
        $this->info("  Selesai: {$linked} asset {$label}, {$skipped} dilewati (kosong), {$missing} kode tidak ditemukan.");
    }

    // =========================================================
    // HELPER PRIVAT
    // =========================================================

    /**
     * Ambil node dari cache atau buat jika belum ada.
     * Selalu menyimpan ke database (dalam transaksi aktif).
     * Rollback di handle() akan membatalkan jika ini adalah dry-run.
     *
     * @param  string    $code
     * @param  string    $segment
     * @param  string    $name
     * @param  int       $level
     * @param  int|null  $parentId
     * @param  array     $extraAttributes
     * @return FunctionalLocation
     */
    private function firstOrCreateNode(
        string $code,
        string $segment,
        string $name,
        int $level,
        ?int $parentId,
        array $extraAttributes
    ): FunctionalLocation {
        if (isset($this->cache[$code])) {
            return $this->cache[$code];
        }

        $node = FunctionalLocation::firstOrCreate(
            ['code' => $code],
            array_merge([
                'segment'   => $segment,
                'name'      => $name,
                'level'     => $level,
                'parent_id' => $parentId,
                'is_active' => true,
            ], $extraAttributes)
        );

        $this->cache[$code] = $node;

        return $node;
    }

    /**
     * Pastikan node dengan kode tertentu sudah ada.
     * Jika belum, buat node-nya beserta semua node induk yang hilang secara rekursif.
     * Nama node yang dibuat dari sini diisi dengan kode itu sendiri karena tidak ada
     * nama yang bisa diambil dari data lama — admin bisa mengupdate via halaman FuncLoc.
     *
     * @param  string  $code
     * @return FunctionalLocation|null
     */
    private function ensureNodeExists(string $code): ?FunctionalLocation
    {
        if (isset($this->cache[$code])) {
            return $this->cache[$code];
        }

        // Pecah kode untuk menentukan level dan segment
        $segments = explode('-', $code);
        $level    = count($segments) - 1;

        if ($level < FunctionalLocation::LEVEL_SITE || $level > FunctionalLocation::LEVEL_SECTION) {
            $this->warn("  [LEVEL TIDAK VALID] {$code} (segmen: " . count($segments) . ")");
            return null;
        }

        // Pastikan induk sudah ada sebelum membuat node ini
        $parentNode = null;

        if ($level > FunctionalLocation::LEVEL_SITE) {
            $parentCode = implode('-', array_slice($segments, 0, -1));
            $parentNode = $this->ensureNodeExists($parentCode);
        }

        $segment = end($segments);

        $node = $this->firstOrCreateNode(
            code: $code,
            segment: $segment,
            name: $code,
            level: $level,
            parentId: $parentNode?->id,
            extraAttributes: []
        );

        $this->line("  [ORPHAN L{$level}] {$code}");

        return $node;
    }
}
