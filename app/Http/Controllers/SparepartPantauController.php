<?php

namespace App\Http\Controllers;

use App\Models\SpPantau;
use App\Services\Sparepart\SparepartPantauService;
use App\Services\Sparepart\SparepartUnggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Halaman daftar pantau Spare Part.
 *
 * Daftar pantau menentukan material mana yang ditampilkan pada halaman
 * Integrasi PR/PO. Isinya dapat diimpor dari berkas (sheet MASTER DATA atau
 * CSV) maupun ditambah/ubah/hapus satu-satu di halaman ini.
 */
class SparepartPantauController extends Controller
{
    /** Jumlah baris daftar pantau per halaman. */
    private const BARIS_PER_HALAMAN = 50;

    /**
     * @param  SparepartPantauService $layanan Layanan daftar pantau
     */
    public function __construct(private SparepartPantauService $layanan)
    {
    }

    /**
     * Daftar pantau beserta ringkasannya.
     *
     * @param  Request $request Permintaan HTTP (pencarian dan nomor halaman)
     * @return View             Halaman daftar pantau
     */
    public function index(Request $request): View
    {
        $kunci = trim((string) $request->input('q', ''));

        $daftar = SpPantau::query()
            ->when($kunci !== '', function ($query) use ($kunci): void {
                $query->where(function ($sub) use ($kunci): void {
                    $sub->where('kode_material', 'like', '%' . $kunci . '%')
                        ->orWhere('deskripsi', 'like', '%' . $kunci . '%');
                });
            })
            ->orderBy('kode_material')
            ->paginate(self::BARIS_PER_HALAMAN)
            ->withQueryString();

        return view('sparepart.pantau', [
            'daftar'    => $daftar,
            'kunci'     => $kunci,
            'ringkasan' => [
                'total'          => SpPantau::query()->count(),
                'manual'         => SpPantau::query()->where('sumber', SpPantau::SUMBER_MANUAL)->count(),
                'tanpa_stok_min' => SpPantau::query()->whereNull('stok_min')->count(),
            ],
        ]);
    }

    /**
     * Menambah satu baris daftar pantau.
     *
     * @param  Request $request Permintaan HTTP (isian baris baru)
     * @return RedirectResponse Kembali ke daftar pantau
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_material' => ['required', 'string', 'max:40', 'regex:/^\d{6,}$/', 'unique:sp_pantau,kode_material'],
            'deskripsi'     => ['nullable', 'string', 'max:255'],
            'plant'         => ['nullable', 'string', 'max:100'],
            'model'         => ['nullable', 'string', 'max:100'],
            'tag_no'        => ['nullable', 'string', 'max:100'],
            'uom'           => ['nullable', 'string', 'max:20'],
            'stok_min'      => ['nullable', 'numeric', 'min:0'],
        ], [
            'kode_material.regex'  => 'Kode material harus berupa angka minimal 6 digit.',
            'kode_material.unique' => 'Kode material itu sudah ada di daftar pantau.',
        ], [
            'kode_material' => 'kode material',
            'stok_min'      => 'stok minimum',
        ]);

        $this->layanan->tambah($data);

        return redirect()
            ->route('sparepart.pantau')
            ->with('success', 'Material ' . $data['kode_material'] . ' ditambahkan ke daftar pantau.');
    }

    /**
     * Mengubah satu baris daftar pantau.
     *
     * Perubahan stok minimum membuat status REORDER material itu dihitung
     * ulang oleh layanan.
     *
     * @param  Request  $request Permintaan HTTP (isian perubahan)
     * @param  SpPantau $pantau  Baris yang diubah (route model binding)
     * @return RedirectResponse  Kembali ke daftar pantau
     */
    public function update(Request $request, SpPantau $pantau): RedirectResponse
    {
        $data = $request->validate([
            'kode_material' => ['required', 'string', 'max:40', 'regex:/^\d{6,}$/'],
            'deskripsi'     => ['nullable', 'string', 'max:255'],
            'plant'         => ['nullable', 'string', 'max:100'],
            'model'         => ['nullable', 'string', 'max:100'],
            'tag_no'        => ['nullable', 'string', 'max:100'],
            'uom'           => ['nullable', 'string', 'max:20'],
            'stok_min'      => ['nullable', 'numeric', 'min:0'],
        ], [
            'kode_material.regex' => 'Kode material harus berupa angka minimal 6 digit.',
        ], [
            'kode_material' => 'kode material',
            'stok_min'      => 'stok minimum',
        ]);

        $this->layanan->ubah($pantau, $data);

        return redirect()
            ->route('sparepart.pantau')
            ->with('success', 'Baris ' . $data['kode_material'] . ' diperbarui dan statusnya dihitung ulang.');
    }

    /**
     * Menghapus satu baris daftar pantau.
     *
     * @param  SpPantau $pantau Baris yang dihapus (route model binding)
     * @return RedirectResponse  Kembali ke daftar pantau
     */
    public function destroy(SpPantau $pantau): RedirectResponse
    {
        $kode = $pantau->kode_material;
        $this->layanan->hapus($pantau);

        return redirect()
            ->route('sparepart.pantau')
            ->with('success', 'Material ' . $kode . ' dikeluarkan dari daftar pantau.');
    }

    /**
     * Mengimpor daftar pantau dari berkas.
     *
     * @param  Request $request Permintaan HTTP (berkas + mode impor)
     * @return RedirectResponse  Kembali ke daftar pantau beserta rinciannya
     */
    public function impor(Request $request): RedirectResponse
    {
        $request->validate([
            'berkas' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:51200'],
            'mode'   => ['nullable', 'in:gabung,ganti'],
        ], [
            'berkas.mimes' => 'Berkas daftar pantau harus berupa Excel (.xlsx/.xls) atau CSV.',
        ], [
            'berkas' => 'berkas daftar pantau',
        ]);

        $unggahan = new SparepartUnggahan();
        $path     = $unggahan->simpan($request->file('berkas'), 'pantau');

        try {
            $hasil = $this->layanan->imporDariBerkas(
                $path,
                $request->input('mode') === SparepartPantauService::MODE_GANTI
                    ? SparepartPantauService::MODE_GANTI
                    : SparepartPantauService::MODE_GABUNG
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Impor daftar pantau dibatalkan: ' . $e->getMessage());
        } finally {
            $unggahan->hapus(['berkas' => $path]);
        }

        $pesan = 'Daftar pantau ' . $hasil['nama'] . ': ' . $hasil['baris'] . ' baris dibaca, '
            . $hasil['kode'] . ' kode unik (baru ' . $hasil['baru']
            . ', diperbarui ' . $hasil['diperbarui'] . ', dihapus ' . $hasil['dihapus'] . ').';

        if ($hasil['ganda'] > 0) {
            $pesan .= ' ' . $hasil['ganda'] . ' kode muncul lebih dari sekali (nilai terakhir dipakai).';
        }

        return redirect()
            ->route('sparepart.pantau')
            ->with('success', $pesan)
            ->with('catatan_pantau', $hasil['catatan']);
    }
}