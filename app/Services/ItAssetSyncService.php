<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\ItAsset;
use Illuminate\Support\Collection;

/**
 * ItAssetSyncService
 *
 * Menyambungkan modul IT Assets dengan tabel assets (asset industri) supaya:
 *
 *   1. Lokasi perangkat IT memakai Functional Location yang sama
 *      (PT -> Departemen -> Area -> Section), bukan teks bebas.
 *   2. Pencarian equipment di wizard laporan Telegram (TechIdentSearchService)
 *      otomatis menemukan perangkat IT, karena pool pencariannya tabel assets.
 *   3. Halaman IT Assets tetap menjadi tempat pengelolaan perangkat IT
 *      (baris di tabel assets hanyalah cermin yang ditandai data_source
 *      'it_sync' dan menunjuk balik lewat kolom it_asset_id).
 *
 * Aturan pemetaan lokasi (plant + kata kunci department -> funcloc) dibaca dari
 * config/it.php bagian sync.funcloc_rules agar mudah direvisi tanpa mengubah
 * kode. Perangkat yang belum bisa dipetakan TIDAK dipaksa masuk: baris cerminnya
 * dihapus dari tabel assets (bila sebelumnya pernah tersinkron) dan perangkat
 * tetap dikelola di halaman IT Assets sampai lokasinya dilengkapi.
 */
class ItAssetSyncService
{
    /**
     * Isi kolom funcloc_id pada seluruh perangkat IT sesuai aturan config.
     *
     * @param  bool  $dryRun  true = hanya menghitung, tidak menyimpan
     * @return array{mapped: int, already: int, unmapped: int, unmapped_list: array<int, array<string, string>>}
     */
    public function mapAllFunclocs(bool $dryRun = false): array
    {
        $funclocByCode = FunctionalLocation::all()->keyBy('code');

        $mapped   = 0;
        $aligned  = 0;
        $already  = 0;
        $unmapped = [];

        foreach (ItAsset::orderBy('id')->get() as $asset) {
            $target = $this->resolveFuncloc($asset, $funclocByCode);

            if ($target === null) {
                $unmapped[] = [
                    'tag'        => (string) ($asset->tag_name ?? $asset->displayName()),
                    'device'     => $asset->deviceLabel(),
                    'plant'      => (string) ($asset->plant ?? '-'),
                    'department' => (string) ($asset->department ?? '-'),
                ];

                continue;
            }

            // Plant dan Department mengikuti funcloc (satu sumber kebenaran),
            // sehingga field di form selalu terisi konsisten.
            $segmen   = explode('-', (string) $target->code);
            $plantBaru = $segmen[0] ?? $asset->plant;
            $deptBaru  = $segmen[1] ?? $asset->department;

            $funclocSama = (int) $asset->funcloc_id === (int) $target->id;
            $fieldSama   = $asset->plant === $plantBaru && $asset->department === $deptBaru;

            if ($funclocSama && $fieldSama) {
                $already++;

                continue;
            }

            if (!$dryRun) {
                $asset->update([
                    'funcloc_id' => $target->id,
                    'plant'      => $plantBaru,
                    'department' => $deptBaru,
                ]);
            }

            if ($funclocSama) {
                $aligned++;
            } else {
                $mapped++;
            }
        }

        return [
            'mapped'        => $mapped,
            'aligned'       => $aligned,
            'already'       => $already,
            'unmapped'      => count($unmapped),
            'unmapped_list' => $unmapped,
        ];
    }

    /**
     * Sinkronkan seluruh perangkat IT yang sudah punya funcloc ke tabel assets:
     * buat/ubah baris cermin, adopsi baris lama bertag sama bila ada, dan hapus
     * baris cermin yang sumbernya sudah tidak punya lokasi lagi.
     *
     * @param  bool  $dryRun  true = hanya menghitung, tidak menyimpan
     * @return array{created: int, updated: int, adopted: int, skipped: int, deleted: int}
     */
    public function syncAll(bool $dryRun = false): array
    {
        $summary       = ['created' => 0, 'updated' => 0, 'adopted' => 0, 'skipped' => 0, 'deleted' => 0];
        $syncableIds   = [];
        $funclocByCode = null;

        foreach (ItAsset::with('functionalLocation')->orderBy('id')->get() as $asset) {
            $funcloc = $asset->functionalLocation;

            // Pada mode dry-run, funcloc_id sumber mungkin belum tersimpan
            // (mis. `it:sync-assets --dry-run` dijalankan sebelum pemetaan
            // disimpan), sehingga hasil pemetaan dihitung di memori agar
            // laporan dry-run tetap menggambarkan hasil sebenarnya.
            if ($funcloc === null && $dryRun) {
                $funclocByCode ??= FunctionalLocation::all()->keyBy('code');
                $funcloc         = $this->resolveFuncloc($asset, $funclocByCode);
            }

            if ($funcloc === null) {
                $summary['skipped']++;

                continue;
            }

            $syncableIds[] = $asset->id;
            $result        = $this->syncOne($asset, $dryRun, $funcloc);
            $summary[$result]++;
        }

        $summary['deleted'] = $this->removeStaleMirrors($syncableIds, $dryRun);

        return $summary;
    }

