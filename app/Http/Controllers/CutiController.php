<?php

namespace App\Http\Controllers;

use App\Models\CutiTeknisi;
use App\Models\CutiToken;
use App\Services\CutiService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Form cuti publik dari token Telegram.
 *
 * Form publik tidak memakai login penuh. Identitas diambil dari token yang
 * dibuat bot. Cuti langsung berlaku tanpa persetujuan admin; pengelolaan
 * (edit/hapus) dilakukan pemilik akun di halaman Cuti Saya.
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

        // Jangan menolak tautan yang sudah "opened_at". Telegram (dan aplikasi
        // lain) sering membuka URL lebih dulu untuk pratinjau, sehingga
        // opened_at terisi sebelum teknisi benar-benar mengklik. Session baru
        // tetap boleh membuka form selama token belum dipakai/kedaluwarsa;
        // submission tetap membutuhkan session dari GET terakhir.
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
        $notifTerkirim = $this->kirimNotifikasiCuti($tokenModel, $cuti);

        $request->session()->forget(['cuti_token_id', 'cuti_technician_id']);

        return redirect()
            ->route('cuti.selesai')
            ->with('cuti_hasil', [
                'nama'            => $tokenModel->technician?->name,
                'tanggal_mulai'   => $cuti->tanggal_mulai->format('d/m/Y'),
                'tanggal_selesai' => $cuti->tanggal_selesai->format('d/m/Y'),
                'jenis'           => $cuti->labelJenis(),
                'status'          => $cuti->labelStatus(),
                'notif'           => $notifTerkirim,
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
     * Mengirim notifikasi bahwa cuti tercatat beserta akses ke halaman Cuti Saya.
     *
     * @param  CutiToken  $token Token form (sumber telegram_id)
     * @param  CutiTeknisi $cuti Cuti yang baru dibuat
     * @return bool              True bila pesan Telegram berhasil dikirim
     */
    private function kirimNotifikasiCuti(CutiToken $token, CutiTeknisi $cuti): bool
    {
        $telegramId = $token->telegram_id ?: $token->technician?->telegram_id;

        if ($telegramId === null || $telegramId === '') {
            return false;
        }

        $pesan = "<b>Cuti tercatat</b>\n"
            . 'Periode: ' . $cuti->tanggal_mulai->format('d/m/Y') . ' - ' . $cuti->tanggal_selesai->format('d/m/Y') . "\n"
            . 'Jenis: ' . e($cuti->labelJenis()) . "\n"
            . 'Status: ' . e($cuti->labelStatus()) . "\n\n"
            . "Akses halaman Cuti Saya (login dengan akun Anda):\n"
            . route('cuti.saya');

        return app(TelegramService::class)->sendMessage(
            $telegramId,
            $pesan,
            ['disable_web_page_preview' => true]
        );
    }
}
