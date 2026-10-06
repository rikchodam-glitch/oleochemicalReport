<?php

namespace App\Services;

use App\Models\FunctionalLocation;

/**
 * FunclocOptionService
 *
 * Sumber tunggal daftar Functional Location untuk dropdown form, dikelompokkan
 * per PT (segmen pertama kode FuncLoc). Dipakai form perangkat IT Assets dan
 * form ruang panel supaya urutan serta isi pilihannya selalu sama.
 *
 * Node level PT sendiri tidak ditampilkan, karena PT bukan lokasi penempatan
 * alat (lihat FunctionalLocation::LEVEL_SITE).
 */
class FunclocOptionService
{
    /**
     * Susun daftar Functional Location per PT.
     *
     * @return array<string, array<int, array{id: int, label: string, plant: string, department: string, code: string, name: string}>>
     */
    public function grupPerPt(): array
    {
        $groups = [];

        foreach (FunctionalLocation::orderBy('code')->get() as $funcloc) {
            if ((int) $funcloc->level === FunctionalLocation::LEVEL_SITE) {
                continue;
            }

            $segmen = explode('-', (string) $funcloc->code);
            $pt     = $segmen[0] ?? 'LAINNYA';

            $groups[$pt][] = [
                'id'         => (int) $funcloc->id,
                'label'      => $funcloc->code . ' - ' . $funcloc->name,
                'plant'      => $pt,
                'department' => $segmen[1] ?? '',
                'code'       => (string) $funcloc->code,
                'name'       => (string) $funcloc->name,
            ];
        }

        return $groups;
    }
}