    /**
     * Sinkronkan satu perangkat IT. Mengembalikan jenis aksi yang dilakukan
     * supaya bisa direkap: created, updated, adopted, atau skipped.
     *
     * @param  ItAsset                   $asset
     * @param  bool                      $dryRun
     * @param  FunctionalLocation|null   $funcloc  Funcloc yang sudah diresolusi (opsional)
     * @return string
     */
    public function syncOne(ItAsset $asset, bool $dryRun = false, ?FunctionalLocation $funcloc = null): string
    {
        $funcloc ??= $asset->functionalLocation ?? $asset->load('functionalLocation')->functionalLocation;

        if ($funcloc === null) {
            return 'skipped';
        }

        $mirror  = Asset::where('it_asset_id', $asset->id)->first();
        $adopted = false;

        // Adopsi baris lama yang tag-nya sama (mis. data industri lama
        // CCTV-EPE-*) supaya laporan lama yang menunjuk baris tersebut tidak
        // kehilangan tautan ke equipment.
        if ($mirror === null && !empty($asset->tag_name)) {
            $mirror  = Asset::whereNull('it_asset_id')
                ->where('tech_ident_no', $asset->tag_name)
                ->first();
            $adopted = $mirror !== null;
        }

        $payload = $this->buildMirrorPayload($asset, $funcloc);

        if ($dryRun) {
            if ($mirror === null) {
                return 'created';
            }

            return $adopted ? 'adopted' : 'updated';
        }

        if ($mirror !== null) {
            // equipment_no milik baris lama dipertahankan (nomor SAP) agar data
            // yang sudah ada tidak hilang saat baris diadopsi.
            if ($adopted) {
                unset($payload['equipment_no']);
            }

            $mirror->update($payload);

            return $adopted ? 'adopted' : 'updated';
        }

        Asset::create($payload);

        return 'created';
    }

    /**
     * Hapus baris cermin yang sumbernya (perangkat IT) sekarang tidak punya
     * funcloc lagi atau sudah tidak ada, supaya tabel assets tidak menyimpan
     * perangkat IT tanpa lokasi.
     *
     * @param  array<int, int>  $syncableIds  ID perangkat IT yang masih punya funcloc
     * @param  bool             $dryRun
     * @return int  jumlah baris yang dihapus (atau akan dihapus bila dry run)
     */
    protected function removeStaleMirrors(array $syncableIds, bool $dryRun): int
    {
        $query = Asset::whereNotNull('it_asset_id');

        if ($syncableIds !== []) {
            $query->whereNotIn('it_asset_id', $syncableIds);
        }

        $stale = $query->get();

        if (!$dryRun) {
            foreach ($stale as $asset) {
                $asset->delete();
            }
        }

        return $stale->count();
    }

    /**
     * Susun data baris cermin di tabel assets untuk satu perangkat IT.
     *
     * @param  ItAsset             $asset
     * @param  FunctionalLocation  $funcloc
     * @return array<string, mixed>
     */
    protected function buildMirrorPayload(ItAsset $asset, FunctionalLocation $funcloc): array
    {
        return [
            'equipment_no'     => null,
            'description'      => $this->buildDescription($asset),
            'tech_ident_no'    => $asset->tag_name,
            'object_type'      => $asset->objectTypeCode(),
            'equipment_type'   => $asset->equipmentTypeLabel(),
            'functional_loc'   => $funcloc->code,
            'funcloc_id'       => $funcloc->id,
            'company_id'       => $this->resolveCompanyId($funcloc),
            'department_id'    => $this->resolveDepartmentId($funcloc),
            'area_id'          => $funcloc->area_id,
            'sub_area_id'      => $funcloc->sub_area_id,
            'status'           => $this->mapStatus($asset->status),
            'has_equipment_no' => false,
            'data_source'      => 'it_sync',
            'imported_at'      => $asset->imported_at ?? now(),
            'it_asset_id'      => $asset->id,
        ];
    }

