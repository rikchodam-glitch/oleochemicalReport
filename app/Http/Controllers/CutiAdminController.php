<?php

namespace App\Http\Controllers;

use App\Models\CutiTeknisi;
use App\Models\Technician;
use App\Services\CutiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pencatatan cuti oleh Admin Reguler/Administrator.
 *
 * Halaman ini global: admin memilih teknisi langsung pada form, tanpa harus
 * membuka profil teknisi satu per satu. Teknisi sendiri hanya melihat cuti.
 */
class CutiAdminController extends Controller
{
    public function __construct(private readonly CutiService $cutiService)
    {
    }

    /**
     * Halaman kelola cuti seluruh teknisi.
     *
     * @param  Request $request Request filter teknisi
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $this->pastikanBolehKelola();

        $teknisiId = (int) $request->query('technician_id', 0);
        $teknisiTerpilih = $teknisiId > 0 ? Technician::find($teknisiId) : null;

        $daftar = CutiTeknisi::query()
            ->with('technician:id,name,nik,group,section')
            ->when($teknisiTerpilih !== null, fn ($query) => $query->where('technician_id', $teknisiTerpilih->id))
            ->orderByDesc('tanggal_mulai')
            ->paginate(20)
            ->withQueryString();

        $daftarTeknisi = Technician::query()
            ->orderBy('name')
            ->get(['id', 'name', 'nik', 'group', 'section', 'status']);

        return view('cuti.kelola', [
            'daftar'           => $daftar,
            'daftarTeknisi'    => $daftarTeknisi,
            'teknisiTerpilih'  => $teknisiTerpilih,
            'jenisCuti'        => CutiTeknisi::JENIS,
        ]);
    }

    /**
     * Mencatat cuti baru untuk teknisi yang dipilih pada form.
     *
     * @param  Request $request Request form
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $this->pastikanBolehKelola();

        $data = $request->validate([
            'technician_id'   => ['required', 'integer', 'exists:technicians,id'],
            'tanggal_mulai'   => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'jenis'           => ['required', Rule::in(array_keys(CutiTeknisi::JENIS))],
            'alasan'          => ['nullable', 'string', 'max:1000'],
        ]);

        $teknisi = Technician::findOrFail($data['technician_id']);

        try {
            $this->cutiService->catatOlehAdmin($teknisi, $data);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('cuti.kelola.index', ['technician_id' => $teknisi->id])
            ->with('status', 'Cuti ' . $teknisi->name . ' berhasil dicatat.');
    }

    /**
     * Form edit cuti.
     *
     * @param  CutiTeknisi $cuti Cuti yang diubah
     * @return \Illuminate\View\View
     */
    public function edit(CutiTeknisi $cuti)
    {
        $this->pastikanBolehKelola();

        return view('cuti.kelola-edit', [
            'cuti'      => $cuti,
            'jenisCuti' => CutiTeknisi::JENIS,
        ]);
    }

    /**
     * Menyimpan perubahan cuti.
     *
     * @param  Request     $request Request form
     * @param  CutiTeknisi $cuti    Cuti yang diubah
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, CutiTeknisi $cuti)
    {
        $this->pastikanBolehKelola();

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
            ->route('cuti.kelola.index', ['technician_id' => $cuti->technician_id])
            ->with('status', 'Cuti berhasil diperbarui.');
    }

    /**
     * Menghapus cuti.
     *
     * @param  CutiTeknisi $cuti Cuti yang dihapus
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(CutiTeknisi $cuti)
    {
        $this->pastikanBolehKelola();

        $technicianId = $cuti->technician_id;
        $cuti->delete();

        return redirect()
            ->route('cuti.kelola.index', ['technician_id' => $technicianId])
            ->with('status', 'Cuti berhasil dihapus. Target man hour mengikuti perubahan ini.');
    }

    /**
     * Pastikan akun boleh mengelola cuti.
     *
     * @return void
     */
    private function pastikanBolehKelola(): void
    {
        if (! auth()->user()?->bolehKelolaCuti()) {
            abort(403, 'Halaman ini hanya untuk Admin Reguler atau Administrator.');
        }
    }
}
