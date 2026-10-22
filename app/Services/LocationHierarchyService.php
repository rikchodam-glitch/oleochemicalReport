<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FunctionalLocation;
use Illuminate\Support\Collection;

/**
 * Opsi dropdown lokasi yang SELALU diturunkan dari hierarki Functional
 * Location, bukan dari tabel master lama departments/areas/sub_areas.
 *
 * Nilai yang dikirim tetap id kolom lama (department_id/area_id/sub_area_id)
 * karena tabel assets/reports/technicians masih memakai kolom tersebut,
 * tetapi daftar dan urutannya mengikuti node FuncLoc yang benar.
 */
class LocationHierarchyService
{
    public function __construct(private readonly AssetLocationService $location)
    {
    }

    /**
     * Semua departemen yang ada di Functional Location level 1.
     *
     * @return Collection<int, object>
     */
    public function semuaDepartemen(): Collection
    {
        $hasil = [];

        FunctionalLocation::query()
            ->where('level', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'department_id', 'parent_id', 'company_id'])
            ->each(function (FunctionalLocation $node) use (&$hasil): void {
                $id = $this->location->departmentId($node);

                if ($id !== null && ! isset($hasil[$id])) {
                    $hasil[$id] = (object) [
                        'id'   => $id,
                        'code' => $node->code,
                        'name' => $node->name,
                    ];
                }
            });

        return collect(array_values($hasil));
    }

    /**
     * Departemen di bawah satu PT.
     *
     * @param  int $companyId ID tabel companies
     * @return Collection<int, object>
     */
    public function departemenUntukCompany(int $companyId): Collection
    {
        $company = Company::find($companyId);
        $root = $company === null ? null : FunctionalLocation::query()
            ->where('level', 0)
            ->where(function ($query) use ($company): void {
                $query->where('company_id', $company->id)
                    ->orWhere('code', $company->code);
            })
            ->first();

        if ($root === null) {
            return $this->semuaDepartemen()
                ->filter(fn (object $dept): bool => $this->location->companyId(
                    FunctionalLocation::where('level', 1)->where('code', $dept->code)->first()
                ) === $companyId)
                ->values();
        }

        $hasil = [];

        FunctionalLocation::query()
            ->where('level', 1)
            ->where('parent_id', $root->id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'department_id', 'parent_id', 'company_id'])
            ->each(function (FunctionalLocation $node) use (&$hasil): void {
                $id = $this->location->departmentId($node);

                if ($id !== null && ! isset($hasil[$id])) {
                    $hasil[$id] = (object) [
                        'id'   => $id,
                        'code' => $node->code,
                        'name' => $node->name,
                    ];
                }
            });

        return collect(array_values($hasil));
    }

    /**
     * Semua area yang ada di Functional Location level 2.
     *
     * @return Collection<int, object>
     */
    public function semuaArea(): Collection
    {
        $hasil = [];

        FunctionalLocation::query()
            ->where('level', 2)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'area_id'])
            ->each(function (FunctionalLocation $node) use (&$hasil): void {
                $id = $this->location->areaId($node);

                if ($id !== null && ! isset($hasil[$id])) {
                    $hasil[$id] = (object) [
                        'id'   => $id,
                        'code' => $node->code,
                        'name' => $node->name,
                    ];
                }
            });

        return collect(array_values($hasil));
    }

    /**
     * Area di bawah satu departemen.
     *
     * @param  int $departmentId ID tabel departments
     * @return Collection<int, object>
     */
    public function areaUntukDepartemen(int $departmentId): Collection
    {
        $parentIds = FunctionalLocation::query()
            ->where('level', 1)
            ->get(['id', 'code', 'department_id', 'parent_id', 'company_id'])
            ->filter(fn (FunctionalLocation $node): bool => $this->location->departmentId($node) === $departmentId)
            ->pluck('id')
            ->all();

        $hasil = [];

        FunctionalLocation::query()
            ->where('level', 2)
            ->whereIn('parent_id', $parentIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'area_id'])
            ->each(function (FunctionalLocation $node) use (&$hasil): void {
                $id = $this->location->areaId($node);

                if ($id !== null && ! isset($hasil[$id])) {
                    $hasil[$id] = (object) [
                        'id'   => $id,
                        'code' => $node->code,
                        'name' => $node->name,
                    ];
                }
            });

        return collect(array_values($hasil));
    }

    /**
     * Sub area di bawah satu area.
     *
     * @param  int $areaId ID tabel areas
     * @return Collection<int, object>
     */
    public function subAreaUntukArea(int $areaId): Collection
    {
        $parentIds = FunctionalLocation::query()
            ->where('level', 2)
            ->get(['id', 'code', 'area_id'])
            ->filter(fn (FunctionalLocation $node): bool => $this->location->areaId($node) === $areaId)
            ->pluck('id')
            ->all();

        $hasil = [];

        FunctionalLocation::query()
            ->where('level', 3)
            ->whereIn('parent_id', $parentIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'sub_area_id'])
            ->each(function (FunctionalLocation $node) use (&$hasil): void {
                $id = $this->location->subAreaId($node);

                if ($id !== null && ! isset($hasil[$id])) {
                    $hasil[$id] = (object) [
                        'id'   => $id,
                        'code' => $node->code,
                        'name' => $node->name,
                    ];
                }
            });

        return collect(array_values($hasil));
    }
}
