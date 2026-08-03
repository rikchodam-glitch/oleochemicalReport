<?php

namespace App\Observers;

use App\Models\Area;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\SubArea;

class FunctionalLocationObserver
{
    /**
     * Saat node FuncLoc baru dibuat dari halaman admin FuncLoc, buat otomatis
     * record master lama (Company/Department/Area/SubArea) yang berpadanan
     * jika belum ada, supaya keduanya tetap tersinkronisasi dua arah — mulai
     * dari level PT (L0) hingga Section (L3). Sinkronisasi Area/SubArea sudah
     * ada sebelumnya; PT dan Departemen ditambahkan agar rantai sinkron tidak
     * terputus ketika admin membuat hierarki dari level PT ke bawah.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    public function created(FunctionalLocation $funcLoc): void
    {
        if ($funcLoc->level === FunctionalLocation::LEVEL_SITE && $funcLoc->company_id === null) {
            $this->createCompanyFor($funcLoc);
            return;
        }

        if ($funcLoc->level === FunctionalLocation::LEVEL_DEPARTMENT && $funcLoc->department_id === null) {
            $this->createDepartmentFor($funcLoc);
            return;
        }

        if ($funcLoc->level === FunctionalLocation::LEVEL_AREA && $funcLoc->area_id === null) {
            $this->createAreaFor($funcLoc);
            return;
        }

        if ($funcLoc->level === FunctionalLocation::LEVEL_SECTION && $funcLoc->sub_area_id === null) {
            $this->createSubAreaFor($funcLoc);
        }
    }

    /**
     * Sinkronkan perubahan name ke Company/Department/Area/SubArea yang
     * terhubung. Kolom is_active tidak disinkronkan karena tabel master
     * lama tidak memiliki kolom tersebut.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    public function updated(FunctionalLocation $funcLoc): void
    {
        if (! $funcLoc->wasChanged('name')) {
            return;
        }

        if ($funcLoc->company_id !== null) {
            $funcLoc->company?->updateQuietly(['name' => $funcLoc->name]);
        }

        if ($funcLoc->department_id !== null) {
            $funcLoc->department?->updateQuietly(['name' => $funcLoc->name]);
        }

        if ($funcLoc->area_id !== null) {
            $funcLoc->area?->updateQuietly(['name' => $funcLoc->name]);
        }

        if ($funcLoc->sub_area_id !== null) {
            $funcLoc->subArea?->updateQuietly(['name' => $funcLoc->name]);
        }
    }

    /**
     * Buat record Company baru dari FuncLoc L0 (PT), atau pakai Company yang
     * sudah ada bila kode-nya (segment) sudah pernah terdaftar sebelumnya —
     * menghindari duplikasi saat admin membuat ulang node PT dengan kode yang
     * sama dengan Company lama.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    private function createCompanyFor(FunctionalLocation $funcLoc): void
    {
        $company = Company::firstOrCreate(
            ['code' => $funcLoc->segment],
            ['name' => $funcLoc->name]
        );

        $funcLoc->company_id = $company->id;
        $funcLoc->saveQuietly();
    }

    /**
     * Buat record Department baru dari FuncLoc L1, hanya jika parent (L0)
     * sudah terhubung ke Company yang valid. Memakai firstOrCreate agar
     * tidak melanggar constraint unique (company_id, code) bila kode yang
     * sama sudah pernah dibuat untuk Company tersebut.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    private function createDepartmentFor(FunctionalLocation $funcLoc): void
    {
        $companyId = $funcLoc->parent?->company_id;

        if ($companyId === null) {
            // Parent (L0) belum terhubung ke Company manapun sehingga
            // Department tidak bisa dibuat (company_id wajib diisi). Node
            // tetap sah sebagai FuncLoc murni tanpa representasi Department.
            return;
        }

        $department = Department::firstOrCreate(
            ['company_id' => $companyId, 'code' => $funcLoc->segment],
            ['name' => $funcLoc->name]
        );

        $funcLoc->department_id = $department->id;
        $funcLoc->saveQuietly();
    }

    /**
     * Buat record Area baru dari FuncLoc L2, hanya jika parent (L1) sudah
     * terhubung ke Department yang valid.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    private function createAreaFor(FunctionalLocation $funcLoc): void
    {
        $departmentId = $funcLoc->parent?->department_id;

        if ($departmentId === null) {
            // Parent (L1) belum terhubung ke Department manapun sehingga
            // Area tidak bisa dibuat (department_id wajib diisi). Node
            // tetap sah sebagai FuncLoc murni tanpa representasi Area.
            return;
        }

        $area = Area::create([
            'department_id' => $departmentId,
            'code'          => $funcLoc->segment,
            'name'          => $funcLoc->name,
            'funcloc_id'    => $funcLoc->id,
        ]);

        $funcLoc->area_id = $area->id;
        $funcLoc->saveQuietly();
    }

    /**
     * Buat record SubArea baru dari FuncLoc L3, hanya jika parent (L2) sudah
     * terhubung ke Area yang valid.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    private function createSubAreaFor(FunctionalLocation $funcLoc): void
    {
        $areaId = $funcLoc->parent?->area_id;

        if ($areaId === null) {
            // Parent (L2) belum terhubung ke Area manapun sehingga SubArea
            // tidak bisa dibuat (area_id wajib diisi).
            return;
        }

        $subArea = SubArea::create([
            'area_id'    => $areaId,
            'code'       => $funcLoc->segment,
            'name'       => $funcLoc->name,
            'funcloc_id' => $funcLoc->id,
        ]);

        $funcLoc->sub_area_id = $subArea->id;
        $funcLoc->saveQuietly();
    }
}
