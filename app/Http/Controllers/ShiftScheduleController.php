<?php

namespace App\Http\Controllers;

use App\Models\TechnicianShift;
use App\Services\Shift\ShiftPatternService;
use App\Services\Shift\ShiftScheduleImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    /**
     * Buat jadwal shift otomatis dari pola rotasi untuk 30 hari ke depan,
     * dipakai supaya periode berikutnya tidak perlu upload Excel jadwal.
     *
     * @param  ShiftPatternService $pola Service pola rotasi shift
     * @return RedirectResponse          Redirect kembali dengan pesan hasil
     */
    public function generate(ShiftPatternService $pola): RedirectResponse
    {
        $terakhir = TechnicianShift::max('shift_date');

        $mulai = $terakhir !== null
            ? Carbon::parse((string) $terakhir)->addDay()->startOfDay()
            : now()->startOfDay();

        $selesai = $mulai->copy()->addDays(29);

        $jumlah = $pola->simpanProyeksi($mulai, $selesai);

        return back()->with('success', sprintf(
            'Jadwal otomatis dibuat dari pola rotasi: %d baris untuk %s s/d %s. Jadwal hasil import tetap dipakai bila ada.',
            $jumlah,
            $mulai->format('d/m/Y'),
            $selesai->format('d/m/Y')
        ));
    }
}
