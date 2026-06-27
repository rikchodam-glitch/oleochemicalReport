<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PhotoStorageService
 *
 * Tanggung jawab tunggal: download foto dari Telegram API menggunakan
 * file_id, lalu simpan ke Laravel Storage dengan path terstruktur.
 *
 * Path penyimpanan: reports/{YYYY}/{MM}/{DD}/{chat_id}/{basename}.jpg
 *
 * Caller (PollTelegramUpdates / ReportWizardService) hanya perlu:
 *   1. Memanggil store($fileId, $chatId) → dapat path relatif.
 *   2. Mengumpulkan array path lalu simpan ke kolom JSON di Report.
 *
 * Service ini TIDAK menyentuh DB dan TIDAK tahu tentang wizard.
 * Semua error ditangani secara defensif — return null jika gagal
 * agar wizard tetap bisa lanjut meski upload foto bermasalah.
 */
class PhotoStorageService
{
    /** Disk Laravel Storage yang dipakai (sesuai config/filesystems.php). */
    protected string $disk;

    /** Subfolder root di dalam disk. */
    protected string $rootFolder;

    /** Ukuran file maksimum yang diterima (bytes). Default 20 MB. */
    protected int $maxFileSize;

    /** Ekstensi yang diizinkan (lowercase). */
    protected array $allowedExtensions;

    public function __construct()
    {
        $this->disk              = config('telegram.photo_disk', 'local');
        $this->rootFolder        = config('telegram.photo_folder', 'reports');
        $this->maxFileSize       = config('telegram.photo_max_bytes', 20 * 1024 * 1024);
        $this->allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    }

    // =========================================================
    // PUBLIC API
    // =========================================================

