<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Services\ReportCopyService;
use Illuminate\Http\Request;

/**
 * Alat/lokasi tambahan pada sebuah laporan (pekerjaan multi item) dari mode
 * edit halaman DETAIL laporan.
 *
 * Sama seperti jalur wizard Telegram: laporan INDUK menyimpan detail pekerjaan
 * dan foto, sedangkan setiap alat/lokasi tambahan mendapat baris salinan sendiri
 * (kolom multi_of menunjuk laporan induk) supaya riwayat alat maupun halaman
 * lokasi tetap menampilkan pekerjaan tersebut. Baris salinan ini TIDAK dihitung
 * pada man hour (lihat ManHourService) supaya satu pekerjaan tidak terhitung
 * berkali-kali.
 */
class ReportMultiItemController extends Controller
{
    /**
     * @param  ReportCopyService  $salinan  Pembuat baris salinan laporan
     */
    public function __construct(protected ReportCopyService $salinan)
    {
    }

    /**
     * Tambah satu alat atau lokasi sebagai item tambahan pekerjaan.
     *
     * @param  Request  $request  Berisi asset_id atau funcloc_id
     * @param  Report   $report   Laporan induk
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Report $report)
    {
        $validated = $request->validate([
            'asset_id'   => 'nullable|integer|exists:assets,id',
            'funcloc_id' => 'nullable|integer|exists:functional_locations,id',
        ]);

        if (($galat = $this->penjagaInduk($report)) !== null) {
            return $this->tolak($request, $galat);
        }

        if (($galat = $this->penjagaStaf($report)) !== null) {
            return $this->tolak($request, $galat);
        }

        $assetId     = $validated['asset_id'] ?? null;
        $funclocId   = $validated['funcloc_id'] ?? null;

        if ($assetId === null && $funclocId === null) {
            return $this->tolak($request, 'Pilih alat atau lokasi yang ingin ditambahkan.');
        }

        if ($this->sudahAda($report, $assetId, $funclocId)) {
            return $this->tolak($request, 'Alat/lokasi itu sudah tercatat pada pekerjaan ini.');
        }

        $aset = $assetId !== null ? Asset::find($assetId) : null;
        $node = $funclocId !== null ? FunctionalLocation::find($funclocId) : null;

        $this->salinan->buatSalinanMulti($report, $aset, $node);

        return $this->balas($request, $report, 'Alat/lokasi tambahan ditambahkan ke pekerjaan ini.');
    }

    /**
     * Hapus satu item tambahan beserta baris salinan laporannya.
     *
     * @param  Request  $request  Permintaan
     * @param  Report   $report   Laporan induk
     * @param  Report   $item     Baris salinan multi alat yang dihapus
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, Report $report, Report $item)
    {
        if ((int) $item->multi_of !== (int) $report->id) {
            return $this->tolak($request, 'Baris itu bukan alat tambahan laporan ini.');
        }

        if (($galat = $this->penjagaStaf($report)) !== null) {
            return $this->tolak($request, $galat);
        }

        $item->delete();

        return $this->balas($request, $report, 'Alat/lokasi tambahan dihapus dari pekerjaan ini.');
    }

    /**
     * Laporan yang boleh diubah daftar alat tambahannya adalah laporan INDUK:
     * bukan salinan kolaborasi (collaborator_of) dan bukan salinan alat
     * tambahan (multi_of) — sama seperti aturan kolaborator.
     *
     * @param  Report  $report  Laporan yang sedang dibuka
     * @return string|null      Pesan galat, null bila laporan boleh diubah
     */
    private function penjagaInduk(Report $report): ?string
    {
        if ($report->collaborator_of !== null) {
            return 'Ini laporan salinan kolaborasi. Kelola alat tambahan dari laporan induknya.';
        }

        if ($report->multi_of !== null) {
            return 'Ini baris salinan alat tambahan. Kelola daftarnya dari laporan induknya.';
        }

        return null;
    }

