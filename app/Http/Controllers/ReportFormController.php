<?php

namespace App\Http\Controllers;

use App\Models\ReportToken;
use App\Models\Technician;
use App\Services\ReportFormService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Form laporan web berbasis token Telegram.
 *
 * Teknisi membuka tautan dari /laporan tanpa login. Form mendukung beberapa
 * laporan sekaligus, hierarki lokasi, multi-alat, kolaborator, foto, dan
 * status Done/Continue.
 */
class ReportFormController extends Controller
{
    public function __construct(private readonly ReportFormService $formService)
    {
    }

    /**
     * Route login: buat token baru lalu arahkan ke form token.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create()
    {
        $teknisi = $this->teknisi();
        $token   = $this->formService->buatToken($teknisi);

        return redirect()->route('laporan.form', ['token' => $token['plain']]);
    }

    /**
     * Tampilkan form laporan dari token.
     *
     * @param  string $token Token mentah
     * @return \Illuminate\View\View|\Illuminate\Http\Response
     */
    public function form(string $token)
    {
        $tokenModel = $this->formService->tokenValid($token);

        if ($tokenModel === null) {
            return response()->view('reports.form-kadaluarsa', [
                'pesan' => 'Tautan laporan sudah kedaluwarsa atau sudah dipakai. Kirim /laporan lagi di Telegram.',
            ], 410);
        }

        $this->formService->tandaiDibuka($tokenModel);

        return view('reports.form-teknisi', array_merge(
            ['token' => $token, 'tokenModel' => $tokenModel],
            $this->formService->dataForm($tokenModel)
        ));
    }

    /**
     * Simpan semua laporan pada form.
     *
     * @param  Request $request Request form
     * @param  string  $token   Token mentah
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function simpan(Request $request, string $token)
    {
        $tokenModel = $this->formService->tokenValid($token);

        if ($tokenModel === null) {
            return response()->view('reports.form-kadaluarsa', [
                'pesan' => 'Tautan laporan sudah kedaluwarsa atau sudah dipakai. Kirim /laporan lagi di Telegram.',
            ], 410);
        }

        $maxKb = (int) (config('telegram.photo_max_bytes', 20 * 1024 * 1024) / 1024);

        $request->validate([
            'reports'                                  => ['required', 'array', 'min:1', 'max:' . ReportFormService::MAKS_LAPORAN],
            'reports.*.report_date'                    => ['required', 'date'],
            'reports.*.work_classification'            => ['required', Rule::in(['zpm1', 'zpm3', 'zpm6', 'zpm5', 'zpm7', 'zpm8', 'umum'])],
            'reports.*.jenis_pekerjaan'                => ['required', 'string', 'max:30'],
            'reports.*.work_description'               => ['required', 'string', 'max:5000'],
            'reports.*.start_time'                     => ['required', 'date_format:H:i'],
            'reports.*.end_time'                       => ['required', 'date_format:H:i'],
            'reports.*.root_cause'                     => ['nullable', 'string', 'max:2000'],
            'reports.*.finding'                        => ['nullable', 'string', 'max:2000'],
            'reports.*.tanggal_kejadian'               => ['nullable', 'date'],
            'reports.*.final_status'                   => ['required', Rule::in(['completed', 'continue'])],
            'reports.*.items'                          => ['required', 'array', 'min:1', 'max:' . ReportFormService::MAKS_ITEM],
            'reports.*.items.*.funcloc_id'             => ['required', 'integer', 'exists:functional_locations,id'],
            'reports.*.items.*.asset_id'               => ['nullable', 'integer', 'exists:assets,id'],
            'reports.*.items.*.equipment_type'         => ['nullable', 'string', 'max:100'],
            'reports.*.collaborator_ids'               => ['nullable', 'array'],
            'reports.*.collaborator_ids.*'             => ['integer', 'exists:technicians,id'],
            'reports.*.photos_documentation'           => ['nullable', 'array', 'max:10'],
            'reports.*.photos_documentation.*'         => ['image', 'mimes:jpg,jpeg,png,webp', 'max:' . $maxKb],
            'reports.*.photos_hygiene'                 => ['nullable', 'array', 'max:10'],
            'reports.*.photos_hygiene.*'               => ['image', 'mimes:jpg,jpeg,png,webp', 'max:' . $maxKb],
        ]);

        $jumlah = $this->formService->simpanBanyak($tokenModel, $request->input('reports', []), $request);

        return redirect()
            ->route('laporan.selesai')
            ->with('laporan_hasil', ['jumlah' => $jumlah]);
    }

    /**
     * Halaman sukses setelah laporan dikirim.
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function selesai()
    {
        $hasil = session('laporan_hasil');

        if (! is_array($hasil)) {
            return redirect()->route('login');
        }

        return view('reports.form-selesai', ['hasil' => $hasil]);
    }

    /**
     * Tipe equipment pada subtree lokasi.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function tipeAlat(Request $request, string $token)
    {
        $tokenModel = $this->formService->tokenValid($token);

        if ($tokenModel === null) {
            return response()->json(['message' => 'Token tidak berlaku.'], 410);
        }

        $funclocId = (int) $request->query('funcloc_id', 0);

        return response()->json([
            'data' => $funclocId > 0 ? $this->formService->tipeAlat($tokenModel, $funclocId) : [],
        ]);
    }

    /**
     * Pencarian alat pada subtree lokasi.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cariAlat(Request $request, string $token)
    {
        $tokenModel = $this->formService->tokenValid($token);

        if ($tokenModel === null) {
            return response()->json(['message' => 'Token tidak berlaku.'], 410);
        }

        $funclocId = (int) $request->query('funcloc_id', 0);

        if ($funclocId <= 0) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $this->formService->cariAlat(
                $tokenModel,
                $funclocId,
                (string) $request->query('q', ''),
                (string) $request->query('type', '')
            ),
        ]);
    }

    /**
     * Teknisi pemilik akun login.
     *
     * @return Technician
     */
    private function teknisi(): Technician
    {
        $teknisi = auth()->user()?->technician;

        if ($teknisi === null) {
            abort(403, 'Akun ini belum terhubung ke data teknisi. Hubungi administrator.');
        }

        if ($teknisi->status !== 'active') {
            abort(403, 'Akun teknisi ini sedang tidak aktif.');
        }

        return $teknisi;
    }
}
