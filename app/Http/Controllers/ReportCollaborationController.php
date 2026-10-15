<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Technician;
use App\Services\ReportCopyService;
use Illuminate\Http\Request;

/**
 * Kolaborasi laporan dari halaman edit web.
 *
 * Menambah/menghapus teknisi kolaborator pada sebuah laporan INDUK. Setiap
 * kolaborator disimpan sebagai baris salinan laporan tersendiri (kolom
 * collaborator_of menunjuk laporan induk) — sama seperti yang dilakukan wizard
 * Telegram — sehingga pekerjaan itu ikut terhitung pada man hour teknisi
 * kolaborator dan muncul di riwayat pekerjaannya.
 *
 * Batas jumlah kolaborator sudah DIHAPUS (permintaan user 15/10/2026). Yang
 * tetap dijaga hanya: teknisi yang sama tidak tercatat dua kali, dan pelaksana
 * laporan sendiri tidak bisa ditambahkan sebagai kolaborator.
 */
class ReportCollaborationController extends Controller
{
    /**
     * @param  ReportCopyService  $salinan  Pembuat baris salinan laporan
     */
    public function __construct(protected ReportCopyService $salinan)
    {
    }

    /**
     * Tambah satu teknisi sebagai kolaborator laporan induk.
     *
     * @param  Request  $request  Berisi technician_id
     * @param  Report   $report   Laporan induk
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Report $report)
    {
        $validated = $request->validate([
            'technician_id' => 'required|integer|exists:technicians,id',
        ]);

        if (($galat = $this->penjagaInduk($report)) !== null) {
            return $this->tolak($request, $galat);
        }

        if (($galat = $this->penjagaStaf($report)) !== null) {
            return $this->tolak($request, $galat);
        }

        $teknisi   = Technician::find($validated['technician_id']);
        $kolaborator = $report->collaboratorReports()->get();

        if ((int) $teknisi->id === (int) $report->technician_id) {
            return $this->tolak($request, 'Teknisi itu adalah pelaksana laporan ini, tidak perlu ditambahkan sebagai kolaborator.');
        }

        if ($kolaborator->contains('technician_id', $teknisi->id)) {
            return $this->tolak($request, 'Teknisi itu sudah tercatat sebagai kolaborator laporan ini.');
        }

        $this->salinan->buatSalinanKolaborasi($report, $teknisi);

        return $this->balas(
            $request,
            $report,
            'Kolaborator ' . $teknisi->name . ' ditambahkan; baris laporannya dibuat otomatis.'
        );
    }

    /**
     * Hapus satu kolaborator beserta baris salinan laporannya.
     *
     * @param  Request  $request      Permintaan
     * @param  Report   $report       Laporan induk
     * @param  Report   $kolaborator  Baris salinan kolaborasi yang dihapus
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, Report $report, Report $kolaborator)
    {
        if ((int) $kolaborator->collaborator_of !== (int) $report->id) {
            return $this->tolak($request, 'Baris itu bukan salinan kolaborasi laporan ini.');
        }

        if (($galat = $this->penjagaStaf($report)) !== null) {
            return $this->tolak($request, $galat);
        }

        $nama = $kolaborator->technician?->name ?? ('#' . $kolaborator->id);

        $kolaborator->delete();

        return $this->balas($request, $report, 'Kolaborator ' . $nama . ' dihapus dari laporan ini.');
    }

    /**
     * Laporan yang boleh diubah daftar kolaboratornya adalah laporan INDUK:
     * bukan salinan kolaborasi (collaborator_of) dan bukan salinan alat
     * tambahan (multi_of). Laporan salinan selalu dikelola dari induknya supaya
     * satu pekerjaan tidak bercabang menjadi banyak daftar kolaborator.
     *
     * @param  Report  $report  Laporan yang sedang dibuka
     * @return string|null      Pesan galat, null bila laporan boleh diubah
     */
    private function penjagaInduk(Report $report): ?string
    {
        if ($report->collaborator_of !== null) {
            return 'Ini laporan salinan kolaborasi. Kelola kolaborator dari laporan induknya.';
        }

        if ($report->multi_of !== null) {
            return 'Ini baris salinan alat tambahan. Kelola kolaborator dari laporan induknya.';
        }

        return null;
    }

    /**
     * Batas pengelolaan group STAFF: Reguler Staff / Staff Grub A-D hanya boleh
     * mengubah daftar kolaborator laporan grub yang dinaunginya.
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
     * Balasan sukses: JSON untuk pemanggil fetch (halaman edit tidak perlu
     * dimuat ulang, isian form yang belum disimpan tidak hilang), redirect
     * untuk pemanggil form biasa.
     *
     * @param  Request  $request  Permintaan
     * @param  Report   $report   Laporan induk
     * @param  string   $pesan    Pesan sukses untuk pengguna
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function balas(Request $request, Report $report, string $pesan)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success'     => true,
                'message'     => $pesan,
                'kolaborator' => $this->daftarKolaborator($report),
            ]);
        }

        return back()->with('success', $pesan);
    }

    /**
     * Balasan gagal: 422 untuk pemanggil fetch, redirect dengan pesan galat
     * untuk pemanggil form biasa.
     *
     * @param  Request  $request  Permintaan
     * @param  string   $pesan    Pesan galat untuk pengguna
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
     * Daftar kolaborator laporan induk (dipakai tampilan awal halaman edit dan
     * pembaruan daftar tanpa reload).
     *
     * @param  Report  $report  Laporan induk
     * @return array<int, array{id: int, technician_id: int, name: string, code: string, url: string}>
     */
    private function daftarKolaborator(Report $report): array
    {
        return $report->collaboratorReports()
            ->with('technician:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Report $salinan) => [
                'id'            => $salinan->id,
                'technician_id' => $salinan->technician_id,
                'name'          => $salinan->technician?->name ?? ('Teknisi #' . $salinan->technician_id),
                'code'          => $salinan->report_code ?? ('#' . $salinan->id),
                'url'           => route('reports.show', $salinan),
            ])
            ->values()
            ->all();
    }
}
