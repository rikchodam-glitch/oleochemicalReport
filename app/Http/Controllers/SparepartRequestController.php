<?php

namespace App\Http\Controllers;

use App\Models\SpRequest;
use App\Services\Sparepart\SparepartRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman Request Spare Part.
 *
 * Tab Pengajuan dipakai Staff CM/PM untuk menyetujui/menolak dan mengisi PR.
 * Setelah PR diisi, request pindah ke Kejar PO; setelah PO diisi pindah ke
 * Kejar Delivery; tombol Barang diterima menutup request.
 */
class SparepartRequestController extends Controller
{
    private const BARIS_PER_HALAMAN = 25;

    /**
     * Tab yang dikenal halaman request.
     */
    private const TAB = [
        'pengajuan',
        'kejar-po',
        'kejar-delivery',
        'selesai',
    ];

    /**
     * Status yang tampil pada tab Pengajuan.
     */
    private const STATUS_PENGAJUAN = [
        'menunggu',
        'disetujui',
        'ditolak',
        'semua',
    ];

    public function __construct(private readonly SparepartRequestService $layanan)
    {
    }

    /**
     * Tampilkan daftar request sesuai tab dan saringan.
     *
     * @param  Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        $request->validate([
            'dari'   => 'nullable|date',
            'sampai' => 'nullable|date',
        ]);

        $tab             = (string) $request->query('tab', 'pengajuan');
        $statusPengajuan = (string) $request->query('status', SpRequest::STATUS_MENUNGGU);
        $cari            = trim((string) $request->query('q', ''));
        $dari            = (string) $request->query('dari', '');
        $sampai          = (string) $request->query('sampai', '');

        if (! in_array($tab, self::TAB, true)) {
            $tab = 'pengajuan';
        }

        if (! in_array($statusPengajuan, self::STATUS_PENGAJUAN, true)) {
            $statusPengajuan = SpRequest::STATUS_MENUNGGU;
        }

        $dasar = SpRequest::query()
            ->with([
                'technician:id,name,nik,group,section',
                'approver:id,name',
                'rejecter:id,name',
                'prBy:id,name',
                'poBy:id,name',
                'receiver:id,name',
            ])
            ->cari($cari);

        if ($dari !== '') {
            $dasar->whereDate('created_at', '>=', $dari);
        }

        if ($sampai !== '') {
            $dasar->whereDate('created_at', '<=', $sampai);
        }

        $daftar = (clone $dasar)->status($this->statusTab($tab, $statusPengajuan))
            ->orderByDesc('created_at')
            ->paginate(self::BARIS_PER_HALAMAN)
            ->withQueryString();

        $jumlahStatus = SpRequest::query()
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status')
            ->all();

        $jumlahTab = [
            'pengajuan'      => (int) ($jumlahStatus[SpRequest::STATUS_MENUNGGU] ?? 0)
                + (int) ($jumlahStatus[SpRequest::STATUS_DISETUJUI] ?? 0)
                + (int) ($jumlahStatus[SpRequest::STATUS_DITOLAK] ?? 0),
            'kejar-po'       => (int) ($jumlahStatus[SpRequest::STATUS_KEJAR_PO] ?? 0),
            'kejar-delivery' => (int) ($jumlahStatus[SpRequest::STATUS_KEJAR_DELIVERY] ?? 0),
            'selesai'        => (int) ($jumlahStatus[SpRequest::STATUS_SELESAI] ?? 0),
        ];

        $prMenungguPo = SpRequest::query()
            ->with(['technician:id,name,nik,group,section'])
            ->status(SpRequest::STATUS_KEJAR_PO)
            ->orderBy('pr_at')
            ->orderBy('id')
            ->get();

        return view('sparepart.request.index', [
            'tab'             => $tab,
            'statusPengajuan' => $statusPengajuan,
            'cari'            => $cari,
            'dari'            => $dari,
            'sampai'          => $sampai,
            'daftar'          => $daftar,
            'jumlahStatus'    => $jumlahStatus,
            'jumlahTab'       => $jumlahTab,
            'prMenungguPo'    => $prMenungguPo,
            'bolehKelola'     => (bool) $request->user()?->bolehKelolaRequestSparepart(),
        ]);
    }

    /**
     * Setujui request yang masih menunggu.
     *
     * @param  Request   $request
     * @param  SpRequest $spRequest
     * @return RedirectResponse
     */
    public function setujui(Request $request, SpRequest $spRequest): RedirectResponse
    {
        $this->pastikanBolehKelola($request);

        if (! $this->layanan->setujui($spRequest, $request->user())) {
            return $this->gagalStatus();
        }

        return redirect()
            ->route('sparepart.request.index', ['tab' => 'pengajuan', 'status' => 'disetujui'])
            ->with('success', 'Request ' . $spRequest->kode . ' disetujui. Silakan isi nomor PR.');
    }