    /**
     * Download foto dari Telegram berdasarkan file_id dan simpan ke Storage.
     *
     * @param  string $fileId  File ID Telegram (dari message.photo[].file_id)
     * @param  string $chatId  Chat ID — dipakai sebagai subfolder
     * @return string|null     Path relatif di Storage, atau null jika gagal
     */
    public function store(string $fileId, string $chatId): ?string
    {
        try {
            // 1. Resolve URL download dari Telegram
            $fileUrl = $this->resolveFileUrl($fileId);
            if (!$fileUrl) {
                Log::warning("PhotoStorage: Tidak bisa resolve URL untuk file_id={$fileId}");
                return null;
            }

            // 2. Download konten
            $content = $this->downloadFile($fileUrl);
            if ($content === null) {
                return null;
            }

            // 3. Validasi ukuran & tipe
            if (!$this->validate($content, $fileUrl)) {
                return null;
            }

            // 4. Buat path & simpan
            $path = $this->buildPath($chatId, $fileUrl);
            Storage::disk($this->disk)->put($path, $content);

            Log::info("PhotoStorage: Foto disimpan", [
                'path'    => $path,
                'size'    => strlen($content),
                'chat_id' => $chatId,
            ]);

            return $path;
        } catch (\Throwable $e) {
            Log::error("PhotoStorage: Gagal menyimpan foto", [
                'file_id' => $fileId,
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Download dan simpan beberapa foto sekaligus.
     * File ID yang gagal dilewati (tidak membatalkan seluruh batch).
     *
     * @param  array  $fileIds  Array of file_id string
     * @param  string $chatId
     * @return array  Array of path string (hanya yang berhasil)
     */
    public function storeMany(array $fileIds, string $chatId): array
    {
        $paths = [];
        foreach ($fileIds as $fileId) {
            $path = $this->store($fileId, $chatId);
            if ($path !== null) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /**
     * Hapus file foto dari Storage berdasarkan path relatif.
     * Dipanggil jika laporan dibatalkan dan foto sudah terlanjur disimpan.
     *
     * @param  string|array $paths  Single path atau array of paths
     * @return void
     */
    public function delete(string|array $paths): void
    {
        $paths = (array) $paths;
        foreach ($paths as $path) {
            try {
                if (Storage::disk($this->disk)->exists($path)) {
                    Storage::disk($this->disk)->delete($path);
                    Log::info("PhotoStorage: Foto dihapus: {$path}");
                }
            } catch (\Throwable $e) {
                Log::warning("PhotoStorage: Gagal hapus foto {$path}: " . $e->getMessage());
            }
        }
    }

    /**
     * Ambil URL publik dari path Storage (jika disk mendukung URL).
     * Untuk disk 'local', kembalikan path saja.
     */
    public function url(string $path): string
    {
        try {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk($this->disk);
            return $disk->url($path);
        } catch (\Throwable $e) {
            return $path;
        }
    }

    // =========================================================
    // TELEGRAM API HELPERS
    // =========================================================

    /**
     * Dapatkan URL download file dari Telegram menggunakan getFile API.
     * Telegram memberikan URL sementara yang valid ~1 jam.
     */
    protected function resolveFileUrl(string $fileId): ?string
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            Log::error("PhotoStorage: TELEGRAM_BOT_TOKEN tidak dikonfigurasi");
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->get("https://api.telegram.org/bot{$token}/getFile", [
                    'file_id' => $fileId,
                ]);

            if (!$response->successful()) {
                Log::warning("PhotoStorage: getFile gagal", [
                    'file_id' => $fileId,
                    'status'  => $response->status(),
                    'body'    => substr($response->body(), 0, 200),
                ]);
                return null;
            }

            $filePath = $response->json('result.file_path');
            if (empty($filePath)) {
                Log::warning("PhotoStorage: file_path kosong dari getFile", ['file_id' => $fileId]);
                return null;
            }

            return "https://api.telegram.org/file/bot{$token}/{$filePath}";
        } catch (\Throwable $e) {
            Log::error("PhotoStorage: getFile exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Download konten file dari URL.
     * Timeout 30 detik — cukup untuk foto resolusi tinggi.
     */
    protected function downloadFile(string $url): ?string
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning("PhotoStorage: Download gagal", [
                    'status' => $response->status(),
                    'url'    => substr($url, 0, 80) . '...',
                ]);
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::error("PhotoStorage: Download exception: " . $e->getMessage());
            return null;
        }
    }

    // =========================================================
    // VALIDASI & PATH
    // =========================================================

    /**
     * Validasi konten yang sudah didownload:
     * - Ukuran tidak boleh melewati batas
     * - Ekstensi harus diizinkan
     * - Magic bytes harus cocok dengan gambar (JPEG/PNG/WebP)
     */
    protected function validate(string $content, string $url): bool
    {
        // Cek ukuran
        $size = strlen($content);
        if ($size > $this->maxFileSize) {
            Log::warning("PhotoStorage: File terlalu besar ({$size} bytes)");
            return false;
        }

        if ($size === 0) {
            Log::warning("PhotoStorage: File kosong");
            return false;
        }

        // Cek ekstensi dari URL
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($ext && !in_array($ext, $this->allowedExtensions)) {
            Log::warning("PhotoStorage: Ekstensi tidak diizinkan: {$ext}");
            return false;
        }

        // Cek magic bytes
        if (!$this->hasImageMagicBytes($content)) {
            Log::warning("PhotoStorage: File bukan gambar yang valid (magic bytes check gagal)");
            return false;
        }

        return true;
    }

    /**
     * Cek magic bytes untuk JPEG, PNG, dan WebP.
     */
    protected function hasImageMagicBytes(string $content): bool
    {
        if (strlen($content) < 4) {
            return false;
        }

        $bytes = substr($content, 0, 12);

        // JPEG: FF D8 FF
        if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
            return true;
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (substr($bytes, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
            return true;
        }

        // WebP: 52 49 46 46 ?? ?? ?? ?? 57 45 42 50
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return true;
        }

        return false;
    }

    /**
     * Bangun path penyimpanan terstruktur.
     * Format: reports/YYYY/MM/DD/{chat_id}/{uniqid}.{ext}
     */
    protected function buildPath(string $chatId, string $sourceUrl): string
    {
        $now      = now();
        $year     = $now->format('Y');
        $month    = $now->format('m');
        $day      = $now->format('d');

        // Ekstensi dari URL, default jpg
        $ext = strtolower(pathinfo(parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            $ext = 'jpg';
        }

        // Sanitasi chat_id agar aman jadi nama folder
        $safeChatId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $chatId);

        $filename = uniqid('photo_', true) . '.' . $ext;

        return "{$this->rootFolder}/{$year}/{$month}/{$day}/{$safeChatId}/{$filename}";
    }
}
