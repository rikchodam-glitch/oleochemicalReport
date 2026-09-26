<?php

namespace App\Http\Controllers;

use App\Services\Shift\ShiftScheduleImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Import jadwal shift teknisi dari file Excel yang diunggah admin, dipakai
 * sebagai dasar target man hour harian dan deteksi laporan di luar jam shift.
 */
class ShiftScheduleController extends Controller
{
    /**
     * Simpan file jadwal yang diunggah lalu import isinya.
     *
     * @param  Request                    $request Request upload berisi file jadwal
     * @param  ShiftScheduleImportService $service Service parser jadwal
     * @return RedirectResponse                    Redirect kembali dengan pesan hasil import
     */
    public function store(Request $request, ShiftScheduleImportService $service): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $file = $request->file('file');

        if ($file === null) {
            return back()->with('error', 'File jadwal tidak terbaca. Silakan coba lagi.');
        }

        $namaAman = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $namaFile = now()->format('Ymd-His') . '-' . $namaAman;

        try {
            Storage::disk('local')->putFileAs('shift-schedules', $file, $namaFile);
            $path = Storage::disk('local')->path('shift-schedules/' . $namaFile);

            $hasil = $service->import($path, Auth::id());
        } catch (Throwable $e) {
            Log::error('ShiftScheduleController: import jadwal gagal', [
                'file'  => $namaFile,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Import jadwal gagal: ' . $e->getMessage());
        }

        $pesan = sprintf(
            'Jadwal %s diimport: %d orang dibaca, %d terpetakan ke teknisi, %d tidak cocok, %d baris shift tersimpan.',
            ($hasil['periode_mulai'] ?? '-') . ' s/d ' . ($hasil['periode_selesai'] ?? '-'),
            $hasil['jumlah_baris'],
            $hasil['jumlah_terpetakan'],
            $hasil['jumlah_tidak_cocok'],
            $hasil['jumlah_baris_shift']
        );

        $respons = back()->with('success', $pesan);

        $tidakCocok = (array) ($hasil['detail_tidak_cocok'] ?? []);

        if ($tidakCocok !== []) {
            $ringkas = implode('; ', array_slice($tidakCocok, 0, 10));
            $sisa    = count($tidakCocok) > 10 ? ' (dan ' . (count($tidakCocok) - 10) . ' lainnya)' : '';

            $respons = $respons->with('warning', 'Belum cocok dengan data teknisi: ' . $ringkas . $sisa);
        }

        return $respons;
    }
}
