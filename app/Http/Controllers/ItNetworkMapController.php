<?php

namespace App\Http\Controllers;

use App\Models\ItAsset;
use App\Models\NetworkLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ItNetworkMapController extends Controller
{
    /**
     * Warna marker per device_type (dipakai legenda di view peta).
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
        'WIFI'        => '#06b6d4',
        'NETWORK'     => '#6366f1',
        'UPS'         => '#84cc16',
        'OTHER'       => '#64748b',
    ];

    /**
     * Tampilkan peta jaringan IT Assets beserta daftar perangkat active dan
     * seluruh jalur yang sudah dibuat.
     *
     * @return View
     */
    public function index(): View
    {
        $assets = ItAsset::where('status', 'active')
            ->orderBy('device_type')
            ->orderBy('tag_name')
            ->get()
            ->map(fn (ItAsset $asset) => $this->serializeAsset($asset));

        $links = NetworkLink::with(['fromAsset', 'toAsset'])
            ->get()
            ->map(fn (NetworkLink $link) => $this->serializeLink($link));

        return view('it-assets.network-map', [
            'assets'           => $assets,
            'links'            => $links,
            'deviceTypeColors' => self::DEVICE_TYPE_COLORS,
        ]);
    }

    /**
     * Simpan koordinat lokasi sebuah perangkat IT (diisi admin lewat klik
     * pada peta). Memperbarui kolom latitude, longitude, dan location_set_at.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_id'  => ['required', 'integer', 'exists:it_assets,id'],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $asset = ItAsset::findOrFail($validated['asset_id']);
        $asset->update([
            'latitude'        => $validated['latitude'],
            'longitude'       => $validated['longitude'],
            'location_set_at' => now(),
        ]);

        return response()->json([
            'message' => 'Lokasi perangkat berhasil disimpan.',
            'asset'   => $this->serializeAsset($asset->fresh()),
        ]);
    }

    /**
     * Buat jalur baru antara dua perangkat IT (dengan jenis kabel dan titik
     * belokan opsional).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function storeLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_asset_id'      => ['required', 'integer', 'exists:it_assets,id'],
            'to_asset_id'        => ['required', 'integer', 'exists:it_assets,id', 'different:from_asset_id'],
            'cable_type'         => ['required', 'string', 'in:fiber_optic,utp,wireless,coaxial,other'],
            'label'              => ['nullable', 'string', 'max:255'],
            'path_points'        => ['nullable', 'array'],
            'path_points.*.lat'  => ['required_with:path_points', 'numeric'],
            'path_points.*.lng'  => ['required_with:path_points', 'numeric'],
        ]);

        $link = NetworkLink::create([
            'from_asset_id' => $validated['from_asset_id'],
            'to_asset_id'   => $validated['to_asset_id'],
            'cable_type'    => $validated['cable_type'],
            'label'         => $validated['label'] ?? null,
            'path_points'   => $validated['path_points'] ?? null,
        ]);

        $link->load(['fromAsset', 'toAsset']);

        return response()->json([
            'message' => 'Jalur berhasil disimpan.',
            'link'    => $this->serializeLink($link),
        ]);
    }

    /**
     * Hapus sebuah jalur (link) antar perangkat.
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
     * Ubah model ItAsset menjadi array siap JSON untuk dikirim ke view peta.
     * Hanya kolom yang aman dan relevan untuk peta yang disertakan; URL
     * snapshot dibangun khusus untuk perangkat CCTV/NVR (disk cctv).
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
            'latitude'         => $asset->latitude !== null ? (float) $asset->latitude : null,
            'longitude'        => $asset->longitude !== null ? (float) $asset->longitude : null,
            'last_ping_status' => $asset->last_ping_status,
            'last_ping_at'     => $asset->last_ping_at?->toIso8601String(),
            'last_snapshot_at' => $asset->last_snapshot_at?->toIso8601String(),
            'snapshot_url'     => $asset->supportsSnapshot() && $asset->last_snapshot_path
                ? Storage::disk(config('cctv.snapshot_disk'))->url($asset->last_snapshot_path)
                : null,
        ];
    }

    /**
     * Ubah model NetworkLink menjadi array siap JSON untuk view peta.
     *
     * @param  NetworkLink  $link
     * @return array<string, mixed>
     */
    private function serializeLink(NetworkLink $link): array
    {
        return [
            'id'            => $link->id,
            'from_asset_id' => $link->from_asset_id,
            'to_asset_id'   => $link->to_asset_id,
            'from_tag'      => $link->fromAsset?->displayName(),
            'from_type'     => $link->fromAsset?->device_type,
            'to_tag'        => $link->toAsset?->displayName(),
            'to_type'       => $link->toAsset?->device_type,
            'cable_type'    => $link->cable_type,
            'label'         => $link->label,
            'path_points'   => $link->path_points,
        ];
    }
}
