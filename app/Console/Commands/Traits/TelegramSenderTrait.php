<?php

namespace App\Console\Commands\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trait TelegramSenderTrait
 *
 * Digunakan oleh PollTelegramUpdates untuk semua operasi pengiriman dan pengeditan
 * pesan ke Telegram Bot API. Semua method membaca token dari config('services.telegram.bot_token').
 * Error HTTP ditangani secara internal — method tidak melempar exception ke pemanggil.
 *
 * Method yang tersedia:
 *   - sendMessage()              : Kirim pesan teks biasa ke chat
 *   - sendMessageWithKeyboard()  : Kirim pesan teks dengan inline keyboard
 *   - sendChatAction()           : Kirim indikator aksi (default: typing)
 *   - answerCallbackQuery()      : Jawab callback query untuk menghilangkan loading state
 *   - editMessageText()          : Edit teks pesan beserta keyboard-nya
 *   - editMessageTextSimple()    : Edit teks pesan tanpa keyboard (wrapper editMessageText)
 */
trait TelegramSenderTrait
{
    /**
     * Kirim pesan teks ke chat.
     *
     * @param int|string $chatId ID chat tujuan
     * @param string     $text   Teks pesan (mendukung Markdown)
     * @return void
     */
    private function sendMessage(int|string $chatId, string $text): void
    {
        $token = config('services.telegram.bot_token');

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Exception $e) {
            Log::error("Poll sendMessage error: " . $e->getMessage());
        }
    }

    /**
     * Kirim pesan teks dengan inline keyboard.
     *
     * $buttons dapat berupa:
     *   - Array of string           : setiap string menjadi tombol dengan callback_data hash-nya
     *   - Array of ['text' => ..., 'callback_data' => ...] : tombol dengan data eksplisit
     *
     * Jika tidak ada tombol valid, fallback ke sendMessage biasa.
     *
     * @param int|string $chatId  ID chat tujuan
     * @param string     $text    Teks pesan (mendukung Markdown)
     * @param array      $buttons Daftar tombol keyboard
     * @return void
     */
    private function sendMessageWithKeyboard(int|string $chatId, string $text, array $buttons): void
    {
        $token    = config('services.telegram.bot_token');
        $keyboard = [];

        foreach ($buttons as $btn) {
            if (is_string($btn)) {
                $keyboard[] = [['text' => $btn, 'callback_data' => 'clarify_' . md5($btn)]];
            } elseif (isset($btn['text']) && isset($btn['callback_data'])) {
                $keyboard[] = [['text' => $btn['text'], 'callback_data' => $btn['callback_data']]];
            }
        }

        if (empty($keyboard)) {
            $this->sendMessage($chatId, $text);
            return;
        }

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
        } catch (\Exception $e) {
            Log::error("Poll sendMessageWithKeyboard error: " . $e->getMessage());
            $this->sendMessage($chatId, $text);
        }
    }

    /**
     * Kirim indikator aksi ke chat (misalnya animasi "sedang mengetik...").
     *
     * @param int|string $chatId ID chat tujuan
     * @param string     $action Jenis aksi Telegram (default: 'typing')
     * @return void
     */
    private function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        $token = config('services.telegram.bot_token');
        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendChatAction", [
                'chat_id' => $chatId,
                'action'  => $action,
            ]);
        } catch (\Exception $e) {
            // Abaikan error — aksi chat tidak kritis
        }
    }

    /**
     * Jawab callback query untuk menghilangkan loading state pada tombol inline keyboard.
     *
     * @param string $callbackId ID callback query dari Telegram
     * @return void
     */
    private function answerCallbackQuery(string $callbackId): void
    {
        $token = config('services.telegram.bot_token');
        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                'callback_query_id' => $callbackId,
            ]);
        } catch (\Exception $e) {
            // Abaikan
        }
    }

    /**
     * Edit teks pesan beserta inline keyboard-nya.
     *
     * Selalu menyertakan reply_markup (meskipun $buttons kosong) agar keyboard
     * lama yang masih menempel di pesan ikut terhapus.
     *
     * @param int|string $chatId    ID chat
     * @param int        $messageId ID pesan yang diedit
     * @param string     $text      Teks baru (mendukung Markdown)
     * @param array      $buttons   Daftar tombol baru; kosong = hapus keyboard
     * @return void
     */
    private function editMessageText(int|string $chatId, int $messageId, string $text, array $buttons = []): void
    {
        $token    = config('services.telegram.bot_token');
        $keyboard = [];

        foreach ($buttons as $btn) {
            $keyboard[] = [['text' => $btn['text'], 'callback_data' => $btn['callback_data']]];
        }

        $params = [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            // Selalu sertakan reply_markup — inline_keyboard kosong akan
            // menghapus keyboard lama yang masih menempel di pesan.
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/editMessageText", $params);
        } catch (\Exception $e) {
            Log::error("editMessageText error: " . $e->getMessage());
        }
    }

    /**
     * Edit teks pesan tanpa keyboard (wrapper dari editMessageText dengan buttons kosong).
     *
     * @param int|string $chatId    ID chat
     * @param int        $messageId ID pesan yang diedit
     * @param string     $text      Teks baru (mendukung Markdown)
     * @return void
     */
    private function editMessageTextSimple(int|string $chatId, int $messageId, string $text): void
    {
        $this->editMessageText($chatId, $messageId, $text);
    }
}
