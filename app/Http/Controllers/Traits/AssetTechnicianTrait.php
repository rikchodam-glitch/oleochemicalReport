<?php

namespace App\Http\Controllers\Traits;

use App\Models\Asset;
use App\Models\Technician;
use App\Services\TelegramService;
use Illuminate\Http\Request;

/**
 * Trait AssetTechnicianTrait
 *
 * Berisi semua method yang mengurus penugasan dan komunikasi teknisi
 * terhadap sebuah asset. Dikelompokkan bersama karena semuanya berputar
 * di sekitar relasi pivot asset_technician dan pengiriman notifikasi Telegram.
 *
 * Method yang ada:
 *   - getAssignedTechnicians()   : Daftar semua teknisi aktif + status penugasan ke asset
 *   - assignTechnician()         : Tambahkan teknisi ke asset + kirim notifikasi Telegram
 *   - removeTechnician()         : Lepas teknisi dari asset
 *   - broadcastToTechnicians()   : Kirim pesan massal ke teknisi yang ditugaskan
 *   - listTechnicians()          : Daftar teknisi yang sudah ditugaskan ke asset (JSON)
 */
trait AssetTechnicianTrait
{
    /**
     * Kembalikan semua teknisi aktif beserta status penugasan ke asset ini.
     * Dipakai untuk mengisi dropdown penugasan di halaman detail asset.
     *
     * @param  Asset  $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAssignedTechnicians(Asset $asset)
    {
        $technicians = Technician::where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function ($t) use ($asset) {
                $pivot            = $asset->technicians->find($t->id)?->pivot;
                $t->is_assigned   = !is_null($pivot);
                $t->pivot_note    = $pivot?->note;
                $t->pivot_assigned_at = $pivot?->assigned_at;
                return $t;
            });

        return response()->json($technicians);
    }

    /**
     * Tambahkan teknisi ke asset. Jika teknisi memiliki Telegram aktif,
     * kirimkan notifikasi penugasan secara otomatis.
     *
     * @param  Request         $request
     * @param  Asset           $asset
     * @param  TelegramService $telegram
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignTechnician(Request $request, Asset $asset, TelegramService $telegram)
    {
        $validated = $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'note'          => 'nullable|string|max:500',
        ]);

        $technician = Technician::findOrFail($validated['technician_id']);

        $asset->technicians()->syncWithoutDetaching([
            $technician->id => [
                'note'        => $validated['note'] ?? null,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
            ],
        ]);

        // Kirim notifikasi Telegram jika teknisi memiliki akun aktif
        $broadcasted = false;
        if ($technician->telegram_id && $technician->status === 'active') {
            $assetInfo = $telegram->formatAssetInfo($asset);
            $message   = "Anda ditugaskan untuk menangani asset berikut:\n\n{$assetInfo}\n\n";
            if ($validated['note']) {
                $message .= "📝 <b>Catatan:</b> " . e($validated['note']);
            }
            $broadcasted = $telegram->sendMessage($technician->telegram_id, $message);
        }

        return response()->json([
            'success'      => true,
            'message'      => 'Teknisi berhasil ditambahkan.',
            'broadcasted'  => $broadcasted,
            'technician'   => [
                'id'                 => $technician->id,
                'name'               => $technician->name,
                'nik'                => $technician->nik,
                'telegram_username'  => $technician->telegram_username,
            ],
        ]);
    }

    /**
     * Lepas teknisi dari asset (detach dari pivot asset_technician).
     *
     * @param  Asset      $asset
     * @param  Technician $technician
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeTechnician(Asset $asset, Technician $technician)
    {
        $asset->technicians()->detach($technician->id);

        return response()->json([
            'success' => true,
            'message' => 'Teknisi berhasil dihapus dari asset ini.',
        ]);
    }

    /**
     * Kirim pesan massal ke sejumlah teknisi yang dipilih via Telegram.
     *
     * @param  Request         $request
     * @param  Asset           $asset
     * @param  TelegramService $telegram
     * @return \Illuminate\Http\JsonResponse
     */
    public function broadcastToTechnicians(Request $request, Asset $asset, TelegramService $telegram)
    {
        $validated = $request->validate([
            'message'          => 'required|string',
            'technician_ids'   => 'required|array',
            'technician_ids.*' => 'exists:technicians,id',
        ]);

        $assetInfo = $telegram->formatAssetInfo($asset);
        $results   = $telegram->broadcastToTechnicians(
            $validated['technician_ids'],
            $validated['message'],
            $assetInfo
        );

        return response()->json([
            'success' => true,
            'message' => "Broadcast selesai. Terkirim: {$results['sent']}, Gagal: {$results['failed']} dari {$results['total']} teknisi.",
            'results' => $results,
        ]);
    }

    /**
     * Kembalikan daftar teknisi yang sudah ditugaskan ke asset ini (JSON).
     * Dipakai untuk menampilkan daftar di halaman detail asset.
     *
     * @param  Asset  $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTechnicians(Asset $asset)
    {
        $asset->load('technicians');

        return response()->json($asset->technicians->map(function ($t) {
            return [
                'id'                 => $t->id,
                'name'               => $t->name,
                'nik'                => $t->nik,
                'telegram_username'  => $t->telegram_username,
                'has_telegram'       => !is_null($t->telegram_id),
                'note'               => $t->pivot->note,
                'assigned_at'        => $t->pivot->assigned_at?->diffForHumans(),
            ];
        }));
    }
}
