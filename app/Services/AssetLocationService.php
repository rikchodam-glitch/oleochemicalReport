<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;

/**
 * AssetLocationService
 *
 * Satu sumber kebenaran untuk menurunkan kolom lokasi baris tabel `assets`
 * (company_id, department_id, area_id, sub_area_id) dari node Functional
 * Location. Dipakai bersama oleh ItAssetSyncService (baris cermin perangkat
 * IT) dan PanelAssetSyncService (baris cermin ruang panel), supaya aturan
 * penurunan lokasi tidak pernah berbeda hasil antar modul.
 *
 * Aturan yang dipakai:
 *   - company_id: segmen pertama kode FuncLoc dicocokkan ke companies.code
 *     (mis. EPE -> id PT), lalu jatuh ke company_id node induknya.
 *   - department_id: department_id node (atau node induk), lalu cadangan
 *     mencocokkan segmen kedua kode FuncLoc ke departments.code pada PT yang
 *     sama.
 *   - area_id/sub_area_id: diambil dari node FuncLoc itu sendiri.
 */
class AssetLocationService
{
    /**
     * Tentukan id PT pemilik sebuah node FuncLoc.
     *
     * @param  FunctionalLocation|null  $funcloc
     * @return int|null
     */
    public function companyId(?FunctionalLocation $funcloc): ?int
    {
        if ($funcloc === null) {
            return null;
        }

        $rootSegment = explode('-', (string) $funcloc->code)[0] ?? '';

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
     * Tentukan id Department pemilik sebuah node FuncLoc.
     *
     * @param  FunctionalLocation|null  $funcloc
     * @return int|null
     */
    public function departmentId(?FunctionalLocation $funcloc): ?int
    {
        if ($funcloc === null) {
            return null;
        }

        $node = $funcloc;

        while ($node !== null) {
            if ($node->department_id !== null) {
                return (int) $node->department_id;
            }

            $node = $node->parent;
        }

        $segments = explode('-', (string) $funcloc->code);
        $code     = $segments[1] ?? '';

        if ($code === '') {
            return null;
        }

        $companyId = $this->companyId($funcloc);

        $query = Department::where('code', $code);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $departmentId = $query->value('id');

        return $departmentId !== null ? (int) $departmentId : null;
    }

    /**
     * Id Area yang tertulis pada node FuncLoc (boleh null).
     *
     * @param  FunctionalLocation|null  $funcloc
     * @return int|null
     */
    public function areaId(?FunctionalLocation $funcloc): ?int
    {
        return $funcloc?->area_id !== null ? (int) $funcloc->area_id : null;
    }

    /**
     * Id Sub Area yang tertulis pada node FuncLoc (boleh null).
     *
     * @param  FunctionalLocation|null  $funcloc
     * @return int|null
     */
    public function subAreaId(?FunctionalLocation $funcloc): ?int
    {
        return $funcloc?->sub_area_id !== null ? (int) $funcloc->sub_area_id : null;
    }

    /**
     * Susun seluruh kolom lokasi untuk baris `assets` dari satu node FuncLoc.
     *
     * @param  FunctionalLocation  $funcloc
     * @return array<string, int|null>
     */
    public function kolomLokasi(FunctionalLocation $funcloc): array
    {
        return [
            'company_id'    => $this->companyId($funcloc),
            'department_id' => $this->departmentId($funcloc),
            'area_id'       => $this->areaId($funcloc),
            'sub_area_id'   => $this->subAreaId($funcloc),
        ];
    }
}