    /**
     * Tolak request yang masih menunggu.
     *
     * @param  Request   $request
     * @param  SpRequest $spRequest
     * @return RedirectResponse
     */
    public function tolak(Request $request, SpRequest $spRequest): RedirectResponse
    {
        $this->pastikanBolehKelola($request);

        $data = $request->validate([
            'alasan' => 'required|string|max:1000',
        ]);

        if (! $this->layanan->tolak($spRequest, $request->user(), $data['alasan'])) {
            return $this->gagalStatus();
        }

        return redirect()
            ->route('sparepart.request.index', ['tab' => 'pengajuan', 'status' => 'ditolak'])
            ->with('success', 'Request ' . $spRequest->kode . ' ditolak.');
    }

    /**
     * Isi nomor PR pada request yang sudah disetujui.
     *
     * @param  Request   $request
     * @param  SpRequest $spRequest
     * @return RedirectResponse
     */
    public function pr(Request $request, SpRequest $spRequest): RedirectResponse
    {
        $this->pastikanBolehKelola($request);

        $data = $request->validate([
            'pr_no' => 'required|string|max:50',
        ]);

        if (! $this->layanan->isiPr($spRequest, $request->user(), $data['pr_no'])) {
            return $this->gagalStatus();
        }

        return redirect()
            ->route('sparepart.request.index', ['tab' => 'kejar-po'])
            ->with('success', 'Nomor PR untuk ' . $spRequest->kode . ' disimpan. Request masuk Kejar PO.');
    }

    /**
     * Isi nomor PO pada request yang sedang menunggu PO.
     *
     * @param  Request   $request
     * @param  SpRequest $spRequest
     * @return RedirectResponse
     */
    public function po(Request $request, SpRequest $spRequest): RedirectResponse
    {
        $this->pastikanBolehKelola($request);

        $data = $request->validate([
            'po_no' => 'required|string|max:50',
        ]);

        if (! $this->layanan->isiPo($spRequest, $request->user(), $data['po_no'])) {
            return $this->gagalStatus();
        }

        return redirect()
            ->route('sparepart.request.index', ['tab' => 'kejar-delivery'])
            ->with('success', 'Nomor PO untuk ' . $spRequest->kode . ' disimpan. Request masuk Kejar Delivery.');
    }

    /**
     * Tandai barang sudah diterima.
     *
     * @param  Request   $request
     * @param  SpRequest $spRequest
     * @return RedirectResponse
     */
    public function terima(Request $request, SpRequest $spRequest): RedirectResponse
    {
        $this->pastikanBolehKelola($request);

        if (! $this->layanan->terima($spRequest, $request->user())) {
            return $this->gagalStatus();
        }

        return redirect()
            ->route('sparepart.request.index', ['tab' => 'selesai'])
            ->with('success', 'Request ' . $spRequest->kode . ' selesai. Barang sudah diterima.');
    }

    /**
     * Hapus request yang belum punya nomor PR.
     *
     * Hanya request pada tahap Pengajuan (menunggu, disetujui, ditolak) yang
     * boleh dihapus. Request yang sudah masuk Kejar PO/Delivery/Selesai
     * dipertahankan sebagai riwayat pengadaan.
     *
     * @param  Request   $request
     * @param  SpRequest $spRequest
     * @return RedirectResponse
     */
    public function destroy(Request $request, SpRequest $spRequest): RedirectResponse
    {
        $this->pastikanBolehKelola($request);

        $kode = $spRequest->kode;

        if (! $this->layanan->hapus($spRequest)) {
            return back()->with(
                'error',
                'Request ' . $kode . ' sudah memiliki PR/PO sehingga tidak bisa dihapus.'
            );
        }

        return back()->with('success', 'Request ' . $kode . ' dihapus.');
    }

    /**
     * Status yang dipakai untuk tab aktif.
     *
     * @param  string $tab
     * @param  string $statusPengajuan
     * @return string|array<int, string>
     */
    private function statusTab(string $tab, string $statusPengajuan): string|array
    {
        return match ($tab) {
            'kejar-po'       => SpRequest::STATUS_KEJAR_PO,
            'kejar-delivery' => SpRequest::STATUS_KEJAR_DELIVERY,
            'selesai'        => SpRequest::STATUS_SELESAI,
            default          => $statusPengajuan === 'semua'
                ? [SpRequest::STATUS_MENUNGGU, SpRequest::STATUS_DISETUJUI, SpRequest::STATUS_DITOLAK]
                : $statusPengajuan,
        };
    }

    /**
     * Hentikan aksi bila akun bukan Staff CM/PM atau administrator.
     *
     * @param  Request $request
     * @return void
     */
    private function pastikanBolehKelola(Request $request): void
    {
        abort_unless(
            $request->user()?->bolehKelolaRequestSparepart(),
            403,
            'Hanya Staff CM/PM yang boleh memproses request spare part.'
        );
    }

    /**
     * Pengalihan standar saat status request sudah berubah.
     *
     * @return RedirectResponse
     */
    private function gagalStatus(): RedirectResponse
    {
        return back()->with('error', 'Status request sudah berubah. Muat ulang halaman lalu coba lagi.');
    }
}
