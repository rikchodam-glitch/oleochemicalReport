<?php

namespace App\Http\Controllers;

use App\Models\ItAsset;
use App\Models\ItServiceRecord;
use App\Services\ItServiceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman Service IT: kasus perangkat IT yang berasal dari laporan
 * continue/completed maupun entri manual, lengkap dengan catatan bebas.
 */
class ItServiceController extends Controller
{
    /**
     * Label tipe perangkat untuk filter dan tampilan pilihan aset.
     *
     * @var array<string, string>
     */
    private const DEVICE_LABELS = [
        'PC'                   => 'PC',
        'LAPTOP'               => 'Laptop',
        'PRINTER'              => 'Printer',
        'CCTV'                 => 'CCTV',
        'NVR'                  => 'NVR',
        'ACCESS_DOOR'          => 'Access Door',
        'WIFI'                 => 'WiFi',
        'NETWORK'              => 'Network',
        'SWITCH_MANAGED'       => 'Switch Managed',
        'SWITCH_POE_UNMANAGED' => 'Switch PoE Unmanaged',
        'ACCESS_POINT'         => 'Access Point',
        'HUB'                  => 'HUB',
        'UPS'                  => 'UPS',
        'OTHER'                => 'Lainnya',
    ];

    /**
     * @param  ItServiceSyncService  $sync  Penyelaras kasus dari laporan
     */
    public function __construct(private ItServiceSyncService $sync)
    {
    }

    /**
     * Tampilkan daftar kasus Service IT.
     *
     * @param  Request  $request  Filter status/tipe/pencarian
     * @return View
     */
    public function index(Request $request): View
    {
        // Selaraskan dulu dengan laporan continue/completed yang ada.
        $this->sync->sinkronkanSemua();

        $status = $request->string('status')->toString();

        if (! in_array($status, ['aktif', 'proses', 'rusak', 'selesai', 'semua'], true)) {
            $status = 'aktif';
        }

        $query = ItServiceRecord::query()
            ->with(['itAsset.functionalLocation', 'report']);

        if ($status === 'aktif') {
            $query->aktif();
        } elseif ($status !== 'semua') {
            $query->where('status', $status);
        }

        if ($request->filled('device_type')) {
            $tipe = $request->string('device_type')->toString();

            $query->whereHas('itAsset', fn ($sub) => $sub->where('device_type', $tipe));
        }

        if ($request->filled('search')) {
            $cari = $request->string('search')->toString();

            $query->whereHas('itAsset', function ($sub) use ($cari): void {
                $sub->where('tag_name', 'like', "%{$cari}%")
                    ->orWhere('name', 'like', "%{$cari}%")
                    ->orWhere('ip_address', 'like', "%{$cari}%")
                    ->orWhere('serial_number', 'like', "%{$cari}%");
            });
        }

        $records = $query
            ->orderByRaw("CASE status WHEN 'proses' THEN 0 WHEN 'rusak' THEN 1 ELSE 2 END")
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $ringkasan = [
            'proses'  => ItServiceRecord::where('status', ItServiceRecord::STATUS_PROSES)->count(),
            'rusak'   => ItServiceRecord::where('status', ItServiceRecord::STATUS_RUSAK)->count(),
            'selesai' => ItServiceRecord::where('status', ItServiceRecord::STATUS_SELESAI)->count(),
        ];

        return view('it-service.index', [
            'records'       => $records,
            'ringkasan'     => $ringkasan,
            'statusFilter'  => $status,
            'deviceTypes'   => self::DEVICE_LABELS,
            'statusStyles'  => [
                ItServiceRecord::STATUS_PROSES  => 'bg-amber-50 text-amber-700 border-amber-200',
                ItServiceRecord::STATUS_RUSAK   => 'bg-red-50 text-red-700 border-red-200',
                ItServiceRecord::STATUS_SELESAI => 'bg-green-50 text-green-700 border-green-200',
            ],
        ]);
    }

