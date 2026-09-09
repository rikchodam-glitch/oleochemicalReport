<?php

namespace App\Http\Controllers;

use App\Models\ItAsset;
use App\Models\ItNotification;
use App\Models\ItPingLog;
use App\Services\CctvSnapshotService;
use App\Services\ItAssetImportService;
use App\Services\ItPingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ItAssetController extends Controller
{
    /**
     * Daftar device_type yang dikenal modul ini, dipakai untuk dropdown
     * filter dan urutan summary card di halaman index.
     *
     * @var array<int, string>
     */
    private const DEVICE_TYPES = [
        'PC', 'LAPTOP', 'PRINTER', 'CCTV', 'NVR', 'ACCESS_DOOR',
        'WIFI', 'NETWORK', 'SWITCH_MANAGED', 'SWITCH_POE_UNMANAGED',
        'ACCESS_POINT', 'HUB', 'UPS', 'OTHER',
    ];

    protected ItAssetImportService $importService;

    protected ItPingService $pingService;

    protected CctvSnapshotService $snapshotService;

    public function __construct(
        ItAssetImportService $importService,
        ItPingService $pingService,
        CctvSnapshotService $snapshotService
    ) {
        $this->importService = $importService;
        $this->pingService = $pingService;
        $this->snapshotService = $snapshotService;
    }

    /**
     * Tampilkan daftar perangkat IT dengan filter device_type/status,
     * pencarian teks (tag/name/IP), dan ringkasan jumlah per device_type.
     *
     * @param  Request  $request
     * @return View
     */
    public function index(Request $request): View
    {
        $query = ItAsset::query();

        // Simpan filter daftar yang sedang aktif di session, supaya setelah
        // aksi tambah/edit/hapus user bisa kembali ke daftar dengan filter
        // yang sama (misal tetap Tipe=CCTV) tanpa harus memilih ulang.
        $listQuery = array_filter([
            'device_type' => $request->query('device_type'),
            'status'      => $request->query('status'),
            'search'      => $request->query('search'),
        ], fn ($value) => $value !== null && $value !== '');

        if ($listQuery === []) {
            session()->forget('it_assets.list_query');
        } else {
            session()->put('it_assets.list_query', $listQuery);
        }

        if ($request->filled('device_type')) {
            $query->ofType($request->string('device_type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($sub) use ($search) {
                $sub->where('tag_name', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        // Mode tampilan: 'layout' (grid + snapshot per PT, collapsible) hanya
        // saat filter Tipe = CCTV; selain itu memakai tabel.
        $viewMode = $request->string('device_type')->toString() === 'CCTV' ? 'layout' : 'table';

        if ($viewMode === 'layout') {
            // Mode layout CCTV: tampilkan SEMUA kamera yang cocok, dikelompokkan
            // per PT (plant) lalu per "Connect To" (lokasi switch). Paginasi
            // tidak dipakai di mode ini karena tujuannya melihat keseluruhan.
            $cctvGroups = (clone $query)
                ->ofType('CCTV')
                ->orderBy('plant')
                ->orderBy('tag_name')
                ->get()
                ->groupBy(fn (ItAsset $asset) => $asset->plant ?: 'Tanpa PT')
                ->map(fn ($plantGroup) => $plantGroup->groupBy(
                    fn (ItAsset $asset) => ($asset->spec['connect_to'] ?? null) ?: 'Tanpa Switch'
                ));

            $itAssets = collect();
        } else {
            $itAssets = $query->orderBy('device_type')
                ->orderBy('tag_name')
                ->paginate(20)
                ->withQueryString();

            $cctvGroups = collect();
        }

        $summary = $this->buildDeviceTypeSummary();

        return view('it-assets.index', [
            'itAssets'                => $itAssets,
            'cctvGroups'              => $cctvGroups,
            'summary'                 => $summary,
            'deviceTypes'             => self::DEVICE_TYPES,
            'deviceTypeLabels'        => $this->deviceTypeLabels(),
            'unreadNotificationCount' => ItNotification::unread()->count(),
            'viewMode'                => $viewMode,
        ]);
    }

    /**
     * Hitung jumlah perangkat per device_type untuk summary card.
     * device_type yang belum punya data tetap muncul dengan nilai 0 agar
     * urutan card konsisten.
     *
     * @return array<string, int>
     */
    private function buildDeviceTypeSummary(): array
    {
        $counts = ItAsset::query()
            ->selectRaw('device_type, count(*) as total')
            ->groupBy('device_type')
            ->pluck('total', 'device_type');

        $summary = [];

        foreach (self::DEVICE_TYPES as $type) {
            $summary[$type] = (int) ($counts[$type] ?? 0);
        }

        return $summary;
    }

    /**
     * Tampilkan detail satu perangkat IT: info lengkap, spec (JSON),
     * riwayat ping terbaru, dan panel CCTV Snapshot bila device_type
     * mendukungnya (CCTV/NVR).
     *
     * @param  ItAsset  $itAsset
     * @return View
     */
    public function show(ItAsset $itAsset): View
    {
        $recentPingLogs = $itAsset->pingLogs()
            ->orderByDesc('checked_at')
            ->limit(20)
            ->get();

        return view('it-assets.show', [
            'itAsset'         => $itAsset,
            'recentPingLogs'  => $recentPingLogs,
            'deviceTypeLabel' => $this->deviceTypeLabel($itAsset->device_type),
        ]);
    }

    /**
     * Label tampilan untuk satu device_type.
     *
     * @param  string  $type
     * @return string
     */
    private function deviceTypeLabels(): array
    {
        return [
            'PC'          => 'PC',
            'LAPTOP'      => 'Laptop',
            'PRINTER'     => 'Printer',
            'CCTV'        => 'CCTV',
            'NVR'         => 'NVR',
            'ACCESS_DOOR' => 'Access Door',
            'WIFI'                  => 'WiFi',
            'NETWORK'               => 'Network',
            'SWITCH_MANAGED'        => 'Switch Managed',
            'SWITCH_POE_UNMANAGED'  => 'Switch PoE Unmanaged',
            'ACCESS_POINT'          => 'Access Point',
            'HUB'                   => 'HUB',
            'UPS'                   => 'UPS',
            'OTHER'                 => 'Lainnya',
        ];
    }

    private function deviceTypeLabel(string $type): string
    {
        return $this->deviceTypeLabels()[$type] ?? $type;
    }

    /**
     * Ping satu perangkat IT berdasarkan ip_address, simpan hasilnya ke
     * kolom last_ping_* pada it_assets dan catat riwayat ke it_ping_logs.
     *
     * @param  ItAsset  $itAsset
     * @return JsonResponse
     */
    public function ping(ItAsset $itAsset): JsonResponse
    {
        if (empty($itAsset->ip_address)) {
            return response()->json([
                'success' => false,
                'message' => 'Perangkat ini tidak memiliki IP address untuk di-ping.',
            ], 422);
        }

        $result = $this->pingService->ping($itAsset->ip_address);

        $itAsset->update([
            'last_ping_status'  => $result['status'],
            'last_ping_latency' => $result['latency_ms'],
            'last_ping_at'      => now(),
        ]);

        ItPingLog::create([
            'it_asset_id' => $itAsset->id,
            'status'      => $result['status'],
            'latency_ms'  => $result['latency_ms'],
            'checked_at'  => now(),
        ]);

        return response()->json([
            'success'    => true,
            'status'     => $result['status'],
            'latency_ms' => $result['latency_ms'],
        ]);
    }

    /**
     * Simpan/perbarui pengaturan sumber CCTV Snapshot (URL + kredensial)
     * untuk satu perangkat CCTV/NVR. Password hanya diperbarui bila diisi
     * ulang oleh admin — dikosongkan di form berarti "tidak diubah", BUKAN
     * "hapus password", supaya admin tidak perlu ketik ulang password
     * setiap kali hanya ingin ganti URL.
     *
     * @param  Request  $request
     * @param  ItAsset  $itAsset
     * @return RedirectResponse
     */
    public function updateSnapshotSettings(Request $request, ItAsset $itAsset): RedirectResponse
    {
        if (!$itAsset->supportsSnapshot()) {
            return redirect()
                ->route('it-assets.show', $itAsset)
                ->with('error', 'Perangkat tipe ini tidak mendukung fitur CCTV Snapshot.');
        }

        $validated = $request->validate([
            'snapshot_url'      => ['nullable', 'string', 'max:255', 'regex:/^(rtsp|https?):\/\//i'],
            'snapshot_username' => ['nullable', 'string', 'max:255'],
            'snapshot_password' => ['nullable', 'string', 'max:255'],
        ], [
            'snapshot_url.regex' => 'URL harus diawali rtsp://, http://, atau https://.',
        ]);

        $payload = [
            'snapshot_url'      => $validated['snapshot_url'] ?? null,
            'snapshot_username' => $validated['snapshot_username'] ?? null,
        ];

        // Hanya timpa password bila admin mengisi field-nya. Ini kolom
        // 'encrypted' cast di model — Eloquent otomatis meng-enkripsi
        // sebelum disimpan.
        if (!empty($validated['snapshot_password'])) {
            $payload['snapshot_password'] = $validated['snapshot_password'];
        }

        $itAsset->update($payload);

        return redirect()
            ->route('it-assets.show', $itAsset)
            ->with('success', 'Pengaturan snapshot berhasil disimpan.');
    }

    /**
     * Ambil snapshot terbaru dari kamera lewat CctvSnapshotService dan
     * simpan hasilnya (path + waktu, atau pesan error) ke kolom
     * last_snapshot_* pada it_assets.
     *
     * @param  ItAsset  $itAsset
     * @return JsonResponse
     */
    public function snapshot(ItAsset $itAsset): JsonResponse
    {
        if (!$itAsset->supportsSnapshot()) {
            return response()->json([
                'success' => false,
                'message' => 'Perangkat tipe ini tidak mendukung fitur CCTV Snapshot.',
            ], 422);
        }

        if (empty($itAsset->snapshot_url)) {
            return response()->json([
                'success' => false,
                'message' => 'Isi dulu URL snapshot di pengaturan perangkat ini.',
            ], 422);
        }

        $result = $this->snapshotService->capture($itAsset);

        if (!$result['success']) {
            $itAsset->update(['last_snapshot_error' => $result['error']]);

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Gagal mengambil snapshot.',
            ], 422);
        }

        $itAsset->update([
            'last_snapshot_path'   => $result['path'],
            'last_snapshot_at'     => now(),
            'last_snapshot_error'  => null,
        ]);

        return response()->json([
            'success'       => true,
            'snapshot_url'  => \Illuminate\Support\Facades\Storage::disk(config('cctv.snapshot_disk'))->url($result['path']),
            'captured_at'   => $itAsset->last_snapshot_at->format('d/m/Y H:i:s'),
        ]);
    }

    /**
     * Tampilkan form tambah perangkat IT manual.
     *
     * @return View
     */
    public function create(): View
    {
        return view('it-assets.form', [
            'itAsset'          => new ItAsset(['status' => 'active', 'device_type' => 'PC']),
            'deviceTypes'      => self::DEVICE_TYPES,
            'deviceTypeLabels' => $this->deviceTypeLabels(),
        ]);
    }

    /**
     * Simpan perangkat IT baru hasil input manual.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateForm($request);
        $validated['data_source'] = 'manual';
        $validated['imported_at'] = now();

        ItAsset::create($validated);

        return $this->redirectToItAssetList()
            ->with('success', 'Perangkat IT berhasil ditambahkan.');
    }

    /**
     * Tampilkan form edit perangkat IT.
     *
     * @param  ItAsset  $itAsset
     * @return View
     */
    public function edit(ItAsset $itAsset): View
    {
        return view('it-assets.form', [
            'itAsset'          => $itAsset,
            'deviceTypes'      => self::DEVICE_TYPES,
            'deviceTypeLabels' => $this->deviceTypeLabels(),
        ]);
    }

    /**
     * Simpan perubahan data perangkat IT.
     *
     * @param  Request  $request
     * @param  ItAsset  $itAsset
     * @return RedirectResponse
     */
    public function update(Request $request, ItAsset $itAsset): RedirectResponse
    {
        $itAsset->update($this->validateForm($request));

        return $this->redirectToItAssetList()
            ->with('success', 'Perangkat IT berhasil diperbarui.');
    }

    /**
     * Hapus perangkat IT beserta riwayat ping, snapshot, dan notifikasinya
     * (cascade dari relasi foreign key).
     *
     * @param  ItAsset  $itAsset
     * @return RedirectResponse
     */
    public function destroy(ItAsset $itAsset): RedirectResponse
    {
        $itAsset->delete();

        return $this->redirectToItAssetList()
            ->with('success', 'Perangkat IT berhasil dihapus.');
    }

    /**
     * Redirect ke halaman daftar IT Assets sambil mempertahankan filter
     * terakhir (device_type/status/search) yang tersimpan saat user membuka
     * daftar. Jika tidak ada filter tersimpan, kembali ke daftar polos.
     *
     * @return RedirectResponse
     */
    private function redirectToItAssetList(): RedirectResponse
    {
        return redirect()->route('it-assets.index', session('it_assets.list_query', []));
    }

    /**
     * Validasi data form tambah/edit perangkat IT.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    private function validateForm(Request $request): array
    {
        $validated = $request->validate([
            'device_type'        => ['required', 'string', Rule::in(self::DEVICE_TYPES)],
            'tag_name'           => ['nullable', 'string', 'max:255'],
            'name'               => ['nullable', 'string', 'max:255'],
            'brand'              => ['nullable', 'string', 'max:255'],
            'model'              => ['nullable', 'string', 'max:255'],
            'serial_number'      => ['nullable', 'string', 'max:255'],
            'ip_address'         => ['nullable', 'ip'],
            'plant'              => ['nullable', 'string', 'max:255'],
            'department'         => ['nullable', 'string', 'max:255'],
            'location'           => ['nullable', 'string', 'max:255'],
            'application'        => ['nullable', 'string'],
            'status'             => ['required', 'string', Rule::in(['active', 'inactive', 'needs_review'])],
            'snapshot_url'       => ['nullable', 'string', 'max:255', 'regex:/^(rtsp|https?):\/\//i'],
            'snapshot_username'  => ['nullable', 'string', 'max:255'],
            'snapshot_password'  => ['nullable', 'string', 'max:255'],
            'spec'               => ['nullable', 'string'],
        ], [
            'snapshot_url.regex' => 'URL snapshot harus diawali rtsp://, http://, atau https://.',
            'ip_address.ip'      => 'Alamat IP tidak valid.',
        ]);

        // Kolom spec diisi sebagai JSON opsional di form; diparse ulang.
        $validated['spec'] = $this->parseSpecJson($validated['spec'] ?? null);

        // Password snapshot hanya diisi saat diinput ulang (mengikuti cast
        // 'encrypted' dan tidak menimpa yang lama saat diedit).
        if (empty($validated['snapshot_password'])) {
            unset($validated['snapshot_password']);
        }

        return $validated;
    }

    /**
     * Parse input JSON spesifikasi opsional dari form. Mengembalikan array
     * bila JSON valid, null bila kosong, atau melempar ValidationException
     * bila format JSON salah.
     *
     * @param  string|null  $value
     * @return array<string, mixed>|null
     */
    private function parseSpecJson(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            throw ValidationException::withMessages([
                'spec' => 'Spesifikasi harus berupa JSON yang valid.',
            ]);
        }

        return $decoded;
    }

    /**
     * Tampilkan halaman monitoring IT Assets: ringkasan status langsung dan
     * daftar perangkat yang perlu perhatian (offline, ping tinggi, error
     * snapshot), bukan daftar event notifikasi — agar lebih mudah dipantau.
     *
     * @param  Request  $request
     * @return View
     */
    public function notifications(Request $request): View
    {
        $threshold = (int) config('it.ping.high_latency_ms', 500);

        $assets = ItAsset::orderBy('device_type')->orderBy('tag_name')->get();

        $summary = [
            'total'          => $assets->count(),
            'online'         => $assets->where('last_ping_status', 'online')->count(),
            'offline'        => $assets->where('last_ping_status', 'offline')->count(),
            'not_checked'    => $assets->whereNull('last_ping_status')->count(),
            'high_latency'   => $assets->filter(fn ($a) => $a->last_ping_status === 'online' && $a->last_ping_latency !== null && $a->last_ping_latency >= $threshold)->count(),
            'snapshot_error' => $assets->filter(fn ($a) => in_array($a->device_type, ItAsset::SNAPSHOT_CAPABLE_TYPES, true) && !empty($a->last_snapshot_error))->count(),
        ];

        $issues = $assets
            ->filter(fn ($a) => $a->last_ping_status === 'offline'
                || ($a->last_ping_status === 'online' && $a->last_ping_latency !== null && $a->last_ping_latency >= $threshold)
                || (in_array($a->device_type, ItAsset::SNAPSHOT_CAPABLE_TYPES, true) && !empty($a->last_snapshot_error)))
            ->map(function ($a) use ($threshold) {
                $problems = [];

                if ($a->last_ping_status === 'offline') {
                    $problems[] = 'offline';
                } elseif ($a->last_ping_status === 'online' && $a->last_ping_latency !== null && $a->last_ping_latency >= $threshold) {
                    $problems[] = 'high_latency';
                }

                if (in_array($a->device_type, ItAsset::SNAPSHOT_CAPABLE_TYPES, true) && !empty($a->last_snapshot_error)) {
                    $problems[] = 'snapshot_error';
                }

                $severity = in_array('offline', $problems, true)
                    ? 3
                    : (in_array('high_latency', $problems, true) ? 2 : 1);

                return ['asset' => $a, 'problems' => $problems, 'severity' => $severity];
            })
            ->sortByDesc('severity')
            ->values();

        return view('it-assets.monitoring', [
            'summary'                 => $summary,
            'issues'                  => $issues,
            'deviceTypeLabels'        => $this->deviceTypeLabels(),
            'unreadNotificationCount' => ItNotification::unread()->count(),
        ]);
    }

    /**
     * Tandai satu notifikasi sudah dibaca.
     *
     * @param  ItNotification  $notification
     * @return RedirectResponse
     */
    public function markNotificationRead(ItNotification $notification): RedirectResponse
    {
        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return redirect()->route('it-assets.notifications');
    }

    /**
     * Tandai semua notifikasi sudah dibaca.
     *
     * @return RedirectResponse
     */
    public function markAllNotificationsRead(): RedirectResponse
    {
        ItNotification::unread()->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return redirect()->route('it-assets.notifications');
    }

    /**
     * Tampilkan form upload Excel untuk import IT Assets.
     *
     * @return View
     */
    public function showImport(): View
    {
        return view('it-assets.import');
    }

    /**
     * Proses upload file Excel dan jalankan import ke tabel it_assets lewat
     * ItAssetImportService. Sukses -> redirect ke daftar IT Assets dengan
     * ringkasan hasil. Gagal -> redirect kembali ke form import dengan
     * pesan error.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        try {
            ini_set('memory_limit', '512M');

            $summary = $this->importService->import($request->file('file'));

            return redirect()
                ->route('it-assets.index')
                ->with('success', $this->buildSummaryMessage($summary));
        } catch (\Exception $e) {
            return redirect()
                ->route('it-assets.import')
                ->with('error', 'Gagal import: ' . $e->getMessage());
        }
    }

    /**
     * Susun pesan ringkasan hasil import per sheet untuk ditampilkan lewat
     * flash message. Sheet yang tidak ditemukan di file Excel dilewati
     * dari pesan (bukan dianggap error).
     *
     * @param  array<string, array{total: int, inserted: int, updated: int, skipped: int, sheet_found: bool}>  $summary
     * @return string
     */
    private function buildSummaryMessage(array $summary): string
    {
        $parts = [];

        foreach ($summary as $deviceType => $result) {
            if (!$result['sheet_found']) {
                continue;
            }

            $parts[] = sprintf(
                '%s: %d baru, %d update, %d dilewati',
                $deviceType,
                $result['inserted'],
                $result['updated'],
                $result['skipped']
            );
        }

        if (empty($parts)) {
            return 'Import selesai, tapi tidak ada sheet yang cocok dengan format yang dikenali.';
        }

        return 'Import berhasil. ' . implode(' | ', $parts);
    }
}
