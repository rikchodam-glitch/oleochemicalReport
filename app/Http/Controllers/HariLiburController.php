<?php

namespace App\Http\Controllers;

use App\Models\HariLibur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pengelolaan hari libur nasional/perusahaan dari kalender teknisi.
 *
 * Hanya administrator yang bisa menulis (ditegakkan HanyaBacaMiddleware pada
 * grup route); teknisi lain hanya melihat kalender. Satu tanggal hanya punya
 * satu baris (unik), sehingga menyimpan tanggal yang sama akan memperbarui
 * namanya alih-alih membuat baris ganda.
 */
class HariLiburController extends Controller
{
    /**
     * Simpan satu hari libur (tambah baru atau perbarui nama bila tanggalnya sama).
     *
     * @param  Request $request Berisi tanggal (Y-m-d) dan nama hari libur
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'nama'    => ['required', 'string', 'max:150'],
        ]);

        $tanggal = Carbon::parse($data['tanggal'])->toDateString();
        $nama    = trim((string) $data['nama']);

        // whereDate (bukan updateOrCreate) supaya pencocokan tanggal benar di
        // MySQL (kolom DATE) maupun SQLite (kolom date tersimpan sebagai
        // datetime "Y-m-d 00:00:00").
        $hariLibur = HariLibur::query()->whereDate('tanggal', $tanggal)->first();

        if ($hariLibur !== null) {
            $hariLibur->update(['nama' => $nama]);
            $aksi = 'Hari libur diperbarui';
        } else {
            $hariLibur = HariLibur::create(['tanggal' => $tanggal, 'nama' => $nama]);
            $aksi      = 'Hari libur ditambahkan';
        }

        $label = Carbon::parse($tanggal)->translatedFormat('d F Y');

        return back()->with('success', $aksi . ': ' . $label . ' - ' . $nama . '.');
    }

    /**
     * Hapus satu hari libur.
     *
     * @param  HariLibur $hariLibur Hari libur yang dihapus (route model binding)
     * @return RedirectResponse
     */
    public function destroy(HariLibur $hariLibur): RedirectResponse
    {
        $nama    = $hariLibur->nama;
        $tanggal = $hariLibur->tanggal?->translatedFormat('d F Y') ?? '-';

        $hariLibur->delete();

        return back()->with('success', 'Hari libur dihapus: ' . $tanggal . ' - ' . $nama . '.');
    }
}
