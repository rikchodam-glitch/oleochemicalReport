<?php

namespace App\Http\Controllers;

use App\Services\MySqlRelocationService;
use App\Services\StorageRelocationService;
use App\Services\StorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        private readonly MySqlRelocationService $mysql,
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
            'report'         => $this->storage->report($request->boolean('fresh')),
            'plan'           => session('storage_plan'),
            'databasePlan'   => session('database_plan'),
            'relocateDrives' => $this->relocateDrives(),
            'projectDrive'   => $this->projectDrive(),
            'folderName'     => $this->defaultFolderName(),
            'databaseFolder' => (string) config('storage.database_folder', 'oleochemical-mysql'),
            'mysqlRunning'   => $this->mysql->isRunning(),
            'opcache'        => $this->opcacheStatus(),
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
        $allowedDrives = array_column($this->relocateDrives(), 'letter');

        $data = $request->validate([
            'drive'  => ['required', 'string', Rule::in($allowedDrives)],
            'folder' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'mode'   => ['required', 'in:plan,force'],
        ], [
            'drive.in'     => 'Drive tujuan tidak tersedia. Pilih salah satu drive pada daftar.',
            'folder.regex' => 'Nama folder hanya boleh berisi huruf, angka, spasi, titik, garis bawah, atau tanda hubung.',
        ]);

        // Nama folder dirapikan dari spasi/titik di ujung, lalu digabung dengan
        // drive terpilih menjadi folder tujuan (dibuat otomatis bila belum ada).
        $target = strtoupper($data['drive']) . ':/' . rtrim(trim($data['folder']), '. ');

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

    /**
     * Huruf drive tempat aplikasi ini berjalan (drive sistem/berkas project).
     *
     * @return string
     */
    private function projectDrive(): string
    {
        return strtoupper(substr(storage_path('app'), 0, 1));
    }

    /**
     * Daftar drive yang ditawarkan sebagai tujuan pemindahan: seluruh drive
     * data kecuali drive tempat project berjalan, supaya pemindahan tidak
     * diarahkan ke drive sistem.
     *
     * @return array<int, array<string, mixed>>
     */
    private function relocateDrives(): array
    {
        $projectDrive = $this->projectDrive();

        return array_values(array_filter(
            $this->storage->disks(),
            static fn (array $disk): bool => $disk['letter'] !== $projectDrive
        ));
    }

    /**
     * Nama folder bawaan untuk tujuan pemindahan. Bila data sudah pernah
     * dipindah, nama folder yang sedang dipakai dijadikan bawaan supaya
     * pemindahan ke drive baru tetap memakai nama yang sama.
     *
     * @return string
     */
    private function defaultFolderName(): string
    {
        $real = $this->storage->storageTargets()['app_real_path'];

        if ($real !== null) {
            $parent = basename(dirname($real));

            if ($parent !== '' && $parent !== '.' && $parent !== '\\' && $parent !== '/') {
                return $parent;
            }
        }

        return (string) config('storage.data_folder', 'oleochemical-data');
    }

    /**
     * Periksa kelayakan (mode plan) atau jalankan pemindahan data MySQL.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function relocateDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'db_drive' => ['required', 'string', 'size:1', 'alpha'],
            'db_folder' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'mode'     => ['required', 'in:plan,force'],
            'keep_old' => ['nullable', 'in:1'],
        ], [
            'db_drive.size'  => 'Pilih drive tujuan terlebih dahulu.',
            'db_folder.regex' => 'Nama folder hanya boleh berisi huruf, angka, spasi, titik, garis bawah, atau tanda hubung.',
        ]);

        $drive = strtoupper((string) $data['db_drive']);
        $folder = rtrim(trim((string) $data['db_folder']), '. ');

        if ($data['mode'] === 'plan') {
            $plan = $this->mysql->plan($drive, $folder);

            return back()
                ->withInput()
                ->with('database_plan', $plan)
                ->with('storage_status', $plan['ok'] ? 'ok' : 'fail')
                ->with('storage_title', 'Hasil pemeriksaan kelayakan pemindahan data MySQL');
        }

        // Pemindahan menyalin seluruh berkas data MySQL (bisa beberapa menit),
        // jadi batas waktu eksekusi PHP dilepas khusus untuk proses ini.
        @set_time_limit(0);

        $log = [];
        $result = $this->mysql->relocate(
            $drive,
            $folder,
            !$request->boolean('keep_old'),
            function (string $line) use (&$log): void {
                $log[] = $line;
            }
        );

        $this->storage->report(true); // segarkan cache laporan penyimpanan

        return back()
            ->with('storage_log', $log)
            ->with('storage_status', $result['success'] ? 'ok' : 'fail')
            ->with('storage_title', $result['success']
                ? 'Data MySQL berhasil dipindahkan'
                : 'Pemindahan data MySQL gagal')
            ->with('storage_error', $result['error'] ?? null);
    }

    /**
     * Status OPcache pada SAPI web untuk dipantau di panel penyimpanan.
     *
     * @return array<string, mixed>
     */
    private function opcacheStatus(): array
    {
        if (!function_exists('opcache_get_status')) {
            return [
                'available'      => false,
                'enabled'        => false,
                'cached_scripts' => 0,
                'hits'           => 0,
                'memory_used'    => 0,
                'memory_free'    => 0,
                'sapi'           => PHP_SAPI,
            ];
        }

        $status = opcache_get_status(false);

        return [
            'available'      => true,
            'enabled'        => (bool) ($status['opcache_enabled'] ?? false),
            'cached_scripts' => (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0),
            'hits'           => (int) ($status['opcache_statistics']['hits'] ?? 0),
            'memory_used'    => (int) ($status['memory_usage']['used_memory'] ?? 0),
            'memory_free'    => (int) ($status['memory_usage']['free_memory'] ?? 0),
            'sapi'           => PHP_SAPI,
        ];
    }
}
