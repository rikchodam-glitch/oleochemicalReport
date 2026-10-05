<?php

namespace App\Http\Controllers\Traits;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use Illuminate\Http\Request;

/**
 * Trait ReportLocationTrait
 *
 * Berisi endpoint AJAX untuk lookup Functional Location dan Asset
 * berdasarkan Area, dipakai untuk mengisi dropdown dinamis di form
 * edit laporan (reports/edit) dan mode edit di halaman detail laporan.
 * Berbeda dari AssetLocationTrait yang melayani hierarki lokasi milik halaman
 * Asset Management — trait ini khusus untuk kebutuhan pemilihan lokasi/alat
 * pada satu laporan.
 *
 * Method yang ada:
 *   - getFuncLocsByArea() : Daftar Functional Location berdasarkan area_id
 *   - getAssetsByArea()   : Daftar Asset berdasarkan area_id, opsional difilter funcloc_id
 *   - cariAsset()         : Pencarian alat lintas area (kode alat/nomor equipment/deskripsi)
 *
 * Ketiganya menerima parameter `q` (kata kunci pencarian). Saat `q` diisi,
 * hasil dibatasi BATAS_CARI_LOKASI baris supaya satu area yang memuat ratusan
 * alat (di data nyata ada area dengan 756 alat) tetap cepat dibuka.
 */
trait ReportLocationTrait
{
    /**
     * Batas jumlah baris hasil pencarian lokasi/alat.
     */
    private const BATAS_CARI_LOKASI = 50;

    /**
     * Kembalikan daftar Functional Location yang termasuk dalam area tertentu.
     * Dipakai untuk mengisi dropdown Functional Location secara dinamis
     * di form edit laporan setelah Area dipilih.
     *
     * @param  Request  $request  Harus mengandung area_id; `q` opsional (pencarian kode/nama)
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFuncLocsByArea(Request $request)
    {
        $kunci = trim((string) $request->get('q', ''));

        $query = FunctionalLocation::where('area_id', $request->area_id);

        // Pencarian dipakai kotak "cari lokasi" pada form edit laporan.
        if ($kunci !== '') {
            $query->where(function ($sub) use ($kunci) {
                $sub->where('code', 'like', '%' . $kunci . '%')
                    ->orWhere('name', 'like', '%' . $kunci . '%');
            })->limit(self::BATAS_CARI_LOKASI);
        }

        $funcLocs = $query->orderBy('code')->get(['id', 'code', 'name', 'level']);

        return response()->json($funcLocs);
    }

    /**
     * Kembalikan daftar Asset yang termasuk dalam area tertentu.
     * Jika funcloc_id disertakan, hasil difilter lebih lanjut berdasarkan
     * Functional Location tersebut. Dipakai untuk mengisi dropdown Asset
     * secara dinamis di form edit laporan.
     *
     * @param  Request  $request  Harus mengandung area_id; opsional funcloc_id dan q
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAssetsByArea(Request $request)
    {
        $kunci = trim((string) $request->get('q', ''));

        $query = Asset::where('area_id', $request->area_id)
            ->when($request->filled('funcloc_id'), function ($query) use ($request) {
                $query->where('funcloc_id', $request->funcloc_id);
            });

        if ($kunci !== '') {
            $query->where(function ($sub) use ($kunci) {
                $this->kriteriaCariAlat($sub, $kunci);
            })->limit(self::BATAS_CARI_LOKASI);
        }

        $assets = $query->orderBy('tech_ident_no')
            ->get(['id', 'equipment_no', 'tech_ident_no', 'description', 'funcloc_id']);

        return response()->json($assets);
    }

    /**
     * Pencarian alat LINTAS AREA untuk kotak "Cari Alat (semua area)" pada form
     * edit laporan: admin cukup mengetik sebagian kode alat, nomor equipment,
     * atau deskripsinya tanpa harus tahu areanya lebih dulu.
     *
     * Hasil dibatasi BATAS_CARI_LOKASI baris dan hanya keluar bila kata kunci
     * minimal 2 huruf (menghindari memuat seluruh daftar alat karena satu huruf).
     *
     * @param  Request  $request  Wajib mengandung q (kata kunci pencarian)
     * @return \Illuminate\Http\JsonResponse
     */
    public function cariAsset(Request $request)
    {
        $kunci = trim((string) $request->get('q', ''));

        if (mb_strlen($kunci) < 2) {
            return response()->json([]);
        }

        $assets = Asset::with('area:id,code,name')
            ->where(function ($sub) use ($kunci) {
                $this->kriteriaCariAlat($sub, $kunci);
            })
            ->orderBy('tech_ident_no')
            ->limit(self::BATAS_CARI_LOKASI)
            ->get(['id', 'equipment_no', 'tech_ident_no', 'description', 'functional_loc', 'funcloc_id', 'area_id'])
            ->map(function (Asset $aset) {
                return [
                    'id'             => $aset->id,
                    'equipment_no'   => $aset->equipment_no,
                    'tech_ident_no'  => $aset->tech_ident_no,
                    'description'    => $aset->description,
                    'functional_loc' => $aset->functional_loc,
                    'funcloc_id'     => $aset->funcloc_id,
                    'area_id'        => $aset->area_id,
                    'area_label'     => $aset->area ? trim($aset->area->code . ' - ' . $aset->area->name) : null,
                ];
            })
            ->values();

        return response()->json($assets);
    }

    /**
     * Kriteria pencarian alat yang dipakai bersama oleh getAssetsByArea() dan
     * cariAsset() supaya dua kotak pencarian mencari dengan aturan yang sama:
     * kode alat (tech_ident_no), nomor equipment, deskripsi, dan kode lokasi.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Query yang sedang disusun
     * @param  string                                 $kunci  Kata kunci pencarian
     * @return void
     */
    private function kriteriaCariAlat($query, string $kunci): void
    {
        $pola = '%' . $kunci . '%';

        $query->where('tech_ident_no', 'like', $pola)
            ->orWhere('equipment_no', 'like', $pola)
            ->orWhere('description', 'like', $pola)
            ->orWhere('functional_loc', 'like', $pola);
    }
}
