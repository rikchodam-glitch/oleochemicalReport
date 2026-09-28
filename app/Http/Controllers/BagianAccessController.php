<?php

namespace App\Http\Controllers;

use App\Models\BagianAccess;
use App\Models\Technician;
use App\Models\User;
use App\Support\AksesModul;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pengaturan hak akses halaman per Bagian.
 *
 * Satu Bagian (section teknisi) punya daftar modul halaman yang boleh dibuka.
 * Modul bertanda khusus_admin (mis. Manajemen User) tidak bisa diberikan.
 */
class BagianAccessController extends Controller
{
    /**
     * Halaman daftar Bagian beserta centang hak aksesnya.
     *
     * @param  Request $request Parameter ?bagian= untuk membuka Bagian tertentu
     * @return View
     */
    public function index(Request $request): View
    {
        $daftarBagian = [];

        foreach (Technician::SECTIONS as $kode => $label) {
            $baris = BagianAccess::untukBagian($kode);

            $daftarBagian[] = [
                'kode'           => $kode,
                'label'          => $label,
                'permissions'    => $baris->daftarModul(),
                'jumlah_akun'    => User::query()->where('role', $kode)->count(),
                'jumlah_teknisi' => Technician::query()->where('section', $kode)->count(),
                'tersimpan'      => $baris->exists,
            ];
        }

        return view('akses.index', [
            'modulPerGrup'      => AksesModul::perGrup(),
            'daftarBagian'      => $daftarBagian,
            'bagianTerbuka'     => (string) $request->input('bagian', array_key_first(Technician::SECTIONS)),
            'jumlahAkunAksesPenuh' => User::query()->whereIn('role', User::ROLE_AKSES_PENUH)->count(),
        ]);
    }

    /**
     * Simpan hak akses satu Bagian.
     *
     * @param  Request $request Berisi array permissions
     * @param  string  $bagian  Kode Bagian yang diubah
     * @return RedirectResponse
     */
    public function update(Request $request, string $bagian): RedirectResponse
    {
        if (! array_key_exists($bagian, Technician::SECTIONS)) {
            abort(404, 'Bagian tidak dikenali.');
        }

        $data = $request->validate([
            'permissions'   => 'array',
            'permissions.*' => 'string|in:' . implode(',', array_keys(AksesModul::semua())),
        ]);

        // Modul khusus admin tidak bisa diberikan ke Bagian mana pun.
        $permissions = array_values(array_filter(
            (array) ($data['permissions'] ?? []),
            fn ($kunci) => ! AksesModul::khususAdmin((string) $kunci)
        ));

        BagianAccess::query()->updateOrCreate(
            ['bagian' => $bagian],
            ['permissions' => $permissions]
        );

        $label = Technician::SECTIONS[$bagian];

        return redirect()
            ->route('akses.index', ['bagian' => $bagian])
            ->with('success', 'Hak akses Bagian ' . $label . ' disimpan (' . count($permissions) . ' halaman diizinkan).');
    }
}
