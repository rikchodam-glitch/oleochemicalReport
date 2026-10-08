<?php

namespace App\Http\Controllers\Traits;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Services\AssetLocationService;
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
 *   - anakLokasi()        : Node anak FuncLoc + alat di bawahnya (pemilih lokasi bertingkat)
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
     * Anak satu node Functional Location beserta alat di bawahnya, dipakai
     * pemilih lokasi bertingkat (PT -> Departemen -> Area -> Section -> Alat)
     * pada mode edit halaman detail laporan.
     *
     * Mengembalikan tiga hal sekaligus supaya satu langkah pilih hanya perlu
     * satu permintaan:
     *   - `induk`       : ringkasan node induk yang sedang dibuka (null = daftar PT)
     *   - `nodes`       : node anak langsung yang masih aktif
     *   - `assets`      : alat pada node induk, maksimal BATAS_CARI_LOKASI baris
     *                     (termasuk equipment_type untuk pengelompokan di layar)
     *   - `jumlah_alat` : jumlah SELURUH alat node itu (supaya pemanggil tahu
     *                     daftarnya dipotong; satu section di data nyata bisa
     *                     memuat 200+ alat)
     *
     * Area node induk ikut dikirim supaya pemanggil mengisi kolom `area_id`
     * laporan dari satu sumber aturan (AssetLocationService::areaId()), bukan
     * menebak dari kode lokasi.
     *
     * @param  Request  $request  `parent_id` opsional (kosong = daftar PT), `q` opsional
     * @return \Illuminate\Http\JsonResponse
     */
    public function anakLokasi(Request $request)
    {
        $indukId = (int) $request->get('parent_id', 0);
        $kunci   = trim((string) $request->get('q', ''));

        // Tanpa parent_id: tingkat teratas berisi PT (level 0).
        if ($indukId <= 0) {
            $nodes = FunctionalLocation::active()
                ->whereNull('parent_id')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'level'])
                ->map(fn (FunctionalLocation $node) => $this->ringkasNodeLokasi($node))
                ->values();

            return response()->json(['induk' => null, 'nodes' => $nodes, 'assets' => [], 'jumlah_alat' => 0]);
        }

        $induk = FunctionalLocation::active()->find($indukId);

        if ($induk === null) {
            return response()->json(['induk' => null, 'nodes' => [], 'assets' => [], 'jumlah_alat' => 0]);
        }

        $assetQuery = Asset::where('funcloc_id', $induk->id);

        if ($kunci !== '') {
            $assetQuery->where(function ($sub) use ($kunci) {
                $this->kriteriaCariAlat($sub, $kunci);
            });
        }

        // Jumlah seluruh alat pada node ini dikirim terpisah supaya pemanggil
        // bisa memberi tahu bahwa daftarnya dipotong BATAS_CARI_LOKASI baris
        // (satu section di data nyata bisa memuat 200+ alat).
        $jumlahAlat = (clone $assetQuery)->count();

        $assets = $assetQuery->orderBy('tech_ident_no')
            ->limit(self::BATAS_CARI_LOKASI)
            ->get(['id', 'equipment_no', 'tech_ident_no', 'description', 'equipment_type', 'funcloc_id', 'area_id'])
            ->map(fn (Asset $aset) => [
                'id'             => (int) $aset->id,
                'equipment_no'   => $aset->equipment_no,
                'tech_ident_no'  => $aset->tech_ident_no,
                'description'    => $aset->description,
                'equipment_type' => $aset->equipment_type,
                'funcloc_id'     => $aset->funcloc_id,
                'area_id'        => $aset->area_id,
                'area_label'     => (string) $induk->code,
            ])
            ->values();

        $nodes = $induk->children()
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level'])
            ->map(fn (FunctionalLocation $node) => $this->ringkasNodeLokasi($node))
            ->values();

        $ringkasInduk            = $this->ringkasNodeLokasi($induk);
        $ringkasInduk['area_id'] = app(AssetLocationService::class)->areaId($induk);

        return response()->json([
            'induk'       => $ringkasInduk,
            'nodes'       => $nodes,
            'assets'      => $assets,
            'jumlah_alat' => $jumlahAlat,
        ]);
    }

    /**
     * Ringkasan satu node Functional Location untuk pemilih lokasi bertingkat.
     *
     * @param  FunctionalLocation  $node  Node yang diringkas
     * @return array<string, mixed>      Kunci: id, code, name, level, label
     */
    private function ringkasNodeLokasi(FunctionalLocation $node): array
    {
        return [
            'id'    => (int) $node->id,
            'code'  => (string) $node->code,
            'name'  => (string) $node->name,
            'level' => (int) $node->level,
            'label' => trim($node->code . ' - ' . $node->name, ' -'),
        ];
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
