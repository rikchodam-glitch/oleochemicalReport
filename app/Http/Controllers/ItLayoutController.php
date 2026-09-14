<?php

namespace App\Http\Controllers;

use App\Models\ItAsset;
use App\Models\ItLayout;
use App\Models\ItLayoutItem;
use App\Models\NetworkLink;
use App\Services\LayoutRasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ItLayoutController extends Controller
{
    /**
     * Warna marker per device_type (dipakai legenda di view denah).
     *
     * @var array<string, string>
     */
    private const DEVICE_TYPE_COLORS = [
        'PC'          => '#3b82f6',
        'LAPTOP'      => '#8b5cf6',
        'PRINTER'     => '#f59e0b',
        'CCTV'        => '#ef4444',
        'NVR'         => '#ec4899',
        'ACCESS_DOOR' => '#10b981',
        'WIFI'                 => '#06b6d4',
        'NETWORK'              => '#6366f1',
        'SWITCH_MANAGED'       => '#0891b2',
        'SWITCH_POE_UNMANAGED' => '#e11d48',
        'ACCESS_POINT'         => '#9333ea',
        'HUB'                  => '#ea580c',
        'UPS'                  => '#84cc16',
        'OTHER'                => '#64748b',
    ];

    /**
     * Daftar seluruh denah (layout) perangkat IT.
     *
     * @return View
     */
    public function index(): View
    {
        $layouts = ItLayout::orderBy('sort_order')->orderBy('name')->get();

        return view('it-assets.layouts.index', [
            'layouts' => $layouts,
        ]);
    }

    /**
     * Tampilkan form tambah denah.
     *
     * @return View
     */
    public function create(): View
    {
        return view('it-assets.layouts.form');
    }

    /**
     * Simpan denah baru beserta file background (PDF/gambar) yang di-upload.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'label'       => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'page'        => ['nullable', 'integer', 'min:1'],
            'file'        => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:20480'],
        ]);

        $file = $request->file('file');
        $fileType = $file->getClientOriginalExtension() === 'pdf' ? 'pdf' : 'image';
        $path = $file->store('layouts', 'public');

        $width = null;
        $height = null;
        if ($fileType === 'image') {
            $dims = @getimagesize($file->getRealPath());
            if ($dims) {
                $width = $dims[0];
                $height = $dims[1];
            }
        }

        $maxSort = ItLayout::max('sort_order') ?? 0;

        $layout = ItLayout::create([
            'name'        => $validated['name'],
            'label'       => $validated['label'] ?? null,
            'description' => $validated['description'] ?? null,
            'file_path'   => $path,
            'file_type'   => $fileType,
            'page'        => $validated['page'] ?? 1,
            'width'       => $width,
            'height'      => $height,
            'sort_order'  => $maxSort + 1,
        ]);

        // Pra-render PDF menjadi gambar sekali agar denah terbuka cepat.
        app(LayoutRasterService::class)->generate($layout);

        return redirect()
            ->route('it-assets.layouts.index')
            ->with('success', 'Denah berhasil ditambahkan.');
    }

    /**
     * Tampilkan editor penempatan perangkat pada sebuah denah.
     *
     * @param  ItLayout  $layout
     * @return View
     */
    public function show(ItLayout $layout): View
    {
        $layout->load(['items.asset', 'links.fromAsset', 'links.toAsset']);

        $items = $layout->items->map(fn (ItLayoutItem $item) => $this->serializeItem($item));
        $links = $layout->links->map(fn (NetworkLink $link) => $this->serializeLink($link));

        $unplaced = ItAsset::where('status', 'active')
            ->whereDoesntHave('layoutItem')
            ->orderBy('device_type')
            ->orderBy('tag_name')
            ->get()
            ->map(fn (ItAsset $asset) => $this->serializeAsset($asset));

        // Bila PDF sudah dipra-render, tampilkan sebagai gambar (cepat) lewat
        // route ber-cache kuat; kalau belum, fallback ke file asli (PDF.js).
        $hasPreview = $layout->hasPreview();

        $layoutData = [
            'id'          => $layout->id,
            'name'        => $layout->name,
            'label'       => $layout->label,
            'description' => $layout->description,
            'file_type'   => $layout->displayFileType(),
            'file_url'    => $hasPreview
                ? route('it-assets.layouts.preview', $layout) . '?v=' . Storage::disk(config('denah.disk', 'public'))->lastModified($layout->previewRelativePath())
                : $layout->fileUrlRelative(),
            'page'        => $layout->page,
            'width'       => $layout->width,
            'height'      => $layout->height,
            'items'       => $items,
            'links'       => $links,
        ];

        return view('it-assets.layouts.show', [
            'layout'             => $layout,
            'layoutData'         => $layoutData,
            'unplaced'           => $unplaced,
            'deviceTypeColors'   => self::DEVICE_TYPE_COLORS,
            'allLayouts'         => ItLayout::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    /**
     * Sajikan gambar hasil pra-render denah dengan header cache kuat, supaya
     * browser tidak mengunduh ulang pada kunjungan berikutnya.
     *
     * @param  ItLayout  $layout
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function preview(ItLayout $layout)
    {
        if (!$layout->hasPreview()) {
            abort(404);
        }

        $path = Storage::disk(config('denah.disk', 'public'))->path($layout->previewRelativePath());

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    /**
     * Hapus sebuah denah beserta seluruh item dan jalurnya (cascade).
     *
     * @param  ItLayout  $layout
     * @return RedirectResponse
     */
    public function destroy(ItLayout $layout): RedirectResponse
    {
        $layout->delete();

        return redirect()
            ->route('it-assets.layouts.index')
            ->with('success', 'Denah berhasil dihapus.');
    }

    /**
     * Tempatkan (atau pindahkan) sebuah perangkat IT pada sebuah denah dengan
     * koordinat persen (x,y 0-100). Perangkat yang sudah terpasang di denah
     * LAIN ditolak (konsisten dengan aturan satu asset satu denah).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function placeAsset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'layout_id' => ['required', 'integer', 'exists:it_layouts,id'],
            'asset_id'  => ['required', 'integer', 'exists:it_assets,id'],
            'x'         => ['required', 'numeric', 'min:0', 'max:100'],
            'y'         => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $layout = ItLayout::findOrFail($validated['layout_id']);
        $asset = ItAsset::findOrFail($validated['asset_id']);

        $existing = ItLayoutItem::where('it_asset_id', $asset->id)->first();
        if ($existing && $existing->layout_id !== $layout->id) {
            return response()->json([
                'message' => 'Perangkat sudah terpasang di denah lain.',
            ], 422);
        }
        if ($existing && $existing->is_locked) {
            return response()->json([
                'message' => 'Perangkat terkunci. Buka kunci terlebih dahulu untuk mengubah posisi.',
            ], 422);
        }

        $item = ItLayoutItem::updateOrCreate(
            ['it_asset_id' => $asset->id],
            ['layout_id' => $layout->id, 'x' => $validated['x'], 'y' => $validated['y']]
        );

        $item->load('asset');

        return response()->json([
            'message' => 'Posisi perangkat tersimpan.',
            'item'    => $this->serializeItem($item),
        ]);
    }

    /**
     * Pindahkan posisi sebuah item (perangkat yang sudah terpasang) pada
     * denah. Tidak mengubah layout, hanya koordinat x/y.
     *
     * @param  ItLayoutItem  $item
     * @param  Request  $request
     * @return JsonResponse
     */
    public function moveAsset(ItLayoutItem $item, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'x' => ['required', 'numeric', 'min:0', 'max:100'],
            'y' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($item->is_locked) {
            return response()->json([
                'message' => 'Perangkat terkunci. Buka kunci terlebih dahulu untuk mengubah posisi.',
            ], 422);
        }

        $item->update([
            'x' => $validated['x'],
            'y' => $validated['y'],
        ]);

        $item->load('asset');

        return response()->json([
            'message' => 'Posisi diperbarui.',
            'item'    => $this->serializeItem($item),
        ]);
    }

    /**
     * Hapus penempatan sebuah perangkat dari sebuah denah (kembali ke daftar
     * belum ditempatkan). Jalur kabel yang melibatkan perangkat ini ikut
     * dihapus karena endpointnya tidak lagi berada di denah.
     *
     * @param  ItLayoutItem  $item
     * @return JsonResponse
     */
    public function removeAsset(ItLayoutItem $item): JsonResponse
    {
        if ($item->is_locked) {
            return response()->json([
                'message' => 'Perangkat terkunci. Buka kunci terlebih dahulu untuk menghapusnya.',
            ], 422);
        }

        $assetId = $item->it_asset_id;

        NetworkLink::where('from_asset_id', $assetId)
            ->orWhere('to_asset_id', $assetId)
            ->delete();

        $item->delete();

        return response()->json([
            'message' => 'Perangkat dikeluarkan dari denah.',
        ]);
    }

    /**
     * Kunci / buka kunci sebuah item pada denah. Saat terkunci, posisi dan
     * penghapusan ditolak sampai dibuka kembali.
     *
     * @param  ItLayoutItem  $item
     * @return JsonResponse
     */
    public function toggleLock(ItLayoutItem $item): JsonResponse
    {
        $item->update(['is_locked' => !$item->is_locked]);

        $item->load('asset');

        return response()->json([
            'message' => $item->is_locked ? 'Perangkat terkunci.' : 'Perangkat dibuka kuncinya.',
            'item'    => $this->serializeItem($item),
        ]);
    }

    /**
     * Buat jalur kabel antara dua perangkat yang keduanya sudah terpasang
     * pada denah yang sama. Path points opsional (titik belokan).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function storeLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'layout_id'    => ['required', 'integer', 'exists:it_layouts,id'],
            'from_asset_id' => ['required', 'integer', 'exists:it_assets,id'],
            'to_asset_id'   => ['required', 'integer', 'exists:it_assets,id', 'different:from_asset_id'],
            'cable_type'    => ['required', 'string', 'in:fiber_optic,utp,wireless,coaxial,other'],
            'label'         => ['nullable', 'string', 'max:255'],
        ]);

        $layoutId = $validated['layout_id'];

        $from = ItLayoutItem::where('layout_id', $layoutId)->where('it_asset_id', $validated['from_asset_id'])->exists();
        $to = ItLayoutItem::where('layout_id', $layoutId)->where('it_asset_id', $validated['to_asset_id'])->exists();

        if (!$from || !$to) {
            return response()->json([
                'message' => 'Kedua perangkat harus sudah terpasang di denah ini untuk membuat jalur.',
            ], 422);
        }

        $link = NetworkLink::create([
            'layout_id'    => $layoutId,
            'from_asset_id' => $validated['from_asset_id'],
            'to_asset_id'   => $validated['to_asset_id'],
            'cable_type'    => $validated['cable_type'],
            'label'         => $validated['label'] ?? null,
        ]);

        $link->load(['fromAsset', 'toAsset']);

        return response()->json([
            'message' => 'Jalur berhasil disimpan.',
            'link'    => $this->serializeLink($link),
        ]);
    }

    /**
     * Hapus sebuah jalur kabel.
     *
     * @param  NetworkLink  $networkLink
     * @return JsonResponse
     */
    public function destroyLink(NetworkLink $networkLink): JsonResponse
    {
        $networkLink->delete();

        return response()->json([
            'message' => 'Jalur berhasil dihapus.',
        ]);
    }

    /**
     * Ubah model ItLayoutItem menjadi array siap JSON untuk view denah.
     *
     * @param  ItLayoutItem  $item
     * @return array<string, mixed>
     */
    private function serializeItem(ItLayoutItem $item): array
    {
        return [
            'id'          => $item->id,
            'it_asset_id' => $item->it_asset_id,
            'x'           => $item->x,
            'y'           => $item->y,
            'is_locked'   => (bool) $item->is_locked,
            'asset'       => $this->serializeAsset($item->asset),
        ];
    }

    /**
     * Ubah model ItAsset menjadi array siap JSON (untuk daftar unplaced dan
     * marker). URL snapshot hanya untuk perangkat CCTV/NVR.
     *
     * @param  ItAsset  $asset
     * @return array<string, mixed>
     */
    private function serializeAsset(ItAsset $asset): array
    {
        return [
            'id'               => $asset->id,
            'device_type'      => $asset->device_type,
            'tag_name'         => $asset->tag_name,
            'name'             => $asset->name,
            'ip_address'       => $asset->ip_address,
            'plant'            => $asset->plant,
            'department'       => $asset->department,
            'location'         => $asset->location,
            'last_ping_status' => $asset->last_ping_status,
            'last_snapshot_at' => $asset->last_snapshot_at?->toIso8601String(),
            'snapshot_url'     => $asset->supportsSnapshot() && $asset->last_snapshot_path
                ? Storage::disk(config('cctv.snapshot_disk'))->url($asset->last_snapshot_path)
                : null,
            'spec_items'       => $asset->specDisplayItems(),
        ];
    }

    /**
     * Ubah model NetworkLink menjadi array siap JSON untuk view denah.
     *
     * @param  NetworkLink  $link
     * @return array<string, mixed>
     */
    private function serializeLink(NetworkLink $link): array
    {
        return [
            'id'            => $link->id,
            'layout_id'     => $link->layout_id,
            'from_asset_id' => $link->from_asset_id,
            'to_asset_id'   => $link->to_asset_id,
            'from_tag'      => $link->fromAsset?->displayName(),
            'to_tag'        => $link->toAsset?->displayName(),
            'cable_type'    => $link->cable_type,
            'label'         => $link->label,
            'path_points'   => $link->path_points,
        ];
    }
}