    /**
     * Daftar perangkat IT untuk modal tambah manual.
     *
     * Perangkat yang masih punya kasus terbuka (belum ada tanggal selesai)
     * tidak ditawarkan supaya tidak terjadi kasus ganda.
     *
     * @return JsonResponse
     */
    public function assetSearch(): JsonResponse
    {
        $assets = ItAsset::query()
            ->with('functionalLocation')
            ->whereDoesntHave('serviceRecords', fn ($sub) => $sub->whereNull('finish_date'))
            ->orderBy('device_type')
            ->orderBy('tag_name')
            ->get()
            ->map(function (ItAsset $asset): array {
                $merek = trim(implode(' ', array_filter([$asset->brand, $asset->model])));
                $detail = implode(' · ', array_filter([
                    $asset->ip_address,
                    $merek,
                    $asset->serial_number,
                ]));

                return [
                    'id'          => $asset->id,
                    'label'       => $asset->displayName(),
                    'nama'        => $asset->name,
                    'tipe'        => $asset->deviceLabel(),
                    'device_type' => $asset->device_type,
                    'lokasi'      => $asset->locationLabel(),
                    'detail'      => $detail,
                    'cari'        => mb_strtolower(implode(' ', array_filter([
                        $asset->tag_name,
                        $asset->name,
                        $asset->ip_address,
                        $asset->serial_number,
                        $asset->brand,
                        $asset->model,
                        $asset->locationLabel(),
                    ]))),
                ];
            })
            ->sortBy(fn (array $baris): string => $baris['lokasi'] . '|' . $baris['tipe'] . '|' . $baris['label'])
            ->values();

        return response()->json(['assets' => $assets]);
    }

