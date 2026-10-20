<?php

namespace App\Http\Controllers;

use App\Models\CutiTeknisi;
use App\Services\CutiService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Form cuti publik (via token Telegram) dan halaman persetujuan admin.
 *
 * Form publik tidak memakai login penuh. Identitas diambil dari token yang
 * dibuat bot dan diikat ke session browser saat tautan pertama dibuka.
 */
class CutiController extends Controller
{
    public function __construct(private readonly CutiService $cutiService)
    {
    }

    /**
     * Menampilkan form cuti dari token Telegram.
     *
     * @param  Request $request Request form
     * @param  string  $token   Token mentah dari URL
     * @return \Illuminate\View\View|\Illuminate\Http\Response
     */
    public function form(Request $request, string $token)
    {
        $tokenModel = $this->cutiService->tokenValid($token);

        if ($tokenModel === null) {
            return response()->view('cuti.kadaluarsa', [
                'pesan' => 'Tautan cuti sudah kedaluwarsa atau sudah dipakai. Kirim /cuti lagi di Telegram untuk mendapatkan tautan baru.',
            ], 410);
        }

        $sesiToken = $request->session()->get('cuti_token_id');

        if ($tokenModel->opened_at !== null && (int) $sesiToken !== (int) $tokenModel->id) {
            return response()->view('cuti.kadaluarsa', [
                'pesan' => 'Tautan ini sudah dibuka di perangkat lain. Minta tautan baru dengan mengirim /cuti di Telegram.',
            ], 410);
        }

        $this->cutiService->tandaiDibuka($tokenModel);

        $request->session()->put('cuti_token_id', $tokenModel->id);
        $request->session()->put('cuti_technician_id', $tokenModel->technician_id);

        $riwayat = CutiTeknisi::query()
            ->where('technician_id', $tokenModel->technician_id)
            ->latest('tanggal_mulai')
            ->limit(5)
            ->get();

        return view('cuti.form', [
            'token'       => $token,
            'tokenModel'  => $tokenModel,
            'teknisi'     => $tokenModel->technician,
            'alasanAwal'  => $tokenModel->alasan,
            'riwayat'     => $riwayat,
            'jenisCuti'   => CutiTeknisi::JENIS,
            'kedaluwarsa' => $tokenModel->expires_at,
        ]);
    }

    /**
     * Menyimpan pengajuan cuti dari form publik.
     *
     * @param  Request $request Request form
     * @param  string  $token   Token mentah dari URL
     * @return \Illuminate\Http\RedirectResponse
     */
    public function simpan(Request $request, string $token)
    {
        $tokenModel = $this->cutiService->tokenValid($token);

        if ($tokenModel === null
            || (int) $request->session()->get('cuti_token_id') !== (int) $tokenModel->id
            || (int) $request->session()->get('cuti_technician_id') !== (int) $tokenModel->technician_id) {
            return redirect()
                ->route('cuti.form', ['token' => $token])
                ->withErrors(['token' => 'Sesi form tidak valid. Buka ulang tautan dari Telegram.']);
        }

        $data = $request->validate([
            'tanggal_mulai'   => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'jenis'           => ['required', Rule::in(array_keys(CutiTeknisi::JENIS))],
            'alasan'          => ['nullable', 'string', 'max:1000'],
        ]);

        $cuti = $this->cutiService->ajukan($tokenModel, $data);

        $request->session()->forget(['cuti_token_id', 'cuti_technician_id']);

        return redirect()
            ->route('cuti.selesai')
            ->with('cuti_hasil', [
                'nama'            => $tokenModel->technician?->name,
                'tanggal_mulai'   => $cuti->tanggal_mulai->format('d/m/Y'),
                'tanggal_selesai' => $cuti->tanggal_selesai->format('d/m/Y'),
                'jenis'           => $cuti->labelJenis(),
                'status'          => $cuti->labelStatus(),
            ]);
    }

