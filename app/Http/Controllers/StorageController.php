<?php

namespace App\Http\Controllers;

use App\Services\StorageRelocationService;
use App\Services\StorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * StorageController
 *
 * Panel penyimpanan: menampilkan pemakaian disk/folder/database, menjalankan
 * pembersih snapshot CCTV yang melewati batas retensi, dan memindahkan folder
 * penyimpanan aplikasi (storage/app) ke drive lain lewat junction sehingga
 * penggantian/penambahan HDD cukup dilakukan dari halaman ini.
 */
class StorageController extends Controller
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly StorageRelocationService $relocation,
    ) {
    }

    /**
     * Tampilkan panel penyimpanan beserta ringkasan terbaru.
     *
     * @param  Request  $request
     * @return View
     */
    public function index(Request $request): View
    {
        return view('storage.index', [
            'report' => $this->storage->report($request->boolean('fresh')),
            'plan'   => session('storage_plan'),
            'target' => (string) old('target', (string) config('storage.data_root', '')),
        ]);
    }

    /**
     * Jalankan pembersih snapshot lama (uji coba atau hapus sungguhan).
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function clean(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'mode' => ['required', 'in:dry,force'],
        ]);

        $days = $data['days'] !== null && $data['days'] !== '' ? (int) $data['days'] : null;
        $dryRun = $data['mode'] === 'dry';
        $result = $this->storage->cleanSnapshots($days, $dryRun);

        $this->storage->report(true); // segarkan cache laporan

        $log = [
            'Lokasi        : ' . $result['root'],
            'Retensi       : ' . $result['retention_days'] . ' hari',
            'Berkas ' . ($dryRun ? 'kandidat' : 'terhapus') . ' : ' . number_format($result['deleted_files']),
            'Ukuran ' . ($dryRun ? 'kandidat' : 'terhapus') . ' : '
                . number_format($result['deleted_bytes'] / 1048576, 1) . ' MB',
        ];

        if (!$dryRun) {
            $log[] = 'Folder kosong dihapus : ' . number_format($result['removed_dirs']);
        }

        if ($result['failed'] > 0) {
            $log[] = 'Gagal dihapus : ' . number_format($result['failed']) . ' berkas';
        }

        return back()
            ->with('storage_log', $log)
            ->with('storage_status', 'ok')
            ->with('storage_title', $dryRun ? 'Uji coba pembersih selesai' : 'Pembersihan selesai');
    }

    /**
     * Periksa kelayakan (mode plan) atau jalankan pemindahan (mode force).
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function relocate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'max:190'],
            'mode'   => ['required', 'in:plan,force'],
        ]);

        $target = (string) $data['target'];

        if ($data['mode'] === 'plan') {
            $plan = $this->relocation->plan($target);

            return back()
                ->withInput()
                ->with('storage_plan', $plan)
                ->with('storage_status', $plan['ok'] ? 'ok' : 'fail')
                ->with('storage_title', 'Hasil pemeriksaan kelayakan');
        }

        // Pemindahan menyalin seluruh berkas (bisa beberapa menit), jadi batas
        // waktu eksekusi PHP dilepas khusus untuk proses ini.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $log = [];
        $result = $this->relocation->relocate($target, function (string $line) use (&$log): void {
            $log[] = $line;
        });

        $this->storage->report(true); // segarkan cache laporan

        if ($result['success']) {
            $log[] = 'Data sekarang berada di : ' . $result['plan']['target'];
            $log[] = 'storage/app tetap di : ' . $result['plan']['source'] . ' (junction)';
        }

        return back()
            ->with('storage_log', $log)
            ->with('storage_status', $result['success'] ? 'ok' : 'fail')
            ->with('storage_title', $result['success'] ? 'Pemindahan selesai' : 'Pemindahan gagal')
            ->with('storage_error', $result['error'] ?? null);
    }
}
