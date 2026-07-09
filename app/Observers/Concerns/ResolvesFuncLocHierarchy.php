<?php

namespace App\Observers\Concerns;

use App\Models\Area;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;

trait ResolvesFuncLocHierarchy
{
    /**
     * Ambil node FuncLoc L0 (site) milik Company tertentu. Buat node baru
     * jika belum ada, dengan kode dari Company::code.
     *
     * @param  Company  $company
     * @return FunctionalLocation
     */
    protected function findOrCreateSiteNode(Company $company): FunctionalLocation
    {
        $siteNode = FunctionalLocation::where('company_id', $company->id)
            ->ofLevel(FunctionalLocation::LEVEL_SITE)
            ->first();

        if ($siteNode !== null) {
            return $siteNode;
        }

        $code = FunctionalLocation::buildCode(null, $company->code);

        $siteNode = FunctionalLocation::firstOrCreate(
            ['code' => $code],
            [
                'segment'    => strtoupper(trim($company->code)),
                'name'       => $company->name,
                'level'      => FunctionalLocation::LEVEL_SITE,
                'parent_id'  => null,
                'company_id' => $company->id,
                'is_active'  => true,
            ]
        );

        if ($siteNode->company_id === null) {
            $siteNode->company_id = $company->id;
            $siteNode->saveQuietly();
        }

        return $siteNode;
    }

    /**
     * Ambil node FuncLoc L1 (departemen) milik Department tertentu. Buat node
     * ini beserta node L0 di atasnya jika belum ada.
     *
     * @param  Department  $department
     * @return FunctionalLocation
     */
    protected function findOrCreateDepartmentNode(Department $department): FunctionalLocation
    {
        $deptNode = FunctionalLocation::where('department_id', $department->id)
            ->ofLevel(FunctionalLocation::LEVEL_DEPARTMENT)
            ->first();

        if ($deptNode !== null) {
            return $deptNode;
        }

        $siteNode = $this->findOrCreateSiteNode($department->company);
        $code     = FunctionalLocation::buildCode($siteNode, $department->code);

        $deptNode = FunctionalLocation::firstOrCreate(
            ['code' => $code],
            [
                'segment'       => strtoupper(trim($department->code)),
                'name'          => $department->name,
                'level'         => FunctionalLocation::LEVEL_DEPARTMENT,
                'parent_id'     => $siteNode->id,
                'company_id'    => $siteNode->company_id,
                'department_id' => $department->id,
                'is_active'     => true,
            ]
        );

        if ($deptNode->department_id === null) {
            $deptNode->department_id = $department->id;
            $deptNode->saveQuietly();
        }

        return $deptNode;
    }

    /**
     * Ambil node FuncLoc L2 (area) milik Area tertentu. Buat node ini beserta
     * node L1/L0 di atasnya jika belum ada. Jika Area sudah punya funcloc_id,
     * langsung kembalikan node yang sudah terhubung tanpa query tambahan.
     *
     * @param  Area  $area
     * @return FunctionalLocation
     */
    protected function findOrCreateAreaNode(Area $area): FunctionalLocation
    {
        if ($area->funcloc_id !== null) {
            return $area->functionalLocation;
        }

        $deptNode = $this->findOrCreateDepartmentNode($area->department);
        $code     = FunctionalLocation::buildCode($deptNode, $area->code);

        $areaNode = FunctionalLocation::firstOrCreate(
            ['code' => $code],
            [
                'segment'       => strtoupper(trim($area->code)),
                'name'          => $area->name,
                'level'         => FunctionalLocation::LEVEL_AREA,
                'parent_id'     => $deptNode->id,
                'company_id'    => $deptNode->company_id,
                'department_id' => $deptNode->department_id,
                'area_id'       => $area->id,
                'is_active'     => true,
            ]
        );

        if ($areaNode->area_id === null) {
            $areaNode->area_id = $area->id;
            $areaNode->saveQuietly();
        }

        if ($area->funcloc_id === null) {
            $area->funcloc_id = $areaNode->id;
            $area->saveQuietly();
        }

        return $areaNode;
    }
}
