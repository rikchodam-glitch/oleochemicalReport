<?php

namespace App\Services;

use App\Models\ItAsset;
use App\Support\WindowsProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * CctvSnapshotService
 *
 * Tanggung jawab tunggal: ambil SATU frame gambar terbaru dari sumber
 * snapshot_url milik sebuah ItAsset (CCTV/NVR) dan simpan ke Laravel
 * Storage, lalu kembalikan path relatif (atau null + pesan error).
 *
 * Dua mode sumber, dideteksi dari skema URL:
 *   - rtsp://  -> ffmpeg mengambil 1 frame dari stream (butuh proses
 *                 eksternal, dibungkus Symfony Process agar timeout-nya
 *                 bisa dibatasi dan tidak menggantung request HTTP).
 *   - http(s)://-> GET langsung ke endpoint snapshot bawaan kamera
 *                 (mis. Hikvision ISAPI /ISAPI/Streaming/channels/101/picture).
 *
 * Service ini TIDAK menyentuh kolom last_snapshot_* di DB — itu tanggung
 * jawab caller (ItAssetController@snapshot), mengikuti pola PhotoStorageService
 * yang juga hanya urusan file, bukan state model.
 */
class CctvSnapshotService
{
    protected string $disk;

    protected string $folder;

    protected string $ffmpegPath;

    protected int $ffmpegTimeout;

    protected int $httpTimeout;

    public function __construct()
    {
        $this->disk          = config('cctv.snapshot_disk', 'cctv_snapshots');
        $this->folder        = config('cctv.snapshot_folder', 'snapshots');
        $this->ffmpegPath    = config('cctv.ffmpeg_path', 'ffmpeg');
        $this->ffmpegTimeout = config('cctv.ffmpeg_timeout', 15);
        $this->httpTimeout   = config('cctv.http_timeout', 10);
    }

    /**
     * Ambil snapshot terbaru untuk satu ItAsset dan simpan ke Storage.
     *
     * @param  ItAsset  $itAsset
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function capture(ItAsset $itAsset): array
    {
        $url = $itAsset->snapshot_url;

        if (empty($url)) {
            return $this->failure('Perangkat ini belum diisi URL snapshot.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        try {
            $binary = match ($scheme) {
                'rtsp'         => $this->captureFromRtsp($url),
                'http', 'https' => $this->captureFromHttp($url, $itAsset->snapshot_username, $itAsset->snapshot_password),
                default        => throw new \RuntimeException("Skema URL '{$scheme}' tidak didukung. Gunakan rtsp:// atau http(s)://."),
            };
        } catch (\Throwable $e) {
            Log::warning("CctvSnapshotService: gagal ambil snapshot ItAsset#{$itAsset->id}: " . $e->getMessage());

            return $this->failure($e->getMessage());
        }

        if ($binary === null || $binary === '') {
            return $this->failure('Kamera tidak mengembalikan data gambar.');
        }

        $path = $this->store($itAsset, $binary);

        return [
            'success' => true,
            'path'    => $path,
            'error'   => null,
        ];
    }

    /**
     * Ambil satu frame dari stream RTSP lewat ffmpeg.
     *
     * ffmpeg dijalankan dengan output ke stdout (pipe:1) sehingga tidak
     * perlu file temporary terpisah untuk hasil capture. `-frames:v 1`
     * membatasi hanya 1 frame diambil lalu proses berhenti sendiri.
     *
     * @param  string  $rtspUrl
     * @return string  Binary JPEG
     *
     * @throws \RuntimeException bila ffmpeg gagal atau timeout
     */
    private function captureFromRtsp(string $rtspUrl): string
    {
        $process = WindowsProcess::make([
            $this->ffmpegPath,
            '-rtsp_transport', 'tcp',
            '-y',
            '-i', $rtspUrl,
            '-frames:v', '1',
            '-q:v', '4',
            '-f', 'image2',
            'pipe:1',
        ], $this->ffmpegTimeout);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException('ffmpeg gagal mengambil frame dari stream RTSP (cek URL, kredensial, atau koneksi ke kamera).');
        }

        $output = $process->getOutput();

        if ($output === '') {
            throw new \RuntimeException('ffmpeg tidak menghasilkan gambar (stream mungkin kosong atau format tidak didukung).');
        }

        return $output;
    }

    /**
     * Ambil gambar langsung dari HTTP snapshot endpoint kamera.
     *
     * Hikvision ISAPI umumnya memakai HTTP Digest authentication, jadi
     * percobaan pertama dikirim dengan mode digest. Bila kamera menolak
     * (401) dan username diisi, dicoba sekali lagi dengan Basic auth untuk
     * perangkat yang hanya mendukung basic.
     *
     * @param  string       $url
     * @param  string|null  $username
     * @param  string|null  $password
     * @return string  Binary gambar
     *
     * @throws \RuntimeException bila request gagal atau bukan gambar
     */
    private function captureFromHttp(string $url, ?string $username, ?string $password): string
    {
        $options = ['verify' => false];

        if (!empty($username)) {
            $options['auth'] = [$username, (string) $password, 'digest'];
        }

        $response = Http::timeout($this->httpTimeout)->withOptions($options)->get($url);

        if (!$response->successful() && !empty($username)) {
            // Fallback: beberapa perangkat hanya mendukung Basic auth.
            $response = Http::timeout($this->httpTimeout)
                ->withOptions(['verify' => false])
                ->withBasicAuth($username, (string) $password)
                ->get($url);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Kamera merespons dengan status HTTP {$response->status()}.");
        }

        $contentType = $response->header('Content-Type', '');

        if (!Str::startsWith($contentType, 'image/')) {
            throw new \RuntimeException('Endpoint tidak mengembalikan gambar (Content-Type: ' . ($contentType ?: 'tidak diketahui') . ').');
        }

        return $response->body();
    }

    /**
     * Simpan binary gambar ke Storage dengan nama file unik per waktu
     * capture, sehingga tidak menimpa snapshot sebelumnya bila diperlukan
     * riwayat (saat ini hanya snapshot terbaru yang dirujuk lewat
     * last_snapshot_path, snapshot lama dibiarkan ada di disk).
     *
     * Path: {folder}/{device_type}/{it_asset_id}/{timestamp}.jpg
     *
     * @param  ItAsset  $itAsset
     * @param  string   $binary
     * @return string  Path relatif di disk
     */
    private function store(ItAsset $itAsset, string $binary): string
    {
        $filename = now()->format('Ymd_His') . '_' . Str::random(6) . '.jpg';
        $path = sprintf(
            '%s/%s/%d/%s',
            $this->folder,
            strtolower($itAsset->device_type),
            $itAsset->id,
            $filename
        );

        Storage::disk($this->disk)->put($path, $binary);

        return $path;
    }

    /**
     * Bentuk array hasil gagal yang konsisten.
     *
     * @param  string  $message
     * @return array{success: bool, path: string|null, error: string|null}
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'path'    => null,
            'error'   => $message,
        ];
    }
}