    /**
     * Halaman konfirmasi setelah pengajuan dikirim.
     *
     * @param  Request $request Request
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function selesai(Request $request)
    {
        $hasil = $request->session()->get('cuti_hasil');

        if (! is_array($hasil)) {
            return redirect()->route('login');
        }

        return view('cuti.selesai', ['hasil' => $hasil]);
    }

    /**
     * Daftar pengajuan cuti untuk admin.
     *
     * @param  Request $request Request filter
     * @return \Illuminate\View\View
     */
    public function adminIndex(Request $request)
    {
        $this->pastikanAdmin();

        $status = (string) $request->query('status', CutiTeknisi::STATUS_PENDING);

        if (! in_array($status, [
            CutiTeknisi::STATUS_PENDING,
            CutiTeknisi::STATUS_DISETUJUI,
            CutiTeknisi::STATUS_DITOLAK,
            'semua',
        ], true)) {
            $status = CutiTeknisi::STATUS_PENDING;
        }

        $cari = trim((string) $request->query('q', ''));

        $daftar = CutiTeknisi::query()
            ->with(['technician:id,name,nik,group', 'penyetuju:id,name'])
            ->when($status !== 'semua', fn ($query) => $query->where('status', $status))
            ->when($cari !== '', function ($query) use ($cari): void {
                $query->whereHas('technician', function ($teknisi) use ($cari): void {
                    $teknisi->where('name', 'like', '%' . $cari . '%')
                        ->orWhere('nik', 'like', '%' . $cari . '%');
                });
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('tanggal_mulai')
            ->paginate(20)
            ->withQueryString();

        $ringkasan = [
            'pending'   => CutiTeknisi::where('status', CutiTeknisi::STATUS_PENDING)->count(),
            'disetujui' => CutiTeknisi::where('status', CutiTeknisi::STATUS_DISETUJUI)->count(),
            'ditolak'   => CutiTeknisi::where('status', CutiTeknisi::STATUS_DITOLAK)->count(),
        ];

        return view('cuti.admin-index', [
            'daftar'    => $daftar,
            'ringkasan' => $ringkasan,
            'status'    => $status,
            'cari'      => $cari,
        ]);
    }

    /**
     * Menyetujui pengajuan cuti.
     *
     * @param  Request     $request Request admin
     * @param  CutiTeknisi $cuti    Pengajuan
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setujui(Request $request, CutiTeknisi $cuti)
    {
        $this->pastikanAdmin();

        try {
            $cuti = $this->cutiService->setujui(
                $cuti,
                $request->user(),
                (string) $request->input('catatan', '')
            );
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->kirimKabar($cuti, true);

        return back()->with('status', 'Cuti ' . $cuti->technician?->name . ' disetujui.');
    }

    /**
     * Menolak pengajuan cuti.
     *
     * @param  Request     $request Request admin
     * @param  CutiTeknisi $cuti    Pengajuan
     * @return \Illuminate\Http\RedirectResponse
     */
    public function tolak(Request $request, CutiTeknisi $cuti)
    {
        $this->pastikanAdmin();

        try {
            $cuti = $this->cutiService->tolak(
                $cuti,
                $request->user(),
                (string) $request->input('catatan', '')
            );
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->kirimKabar($cuti, false);

        return back()->with('status', 'Cuti ' . $cuti->technician?->name . ' ditolak.');
    }

    /**
     * Mengirim kabar persetujuan/penolakan ke Telegram teknisi.
     *
     * @param  CutiTeknisi $cuti     Pengajuan
     * @param  bool        $disetujui True bila disetujui
     * @return void
     */
    private function kirimKabar(CutiTeknisi $cuti, bool $disetujui): void
    {
        $telegramId = $cuti->technician?->telegram_id;

        if ($telegramId === null || $telegramId === '') {
            return;
        }

        $status = $disetujui ? 'DISETUJUI' : 'DITOLAK';
        $pesan  = "Cuti Anda {$status}.\n"
            . 'Periode: ' . $cuti->tanggal_mulai->format('d/m/Y') . ' - ' . $cuti->tanggal_selesai->format('d/m/Y') . "\n"
            . 'Jenis: ' . $cuti->labelJenis();

        if (($cuti->catatan_approver ?? '') !== '') {
            $pesan .= "\nCatatan: " . htmlspecialchars((string) $cuti->catatan_approver, ENT_QUOTES, 'UTF-8');
        }

        app(TelegramService::class)->sendMessage($telegramId, $pesan);
    }

    /**
     * Memastikan hanya administrator yang boleh mengelola cuti.
     *
     * @return void
     */
    private function pastikanAdmin(): void
    {
        if (! auth()->user()?->bolehKelolaUser()) {
            abort(403, 'Halaman cuti hanya bisa diakses administrator.');
        }
    }
}
