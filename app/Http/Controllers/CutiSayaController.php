<?php

namespace App\Http\Controllers;

use App\Models\CutiTeknisi;
use App\Models\Technician;
use App\Services\CutiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Halaman cuti milik teknisi yang sudah login.
 *
 * Akun harus terhubung ke teknisi melalui users.technician_id. Pengguna hanya
 * bisa melihat, mengubah, dan menghapus cuti miliknya sendiri.
 */
class CutiSayaController extends Controller
{
    public function __construct(private readonly CutiService $cutiService)
    {
    }

    /**
     * Daftar cuti milik pengguna yang sedang login.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $teknisi = $this->teknisi();

        $daftar = CutiTeknisi::query()
            ->where('technician_id', $teknisi->id)
            ->orderByDesc('tanggal_mulai')
            ->paginate(15);

        return view('cuti.saya-index', [
            'teknisi' => $teknisi,
            'daftar'  => $daftar,
        ]);
    }

    /**
     * Form edit cuti milik pengguna.
     *
     * @param  CutiTeknisi $cuti Cuti yang diubah
     * @return \Illuminate\View\View
     */
    public function edit(CutiTeknisi $cuti)
    {
        $this->pastikanMilik($cuti);

        return view('cuti.saya-edit', [
            'cuti'      => $cuti,
            'jenisCuti' => CutiTeknisi::JENIS,
        ]);
    }

    /**
     * Menyimpan perubahan cuti milik pengguna.
     *
     * @param  Request     $request Request form
     * @param  CutiTeknisi $cuti    Cuti yang diubah
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, CutiTeknisi $cuti)
    {
        $this->pastikanMilik($cuti);

        $data = $request->validate([
            'tanggal_mulai'   => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'jenis'           => ['required', Rule::in(array_keys(CutiTeknisi::JENIS))],
            'alasan'          => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->cutiService->perbarui($cuti, $data);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('cuti.saya')
            ->with('status', 'Cuti berhasil diperbarui. Target man hour mengikuti perubahan ini.');
    }

    /**
     * Menghapus cuti milik pengguna.
     *
     * @param  CutiTeknisi $cuti Cuti yang dihapus
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(CutiTeknisi $cuti)
    {
        $this->pastikanMilik($cuti);

        $cuti->delete();

        return redirect()
            ->route('cuti.saya')
            ->with('status', 'Cuti berhasil dihapus. Target man hour mengikuti perubahan ini.');
    }

    /**
     * Teknisi pemilik akun yang sedang login.
     *
     * @return Technician
     */
    private function teknisi(): Technician
    {
        $teknisi = auth()->user()?->technician;

        if ($teknisi === null) {
            abort(403, 'Akun ini belum terhubung ke data teknisi. Hubungi administrator.');
        }

        return $teknisi;
    }

    /**
     * Pastikan cuti milik teknisi yang sedang login.
     *
     * @param  CutiTeknisi $cuti Cuti yang diperiksa
     * @return void
     */
    private function pastikanMilik(CutiTeknisi $cuti): void
    {
        if ((int) $cuti->technician_id !== (int) $this->teknisi()->id) {
            abort(403, 'Anda hanya boleh mengelola cuti milik sendiri.');
        }
    }
}
