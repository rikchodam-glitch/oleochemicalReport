<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Mengunduh pesan suara Telegram ke disk sementara.
 *
 * Berbeda dari PhotoStorageService yang menyimpan foto permanen, audio voice
 * hanya dipakai untuk transkripsi lalu dihapus. Disk bawaan adalah disk local
 * (private) agar suara teknisi tidak dapat diakses dari web.
 */
class VoiceStorageService
{
    private string $disk;
    private string $folder;
    private int $maxBytes;

    /**
     * Ekstensi audio yang diterima Groq Whisper.
     *
     * @var array<int, string>
     */
    private array $allowedExtensions = ['ogg', 'oga', 'opus', 'mp3', 'm4a', 'wav', 'webm', 'flac'];

    public function __construct()
    {
        $this->disk     = (string) config('voice.disk', 'local');
        $this->folder   = trim((string) config('voice.folder', 'telegram-voices'), '/');
        $this->maxBytes = (int) config('voice.max_bytes', 20 * 1024 * 1024);
    }

    /**
     * Unduh voice note dari Telegram dan simpan sementara.
     *
     * @param  string $fileId File ID dari message.voice
     * @param  string $chatId Chat ID pemilik
     * @return array{path:string,size:int}|null Null bila gagal
     */
    public function store(string $fileId, string $chatId): ?array
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            Log::warning('VoiceStorage: token Telegram belum dikonfigurasi.');

            return null;
        }

        try {
            $info = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getFile", [
                'file_id' => $fileId,
            ]);

            if (! $info->successful() || ! ($info->json('ok') === true)) {
                Log::warning('VoiceStorage: getFile gagal', ['body' => mb_substr($info->body(), 0, 300)]);

                return null;
            }

            $filePath = (string) $info->json('result.file_path', '');
            $fileSize = (int) $info->json('result.file_size', 0);

            if ($filePath === '') {
                return null;
            }

            if ($fileSize > $this->maxBytes) {
                Log::warning('VoiceStorage: ukuran audio melebihi batas', ['size' => $fileSize]);

                return null;
            }

            $ekstensi = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            // Telegram voice note sering memakai ekstensi .oga, sedangkan
            // Groq Whisper hanya menerima daftar ekstensi resminya (ogg/opus/
            // mp3/...). Normalisasi .oga -> .ogg agar file diterima.
            if ($ekstensi === 'oga') {
                $ekstensi = 'ogg';
            }

            if (! in_array($ekstensi, $this->allowedExtensions, true)) {
                Log::warning('VoiceStorage: ekstensi audio tidak didukung', ['ekstensi' => $ekstensi]);

                return null;
            }

            $unduh = Http::timeout(30)->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

            if (! $unduh->successful() || $unduh->body() === '') {
                Log::warning('VoiceStorage: unduh audio gagal', ['status' => $unduh->status()]);

                return null;
            }

            $isi = $unduh->body();

            if (strlen($isi) > $this->maxBytes) {
                return null;
            }

            $path = implode('/', [
                $this->folder,
                now()->format('Y'),
                now()->format('m'),
                now()->format('d'),
                preg_replace('/[^0-9A-Za-z_-]/', '', $chatId) ?: 'chat',
                'voice_' . md5($isi) . '.' . $ekstensi,
            ]);

            if (! Storage::disk($this->disk)->exists($path)) {
                Storage::disk($this->disk)->put($path, $isi);
            }

            return ['path' => $path, 'size' => strlen($isi)];
        } catch (\Throwable $galat) {
            Log::error('VoiceStorage: gagal menyimpan audio', [
                'file_id' => $fileId,
                'error'   => $galat->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Hapus audio sementara.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            Storage::disk($this->disk)->delete($path);
        } catch (\Throwable $galat) {
            Log::warning('VoiceStorage: gagal menghapus audio', [
                'path'  => $path,
                'error' => $galat->getMessage(),
            ]);
        }
    }

    /**
     * Path absolut file audio (dipakai untuk multipart ke Groq).
     */
    public function absolutePath(string $path): string
    {
        return Storage::disk($this->disk)->path($path);
    }
}