    /**
     * Batas pengelolaan group STAFF: Reguler Staff / Staff Grub A-D hanya boleh
     * mengubah alat/lokasi tambahan laporan grub yang dinaunginya.
     *
     * @param  Report  $report  Laporan induk
     * @return string|null      Pesan galat, null bila boleh dikelola
     */
    private function penjagaStaf(Report $report): ?string
    {
        $user = auth()->user();

        if ($user !== null && $user->bolehKelolaLaporan($report)) {
            return null;
        }

        return (string) config(
            'akses.pesan_kelola_grub',
            'Akun Anda hanya bisa mengelola laporan grub yang Anda naungi.'
        );
    }

    /**
     * Apakah alat/lokasi itu sudah tercatat pada pekerjaan ini — baik sebagai
     * alat utama laporan induk maupun sebagai item tambahan sebelumnya.
     *
     * @param  Report    $report     Laporan induk
     * @param  int|null  $assetId    ID alat yang diminta
     * @param  int|null  $funclocId  ID node lokasi yang diminta
     * @return bool
     */
    private function sudahAda(Report $report, ?int $assetId, ?int $funclocId): bool
    {
        if ($assetId !== null && (int) $report->asset_id === $assetId) {
            return true;
        }

        if ($assetId === null && $funclocId !== null && (int) $report->funcloc_id === $funclocId) {
            return true;
        }

        foreach ($report->multiItems()->get() as $item) {
            if ($assetId !== null && (int) $item->asset_id === $assetId) {
                return true;
            }

            if ($assetId === null && $funclocId !== null && (int) $item->funcloc_id === $funclocId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Balasan sukses: JSON untuk pemanggil fetch, redirect untuk form biasa.
     *
     * @param  Request  $request  Permintaan
     * @param  Report   $report   Laporan induk
     * @param  string   $pesan    Pesan sukses
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function balas(Request $request, Report $report, string $pesan)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $pesan,
                'item'    => $this->daftarItem($report),
            ]);
        }

        return back()->with('success', $pesan);
    }

    /**
     * Balasan gagal: 422 untuk pemanggil fetch, redirect untuk form biasa.
     *
     * @param  Request  $request  Permintaan
     * @param  string   $pesan    Pesan galat
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function tolak(Request $request, string $pesan)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $pesan,
            ], 422);
        }

        return back()->with('error', $pesan);
    }

    /**
     * Daftar item tambahan laporan induk untuk tampilan awal dan pembaruan
     * daftar tanpa reload. Label memakai accessor Report::multi_item_label
     * supaya sama dengan yang dirender server.
     *
     * Tautan tiap baris menunjuk detail ALAT/LOKASI (bukan halaman baris
     * salinannya) supaya satu pekerjaan bersama tetap tampil sebagai satu
     * laporan; kode baris salinannya dikirim terpisah sebagai keterangan.
     *
     * @param  Report  $report  Laporan induk
     * @return array<int, array{id: int, label: string, code: string, kode_alat: string, grup: string, jenis: string, url: string}>
     */
    private function daftarItem(Report $report): array
    {
        return $report->multiItems()
            ->with(['asset', 'functionalLocation'])
            ->orderBy('id')
            ->get()
            ->map(fn (Report $item) => [
                'id'        => $item->id,
                'label'     => $item->multi_item_label,
                'code'      => $item->report_code ?? ('#' . $item->id),
                'kode_alat' => $item->asset?->tech_ident_no
                    ?? $item->functionalLocation?->code
                    ?? $item->multi_item_label,
                // Pengelompokan tampilan: satu baris per lokasi (Section).
                // Baris salinan alat menyimpan funcloc_id alatnya, jadi grup
                // alat dan grup lokasi memakai sumber yang sama.
                'grup'      => $item->functionalLocation?->code
                    ?? $item->asset?->functional_loc
                    ?? 'Tanpa lokasi',
                'jenis'     => $item->asset_id !== null ? 'alat' : 'lokasi',
                'url'       => $item->asset
                    ? route('assets.show', $item->asset)
                    : ($item->functionalLocation
                        ? route('locations.show', $item->functionalLocation)
                        : route('reports.show', $report)),
            ])
            ->values()
            ->all();
    }
}