    /**
     * Deskripsi baris cermin: nama perangkat, dilengkapi brand/model dan
     * lokasi fisik bila tersedia (dipakai di daftar kandidat wizard).
     *
     * @param  ItAsset  $asset
     * @return string
     */
    protected function buildDescription(ItAsset $asset): string
    {
        $nama = $asset->name;

        if (empty($nama)) {
            $nama = trim(implode(' ', array_filter([$asset->brand, $asset->model])));
        }

        if (empty($nama)) {
            $nama = $asset->deviceLabel();
        }

        $lokasi = trim((string) $asset->location);

        if ($lokasi !== '' && !str_contains($nama, $lokasi)) {
            $nama .= ' (' . $lokasi . ')';
        }

        return $nama;
    }

    /**
     * Status perangkat IT dipetakan apa adanya ke kolom status assets. Nilai
     * yang tidak dikenal (mis. data lama) dianggap active.
     *
     * @param  string|null  $status
     * @return string
     */
    protected function mapStatus(?string $status): string
    {
        return in_array($status, ['active', 'inactive', 'needs_review', 'spare'], true) ? $status : 'active';
    }

    /**
     * Tentukan funcloc tujuan untuk satu perangkat IT berdasarkan aturan
     * config (plant + kata kunci department), lalu aturan cadangan per tipe
     * perangkat bila departemen tidak diisi.
     *
     * @param  ItAsset                                  $asset
     * @param  Collection<string, FunctionalLocation>   $funclocByCode
     * @return FunctionalLocation|null
     */
    public function resolveFuncloc(ItAsset $asset, Collection $funclocByCode): ?FunctionalLocation
    {
        $plant      = strtoupper(trim((string) $asset->plant));
        $department = strtoupper(trim((string) $asset->department));

        foreach ((array) config('it.sync.funcloc_rules.' . $plant, []) as $rule) {
            foreach ((array) ($rule['keywords'] ?? []) as $keyword) {
                if ($keyword !== '' && !str_contains($department, strtoupper((string) $keyword))) {
                    continue;
                }

                $funcloc = $funclocByCode->get($rule['funcloc'] ?? '');

                if ($funcloc !== null) {
                    return $funcloc;
                }
            }
        }

        foreach ((array) config('it.sync.device_fallback_rules', []) as $rule) {
            $plants      = (array) ($rule['plants'] ?? []);
            $deviceTypes = (array) ($rule['device_types'] ?? []);

            if (!in_array($plant, $plants, true) || !in_array($asset->device_type, $deviceTypes, true)) {
                continue;
            }

            $funcloc = $funclocByCode->get($rule['funcloc'] ?? '');

            if ($funcloc !== null) {
                return $funcloc;
            }
        }

        return null;
    }

    /**
     * Tentukan company_id dari funcloc: segmen pertama kode dicocokkan ke
     * companies.code (mis. EPE -> #1), lalu jatuh ke company_id milik node
     * induk bila tidak ketemu.
     *
     * @param  FunctionalLocation  $funcloc
     * @return int|null
     */
    protected function resolveCompanyId(FunctionalLocation $funcloc): ?int
    {
        $rootSegment = explode('-', $funcloc->code)[0] ?? '';

        if ($rootSegment !== '') {
            $companyId = Company::where('code', $rootSegment)->value('id');

            if ($companyId !== null) {
                return (int) $companyId;
            }
        }

        $node = $funcloc;

        while ($node !== null) {
            if ($node->company_id !== null) {
                return (int) $node->company_id;
            }

            $node = $node->parent;
        }

        return null;
    }

    /**
     * Tentukan department_id dari funcloc: pakai department_id node itu sendiri
     * atau node induknya, lalu cadangan terakhir mencocokkan segmen kedua kode
     * funcloc (mis. HRGA) ke departments.code pada perusahaan yang sama.
     *
     * @param  FunctionalLocation  $funcloc
     * @return int|null
     */
    protected function resolveDepartmentId(FunctionalLocation $funcloc): ?int
    {
        $node = $funcloc;

        while ($node !== null) {
            if ($node->department_id !== null) {
                return (int) $node->department_id;
            }

            $node = $node->parent;
        }

        $segments = explode('-', $funcloc->code);
        $code     = $segments[1] ?? '';

        if ($code === '') {
            return null;
        }

        $companyId = $this->resolveCompanyId($funcloc);

        $query = Department::where('code', $code);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $departmentId = $query->value('id');

        return $departmentId !== null ? (int) $departmentId : null;
    }
}
