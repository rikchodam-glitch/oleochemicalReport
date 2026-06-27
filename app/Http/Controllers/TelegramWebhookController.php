<?php

namespace App\Http\Controllers;

use App\Services\AiService;
use App\Models\BotRegistration;
use App\Models\Report;
use App\Models\Technician;
use App\Models\BotUnknownAsset;
use App\Models\Asset;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function handle(Request $request)
    {
        try {
            $update = $request->all();

            if (isset($update['message'])) {
                $message = $update['message'];
                $chatId = $message['chat']['id'];
                $text = $message['text'] ?? '';
                $from = $message['from'] ?? [];
                $telegramId = $from['id'] ?? null;
                $username = $from['username'] ?? null;
                $firstName = $from['first_name'] ?? '';

                // Check if user is start command
                if (str_starts_with($text, '/start')) {
                    return $this->handleStart($chatId, $telegramId, $username, $firstName);
                }

                // Check if user is registered technician
                $technician = Technician::where('telegram_id', $telegramId)
                    ->where('status', 'active')
                    ->first();

                if (!$technician) {
                    return $this->sendMessage($chatId, 'Maaf, akun kamu belum terdaftar atau belum disetujui. Silakan hubungi admin.');
                }

                // Update last active
                $technician->update(['last_active_at' => now()]);

                // Handle the report
                return $this->handleReport($chatId, $technician, $text);
            }

            return response('OK');
        } catch (\Exception $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage());
            return response('OK');
        }
    }

    protected function handleStart($chatId, $telegramId, $username, $firstName)
    {
        // Check if already registered
        $existing = Technician::where('telegram_id', $telegramId)->first();
        if ($existing) {
            if ($existing->status === 'active') {
                return $this->sendMessage($chatId, "Halo $firstName! Akun kamu sudah aktif. Silakan kirim laporan harian.");
            }
            return $this->sendMessage($chatId, "Akun kamu masih menunggu persetujuan admin.");
        }

        // Check if already requested registration
        $pending = BotRegistration::where('telegram_id', $telegramId)
            ->where('status', 'pending')
            ->first();

        if ($pending) {
            return $this->sendMessage($chatId, "Pendaftaran kamu masih diproses. Silakan tunggu konfirmasi dari admin.");
        }

        // Create new registration
        BotRegistration::create([
            'telegram_id' => $telegramId,
            'telegram_username' => $username,
            'name' => $firstName,
            'status' => 'pending',
        ]);

        return $this->sendMessage(
            $chatId,
            "Halo $firstName! Untuk mendaftar sebagai teknisi, silakan kirim NIK kamu.\n\nContoh: NIK 123456"
        );
    }

    protected function handleReport($chatId, Technician $technician, string $text)
    {
        // Handle NIK registration follow-up
        if (preg_match('/^NIK\s+(\S+)$/i', $text, $matches)) {
            $nik = $matches[1];

            $registration = BotRegistration::where('telegram_id', $technician->telegram_id)
                ->latest()
                ->first();

            if ($registration && $registration->status === 'pending') {
                $registration->update(['nik' => $nik]);
                return $this->sendMessage(
                    $chatId,
                    "Terima kasih! NIK kamu ($nik) sudah dicatat. Admin akan memproses pendaftaran kamu segera."
                );
            }

            return $this->sendMessage($chatId, "Pendaftaran kamu sedang diproses atau sudah selesai.");
        }

        // PERBAIKAN: Hapus sesi klarifikasi yang mungkin masih tersisa dari pesan sebelumnya
        $clarification = app(\App\Services\Telegram\ClarificationService::class);
        $clarification->destroySession((string)$chatId);

        // Process as report
        $analysis = $this->aiService->analyzeReportText($text);

        $report = Report::create([
            'technician_id' => $technician->id,
            'report_date' => now()->toDateString(),
            'work_description' => $text,
            'report_type' => $analysis['report_type'] ?? 'general',
            'ai_analyzed' => true,
            'ai_confidence' => $analysis['confidence'] ?? 0,
            'ai_suggestion_json' => $analysis,
            'status' => 'draft',
        ]);

        // PERBAIKAN: TechIdentNo adalah kunci utama, bukan equipment_no
        // 1. Jika AI langsung mendeteksi equipment_id — paling akurat
        $equipmentId = $analysis['detected_equipment_id'] ?? null;
        if ($equipmentId) {
            $asset = Asset::find($equipmentId);
            if ($asset) {
                $report->update([
                    'asset_id' => $asset->id,
                    'area_id' => $asset->area_id,
                ]);
            }
        } else {
            // 2. Cari berdasarkan tech_ident_no
            $equipmentKey = $analysis['suggested_equipment'] ?? $analysis['detected_equipment'] ?? null;
            if ($equipmentKey) {
                $asset = Asset::where('tech_ident_no', $equipmentKey)->first();
                if ($asset) {
                    $report->update([
                        'asset_id' => $asset->id,
                        'area_id' => $asset->area_id,
                    ]);
                } else {
                    BotUnknownAsset::create([
                        'report_id' => $report->id,
                        'keyword_mentioned' => $equipmentKey,
                    ]);
                }
            }

            // 3. Area dari suggest (jika equipment tidak membawa area)
            if (!empty($analysis['suggested_area']) && !$report->area_id) {
                $area = Area::where('code', $analysis['suggested_area'])->first();
                if ($area) {
                    $report->update(['area_id' => $area->id]);
                } else {
                    BotUnknownAsset::create([
                        'report_id' => $report->id,
                        'keyword_mentioned' => $analysis['suggested_area'],
                    ]);
                }
            }
        }

        // PERBAIKAN: Integrasi ClarificationService jika AI butuh klarifikasi
        if (!empty($analysis['needs_clarification'])) {
            $clarification = app(\App\Services\Telegram\ClarificationService::class);
            $session = $clarification->getOrCreateSession((string)$chatId, $text, $analysis);
            $msgData = $clarification->buildCurrentMessage($session);

            // Handle auto-select (jika company/department cuma 1)
            if (!empty($msgData['auto_select'])) {
                $autoResult = $clarification->processSelection(
                    (string)$chatId,
                    "{$msgData['auto_level']}:select:{$msgData['auto_id']}"
                );
                if ($autoResult['success']) {
                    $msgData = $clarification->buildCurrentMessage($autoResult['session']);
                    $maxAuto = 5;
                    while (!empty($msgData['auto_select']) && $maxAuto > 0) {
                        $autoResult = $clarification->processSelection(
                            (string)$chatId,
                            "{$msgData['auto_level']}:select:{$msgData['auto_id']}"
                        );
                        if ($autoResult['success']) {
                            $msgData = $clarification->buildCurrentMessage($autoResult['session']);
                        }
                        $maxAuto--;
                    }
                }
            }

            if (!empty($msgData['message'])) {
                $token = config('services.telegram.bot_token');
                $params = [
                    'chat_id' => $chatId,
                    'text' => $msgData['message'],
                    'parse_mode' => 'Markdown',
                ];

                if (!empty($msgData['keyboard'])) {
                    $keyboard = [];
                    foreach ($msgData['keyboard'] as $btn) {
                        $keyboard[] = [['text' => $btn['text'], 'callback_data' => $btn['callback_data']]];
                    }
                    $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
                }

                try {
                    Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", $params);
                } catch (\Exception $e) {
                    Log::error("Clarification sendMessage error: " . $e->getMessage());
                }

                return response('OK');
            }
        }

        $response = "✅ Laporan diterima!\n\n";
        $response .= "📋 *ID Laporan:* #{$report->id}\n";
        $response .= "📅 *Tanggal:* " . now()->format('d/m/Y') . "\n";

        if ($analysis['confidence'] > 0) {
            $response .= "🤖 *AI Confidence:* {$analysis['confidence']}%\n";
        }

        if (!empty($analysis['detected_area'])) {
            $response .= "📍 *Area terdeteksi:* {$analysis['detected_area']}\n";
        }

        if (!empty($analysis['detected_equipment'])) {
            $response .= "🔧 *Equipment:* {$analysis['detected_equipment']}\n";
        }

        $response .= "\n" . ($analysis['message'] ?? 'Laporan akan direview oleh admin.');

        return $this->sendMessage($chatId, $response);
    }

    protected function sendMessage($chatId, string $text)
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            Log::info("[Telegram Mock] Would send to $chatId: $text");
            return response('OK');
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);

            if (!$response->successful()) {
                Log::warning("Telegram sendMessage failed: " . $response->body());
            }

            return response('OK');
        } catch (\Exception $e) {
            Log::error("Telegram sendMessage exception: " . $e->getMessage());
            return response('OK');
        }
    }
}