    /**
     * Simpan kasus Service IT manual.
     *
     * @param  Request  $request  Isian kasus manual
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'it_asset_id' => 'required|integer|exists:it_assets,id',
            'entry_date'  => 'required|date',
            'finish_date' => 'nullable|date|after_or_equal:entry_date',
            'status'      => 'required|in:proses,selesai,rusak',
            'note'        => 'nullable|string|max:2000',
        ], [
            'it_asset_id.required' => 'Pilih perangkat IT terlebih dahulu.',
            'entry_date.required'  => 'Tanggal masuk wajib diisi.',
            'finish_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal masuk.',
        ]);

        $itAssetId = (int) $request->input('it_asset_id');
        $status    = (string) $request->input('status');
        $finish    = $request->input('finish_date');

        // Tanggal selesai yang diisi otomatis menuntaskan kasus.
        if ($status === ItServiceRecord::STATUS_PROSES && filled($finish)) {
            $status = ItServiceRecord::STATUS_SELESAI;
        }

        if ($status !== ItServiceRecord::STATUS_PROSES && blank($finish)) {
            return back()
                ->withErrors(['finish_date' => 'Tanggal selesai wajib diisi untuk status selesai atau rusak.'])
                ->withInput();
        }

        if ($status === ItServiceRecord::STATUS_PROSES) {
            $finish = null;
        }

        $adaTerbuka = ItServiceRecord::query()
            ->where('it_asset_id', $itAssetId)
            ->terbuka()
            ->exists();

        if ($adaTerbuka) {
            return back()
                ->withErrors(['it_asset_id' => 'Perangkat ini masih punya kasus Service IT yang belum selesai.'])
                ->withInput();
        }

        ItServiceRecord::create([
            'it_asset_id' => $itAssetId,
            'source'      => ItServiceRecord::SOURCE_MANUAL,
            'entry_date'  => $request->input('entry_date'),
            'finish_date' => $finish,
            'status'      => $status,
            'note'        => $request->input('note'),
            'created_by'  => $request->user()?->id,
        ]);

        return redirect()
            ->route('it-service.index')
            ->with('success', 'Kasus Service IT berhasil ditambahkan.');
    }

    /**
     * Perbarui catatan, tanggal, atau status satu kasus Service IT.
     *
     * Kasus manual boleh diubah penuh. Kasus dari laporan hanya boleh diubah
     * catatannya, dan kasus proses boleh ditandai rusak dengan tanggal selesai.
     *
     * @param  Request  $request  Isian perubahan
     * @param  ItServiceRecord  $itService  Kasus yang diubah
     * @return RedirectResponse
     */
    public function update(Request $request, ItServiceRecord $itService): RedirectResponse
    {
        $request->validate([
            'entry_date'  => 'nullable|date',
            'finish_date' => 'nullable|date',
            'status'      => 'required|in:proses,selesai,rusak',
            'note'        => 'nullable|string|max:2000',
        ]);

        $status = (string) $request->input('status');
        $note   = $request->input('note');

        if ($itService->source === ItServiceRecord::SOURCE_MANUAL) {
            $entry  = $request->input('entry_date') ?: $itService->entry_date->toDateString();
            $finish = $request->input('finish_date');

            if ($finish !== null && $finish < $entry) {
                return back()->withErrors(['finish_date' => 'Tanggal selesai tidak boleh sebelum tanggal masuk.'])->withInput();
            }

            if ($status === ItServiceRecord::STATUS_PROSES && filled($finish)) {
                $status = ItServiceRecord::STATUS_SELESAI;
            }

            if ($status !== ItServiceRecord::STATUS_PROSES && blank($finish)) {
                return back()->withErrors(['finish_date' => 'Tanggal selesai wajib diisi untuk status selesai atau rusak.'])->withInput();
            }

            $itService->update([
                'entry_date'  => $entry,
                'finish_date' => $status === ItServiceRecord::STATUS_PROSES ? null : $finish,
                'status'      => $status,
                'note'        => $note,
            ]);

            return redirect()->route('it-service.index')->with('success', 'Kasus Service IT berhasil diperbarui.');
        }

        // Kasus dari laporan: tanggal masuk/selesai mengikuti laporan.
        if ($itService->status === ItServiceRecord::STATUS_PROSES) {
            if ($status === ItServiceRecord::STATUS_RUSAK) {
                $request->validate([
                    'finish_date' => 'required|date|after_or_equal:' . $itService->entry_date->toDateString(),
                ], [
                    'finish_date.required' => 'Tanggal selesai wajib diisi saat menandai rusak.',
                    'finish_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal masuk.',
                ]);

                $itService->update([
                    'status'      => ItServiceRecord::STATUS_RUSAK,
                    'finish_date' => $request->input('finish_date'),
                    'note'        => $note,
                ]);
            } elseif ($status === ItServiceRecord::STATUS_PROSES) {
                $itService->update(['note' => $note]);
            } else {
                return back()->withErrors(['status' => 'Status selesai hanya diisi otomatis dari laporan done.'])->withInput();
            }

            return redirect()->route('it-service.index')->with('success', 'Kasus Service IT berhasil diperbarui.');
        }

        if ($itService->status === ItServiceRecord::STATUS_RUSAK && $status === ItServiceRecord::STATUS_RUSAK) {
            $request->validate([
                'finish_date' => 'required|date|after_or_equal:' . $itService->entry_date->toDateString(),
            ], [
                'finish_date.required' => 'Tanggal selesai wajib diisi untuk status rusak.',
                'finish_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal masuk.',
            ]);

            $itService->update([
                'finish_date' => $request->input('finish_date'),
                'note'        => $note,
            ]);

            return redirect()->route('it-service.index')->with('success', 'Kasus Service IT berhasil diperbarui.');
        }

        if ($itService->status === ItServiceRecord::STATUS_SELESAI && $status === ItServiceRecord::STATUS_SELESAI) {
            $itService->update(['note' => $note]);

            return redirect()->route('it-service.index')->with('success', 'Catatan Service IT berhasil diperbarui.');
        }

        return back()->withErrors(['status' => 'Status kasus ini tidak dapat diubah dari halaman Service IT.'])->withInput();
    }
}
