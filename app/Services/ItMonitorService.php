<?php

namespace App\Services;

use App\Models\ItAsset;
use App\Models\ItNotification;
use App\Models\ItPingLog;

/**
 * ItMonitorService
 *
 * Pusat logika monitoring perangkat IT untuk pengecekan terjadwal:
 * melakukan ping, mencatat hasil, lalu membuat notifikasi dalam aplikasi
 * (tabel it_notifications) hanya pada peristiwa yang relevan supaya tidak
 * membanjiri user:
 *
 *   - offline        : perangkat tidak merespons ping
 *   - high_latency   : latency melebihi ambang konfigurasi (ping tinggi)
 *   - recovered      : perangkat yang tadinya offline kembali online
 *   - snapshot_error : gagal mengambil snapshot CCTV/NVR
 *
 * Duplikasi dicegah dengan jeda (cooldown) antar notifikasi sejenis per
 * perangkat (lihat config/it.php).
 */
class ItMonitorService
{
    public function __construct(
        protected ItPingService $pingService
    ) {
    }

    /**
     * Cek satu perangkat: ping, simpan hasil, dan buat notifikasi bila ada
     * peristiwa yang relevan. Perangkat tanpa IP diabaikan.
     *
     * @param  ItAsset  $asset
     * @return array{checked: bool, status: string|null, latency_ms: int|null, notified: array<int, string>}
     */
    public function checkAsset(ItAsset $asset): array
    {
        if (empty($asset->ip_address)) {
            return ['checked' => false, 'status' => null, 'latency_ms' => null, 'notified' => []];
        }

        $result = $this->pingService->ping($asset->ip_address);
        $previousStatus = $asset->last_ping_status;

        $asset->update([
            'last_ping_status'  => $result['status'],
            'last_ping_latency' => $result['latency_ms'],
            'last_ping_at'      => now(),
        ]);

        ItPingLog::create([
            'it_asset_id' => $asset->id,
            'status'      => $result['status'],
            'latency_ms'  => $result['latency_ms'],
            'checked_at'  => now(),
        ]);

        $notified = [];

        if ($result['status'] === 'offline') {
            $notified[] = $this->notifyIfDue($asset, 'offline', sprintf(
                'Perangkat %s (%s) tidak merespons ping.',
                $asset->deviceLabel(),
                $asset->displayName()
            ));
        } elseif ($result['status'] === 'online') {
            if ($previousStatus === 'offline') {
                $notified[] = $this->notifyIfDue($asset, 'recovered', sprintf(
                    'Perangkat %s (%s) kembali online.',
                    $asset->deviceLabel(),
                    $asset->displayName()
                ));
            }

            $threshold = (int) config('it.ping.high_latency_ms', 500);
            if ($result['latency_ms'] !== null && $result['latency_ms'] >= $threshold) {
                $notified[] = $this->notifyIfDue($asset, 'high_latency', sprintf(
                    'Ping tinggi: %s (%s) merespons dalam %d ms.',
                    $asset->deviceLabel(),
                    $asset->displayName(),
                    $result['latency_ms']
                ));
            }
        }

        return [
            'checked'    => true,
            'status'     => $result['status'],
            'latency_ms' => $result['latency_ms'],
            'notified'   => array_values(array_filter($notified)),
        ];
    }

    /**
     * Catat notifikasi error snapshot untuk satu perangkat (dipakai oleh
     * perintah it:snapshot).
     *
     * @param  ItAsset  $asset
     * @param  string   $error
     * @return bool  true bila notifikasi berhasil dibuat
     */
    public function notifySnapshotError(ItAsset $asset, string $error): bool
    {
        $message = sprintf(
            'Gagal mengambil snapshot %s (%s): %s',
            $asset->deviceLabel(),
            $asset->displayName(),
            $error
        );

        return $this->notifyIfDue($asset, 'snapshot_error', $message) !== null;
    }

    /**
     * Buat notifikasi bila belum ada notifikasi sejenis untuk perangkat
     * tersebut dalam jendela cooldown.
     *
     * @param  ItAsset  $asset
     * @param  string   $type
     * @param  string   $message
     * @return ItNotification|null
     */
    protected function notifyIfDue(ItAsset $asset, string $type, string $message): ?ItNotification
    {
        $cooldownMinutes = (int) config('it.notification.cooldown_minutes', 30);

        $recentExists = ItNotification::query()
            ->where('it_asset_id', $asset->id)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();

        if ($recentExists) {
            return null;
        }

        return ItNotification::create([
            'it_asset_id' => $asset->id,
            'type'        => $type,
            'message'     => $message,
            'is_read'     => false,
        ]);
    }
}
