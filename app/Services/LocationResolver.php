<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;

/**
 * LocationResolver
 *
 * Satu sumber kebenaran untuk melengkapi lokasi sebuah laporan/pekerjaan:
 *   - funcloc_id diambil dari node FunctionalLocation (atau dari asetnya);
 *   - area_id diambil berurutan: node -> aset -> turunan segmen ke-3 kode
 *     FuncLoc (mis. EPE-PROD-BD02-6153 -> area BD02).
 *
 * Alasan dipisah ke service: aturan turunan area ini dipakai wizard Telegram
 * (saat mengunci alat/lokasi) DAN command perbaikan data laporan lama
 * (reports:backfill-location) — supaya tidak ada dua versi aturan yang bisa
 * berbeda hasil.
 */
class LocationResolver
{
    /**
     * Lengkapi kunci lokasi pada state wizard/array kerja.
     *
     * @param  array                    $state  State wizard saat ini
     * @param  FunctionalLocation|null  $node   Node lokasi yang dikunci (bila ada)
     * @param  Asset|null               $aset   Aset yang dikunci (bila ada)
     * @return array  State dengan funcloc_id/funcloc_code/funcloc_name/area_id/area_code
     */
    public function lengkapiState(array $state, ?FunctionalLocation $node, ?Asset $aset = null): array
    {
        if ($node !== null) {
            return $this->lengkapiDariNode($state, $node);
        }

        if ($aset !== null) {
            return $this->lengkapiDariAset($state, $aset);
        }

        return $state;
    }

    /**
     * Lengkapi state dari node FunctionalLocation.
     *
     * @param  array               $state  State wizard
     * @param  FunctionalLocation  $node   Node lokasi yang dikunci
     * @return array  State yang sudah dilengkapi
     */
    public function lengkapiDariNode(array $state, FunctionalLocation $node): array
    {
        $state['funcloc_id']   = $node->id;
        $state['funcloc_code'] = $node->code;
        $state['funcloc_name'] = $node->name;

        return $this->isiArea($state, $node->area_id, $node->code);
    }

    /**
     * Lengkapi state dari aset (dipakai jalur penguncian equipment).
     *
     * @param  array  $state  State wizard
     * @param  Asset  $aset   Aset yang dikunci
     * @return array  State yang sudah dilengkapi
     */
    public function lengkapiDariAset(array $state, Asset $aset): array
    {
        $node = $aset->funcloc_id !== null
            ? FunctionalLocation::find($aset->funcloc_id)
            : $this->nodeDariKode($aset->functional_loc);

        if ($node !== null) {
            $state['funcloc_id']   = $node->id;
            $state['funcloc_code'] = $node->code;
            $state['funcloc_name'] = $node->name;

            return $this->isiArea($state, $aset->area_id ?? $node->area_id, $node->code);
        }

        if ($aset->functional_loc !== null) {
            $state['funcloc_code'] = $aset->functional_loc;
        }

        return $this->isiArea($state, $aset->area_id, $aset->functional_loc);
    }

    /**
     * Isi area_id/area_code pada state bila area bisa ditentukan.
     *
     * @param  array        $state  State wizard
     * @param  int|null     $areaId Area yang sudah diketahui (node/aset)
     * @param  string|null  $kode   Kode FuncLoc untuk menurunkan area
     * @return array  State dengan area_id/area_code (bila ketemu)
     */
    public function isiArea(array $state, ?int $areaId, ?string $kode = null): array
    {
        $areaId = $areaId ?? $this->areaIdDariKode($kode);

        if ($areaId === null) {
            return $state;
        }

        $state['area_id']   = $areaId;
        $state['area_code'] = Area::where('id', $areaId)->value('code');

        return $state;
    }

    /**
     * Turunkan area_id dari segmen ke-3 kode FuncLoc.
     * Contoh: "EPE-PROD-BD02-6153" -> kode area "BD02".
     *
     * @param  string|null  $kode  Kode FunctionalLocation
     * @return int|null  ID Area, atau null bila tidak ditemukan
     */
    public function areaIdDariKode(?string $kode): ?int
    {
        $bagian   = explode('-', trim((string) $kode));
        $kodeArea = trim($bagian[2] ?? '');

        if ($kodeArea === '') {
            return null;
        }

        return Area::where('code', $kodeArea)->value('id');
    }

    /**
     * Cari node FunctionalLocation berdasarkan kode penuh.
     *
     * @param  string|null  $kode  Kode lokasi (mis. EPE-PROD-BD02-6153)
     * @return FunctionalLocation|null
     */
    public function nodeDariKode(?string $kode): ?FunctionalLocation
    {
        $kode = trim((string) $kode);

        if ($kode === '') {
            return null;
        }

        return FunctionalLocation::where('code', $kode)->first();
    }
}
