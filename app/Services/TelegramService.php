<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $token;
    protected string $apiUrl;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token', '');
        $this->apiUrl = 'https://api.telegram.org/bot' . $this->token;
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    /**
     * Send a text message to a specific chat ID
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): bool
    {
        if (!$this->isConfigured()) {
            Log::info("[Telegram Mock] Would send to {$chatId}: {$text}");
            return false;
        }

        try {
            $response = Http::timeout(10)->post("{$this->apiUrl}/sendMessage", array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ], $extra));

            $success = $response->successful();

            if (!$success) {
                Log::warning("Telegram sendMessage failed to {$chatId}: " . $response->body());
            }

            return $success;
        } catch (\Exception $e) {
            Log::error("Telegram sendMessage exception to {$chatId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast a message to multiple technicians
     */
    public function broadcastToTechnicians(array $technicianIds, string $message, ?string $assetInfo = null): array
    {
        $technicians = \App\Models\Technician::whereIn('id', $technicianIds)
            ->where('status', 'active')
            ->whereNotNull('telegram_id')
            ->get();

        $results = [
            'sent' => 0,
            'failed' => 0,
            'total' => $technicians->count(),
            'details' => [],
        ];

        foreach ($technicians as $technician) {
            $fullMessage = $message;
            if ($assetInfo) {
                $fullMessage = $assetInfo . "\n\n" . $message;
            }

            $success = $this->sendMessage($technician->telegram_id, $fullMessage);

            if ($success) {
                $results['sent']++;
                $results['details'][] = [
                    'technician_id' => $technician->id,
                    'name' => $technician->name,
                    'status' => 'sent',
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'technician_id' => $technician->id,
                    'name' => $technician->name,
                    'status' => 'failed',
                ];
            }
        }

        return $results;
    }

    /**
     * Format asset info for broadcast
     */
    public function formatAssetInfo(\App\Models\Asset $asset): string
    {
        $lines = [];
        $lines[] = "🔧 <b>ASSET ASSIGNMENT</b>";
        $lines[] = "";
        $lines[] = "<b>Kode Alat:</b> " . ($asset->tech_ident_no ?? '-');
        if ($asset->equipment_no) {
            $lines[] = "<b>Equipment No:</b> " . $asset->equipment_no;
        }
        $lines[] = "<b>Deskripsi:</b> " . ($asset->description ?? '-');
        $lines[] = "<b>Lokasi:</b> " . ($asset->area?->code ?? '-') . " / " . ($asset->subArea?->code ?? '-');
        if ($asset->functional_loc) {
            $lines[] = "<b>Functional Loc:</b> " . $asset->functional_loc;
        }

        return implode("\n", $lines);
    }
}
