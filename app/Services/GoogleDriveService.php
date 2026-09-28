<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GoogleDriveService
 *
 * Mengakses Google Drive memakai kredensial service account (JWT RS256)
 * tanpa library google/apiclient. Dipakai untuk mengubah nilai kolom
 * "Foto N" (path relatif yang ditulis AppSheet) menjadi URL gambar yang
 * bisa langsung dipakai tag <img>.
 *
 * Alur kerja:
 *   1. Service account menukar JWT dengan access token OAuth.
 *   2. Cari file berdasarkan nama di folder foto AppSheet.
 *   3. Set permission "anyone with link" (role reader) supaya gambar bisa
 *      dimuat tanpa login.
 *   4. Bangun URL https://lh3.googleusercontent.com/d/<fileId>.
 *
 * Konfigurasi lengkap ada di config('services.google_drive.*').
 */
class GoogleDriveService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const DRIVE_API = 'https://www.googleapis.com/drive/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/drive';

    private const TIMEOUT = 30;

    /**
     * Isi file kredensial service account (di-cache per instance).
     */
    private ?array $credentials = null;

    /**
     * Penanda apakah file kredensial sudah dicoba dibaca.
     */
    private bool $credentialsLoaded = false;

    /**
     * Cek apakah fitur ini aktif dan file kredensialnya bisa dibaca.
     *
     * @return bool True kalau siap dipakai
     */
    public function isConfigured(): bool
    {
        if (!config('services.google_drive.enabled')) {
            return false;
        }

        return $this->loadCredentials() !== null;
    }

    /**
     * Ambil access token OAuth dari service account. Token di-cache sampai
     * mendekati kedaluwarsa supaya tidak menukar JWT di setiap pemanggilan.
     *
     * @return string Access token
     *
     * @throws RuntimeException Kalau kredensial tidak ada atau token gagal diambil
     */
    public function accessToken(): string
    {
        $cached = Cache::get('gdrive:access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->loadCredentials();
        if ($credentials === null) {
            throw new RuntimeException('Kredensial service account Google Drive tidak tersedia.');
        }

        $assertion = $this->buildJwtAssertion($credentials);

        $response = Http::asForm()
            ->timeout(self::TIMEOUT)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Gagal mengambil access token Google: HTTP ' . $response->status() . ' — '
                . mb_substr((string) $response->body(), 0, 300)
            );
        }

        $token = (string) $response->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Respons token Google tidak memuat access_token.');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put('gdrive:access_token', $token, now()->addSeconds(max(60, $expiresIn - 120)));

        return $token;
    }

    /**
     * Cari file berdasarkan nama, dibatasi ke folder foto kalau folder ID
     * dikonfigurasi. Kalau tidak ketemu di folder, dicari di seluruh Drive.
     *
     * @param  string  $fileName  Nama file (tanpa path folder)
     * @return string|null File ID atau null kalau tidak ada
     */
    public function findFileId(string $fileName): ?string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return null;
        }

        $folderId = (string) config('services.google_drive.finding_photos_folder_id', '');

        if ($folderId !== '') {
            $fileId = $this->searchFile($fileName, $folderId);
            if ($fileId !== null) {
                return $fileId;
            }
        }

        return $this->searchFile($fileName, null);
    }

    /**
     * Set permission file menjadi "anyone with link" (reader).
     *
     * @param  string  $fileId  File ID
     * @return bool True kalau file sudah/pasti bisa diakses publik
     */
    public function makePublic(string $fileId): bool
    {
        try {
            $response = $this->request('post', "/files/{$fileId}/permissions", [
                'role' => 'reader',
                'type' => 'anyone',
            ]);

            if ($response->successful()) {
                return true;
            }

            // Permission yang sudah ada akan ditolak sebagai duplikat.
            if ($response->status() === 400 && str_contains((string) $response->body(), 'alreadyExists')) {
                return true;
            }

            Log::warning('GoogleDriveService: gagal set permission', [
                'file_id' => $fileId,
                'status'  => $response->status(),
                'body'    => mb_substr((string) $response->body(), 0, 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GoogleDriveService: error set permission', [
                'file_id' => $fileId,
                'pesan'   => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Susun URL gambar yang bisa langsung dipakai tag <img>.
     *
     * @param  string  $fileId  File ID
     * @return string URL gambar
     */
    public function imageUrl(string $fileId): string
    {
        return 'https://lh3.googleusercontent.com/d/' . $fileId;
    }

    /**
     * Ubah nama file dari AppSheet menjadi URL gambar siap pakai.
     * Hasil dicari di Drive, dijadikan publik bila dikonfigurasi, lalu
     * disusun menjadi URL. Hasil sukses di-cache lama, kegagalan sebentar.
     *
     * @param  string  $fileName  Nama file hasil pembersihan path AppSheet
     * @return string|null URL gambar atau null kalau tidak bisa dipakai
     */
    public function resolvePhotoUrl(string $fileName): ?string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return null;
        }

        $cacheKey = 'gdrive:foto:' . sha1($fileName);
        $cached   = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached === '' ? null : (string) $cached;
        }

        $url = $this->resolvePhotoUrlFresh($fileName);

        Cache::put($cacheKey, $url ?? '', $url !== null ? now()->addDays(30) : now()->addMinutes(10));

        return $url;
    }

    /**
     * Ambil nama-nama file di folder foto (untuk keperluan diagnostik).
     *
     * @param  int  $limit  Jumlah maksimum nama yang dikembalikan
     * @return array<int, array{id: string, name: string}>
     */
    public function listFolderFiles(int $limit = 10): array
    {
        $folderId = (string) config('services.google_drive.finding_photos_folder_id', '');
        if ($folderId === '') {
            return [];
        }

        $response = $this->request('get', '/files', [
            'q'                          => "'{$folderId}' in parents and trashed = false",
            'fields'                     => 'files(id,name)',
            'pageSize'                   => $limit,
            'supportsAllDrives'          => 'true',
            'includeItemsFromAllDrives'  => 'true',
        ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Gagal membaca isi folder Drive: HTTP ' . $response->status() . ' — '
                . mb_substr((string) $response->body(), 0, 300)
            );
        }

        $files = $response->json('files') ?? [];

        return array_values(array_filter(array_map(
            static fn ($f) => isset($f['id']) ? ['id' => (string) $f['id'], 'name' => (string) ($f['name'] ?? '')] : null,
            is_array($files) ? $files : []
        )));
    }

    /**
     * Proses inti resolve: cari file, jadikan publik, susun URL.
     * URL hanya dikembalikan kalau file benar-benar bisa diakses publik,
     * supaya kolom foto tidak terisi link yang tidak bisa tampil.
     *
     * @param  string  $fileName  Nama file
     * @return string|null URL gambar atau null
     */
    private function resolvePhotoUrlFresh(string $fileName): ?string
    {
        try {
            if (!$this->isConfigured()) {
                return null;
            }

            $fileId = $this->findFileId($fileName);
            if ($fileId === null) {
                Log::info('GoogleDriveService: file tidak ditemukan', ['nama_file' => $fileName]);

                return null;
            }

            if (config('services.google_drive.make_public', true) && !$this->makePublic($fileId)) {
                Log::warning('GoogleDriveService: file tidak bisa dijadikan publik', [
                    'nama_file' => $fileName,
                    'file_id'   => $fileId,
                ]);

                return null;
            }

            return $this->imageUrl($fileId);
        } catch (\Throwable $e) {
            Log::warning('GoogleDriveService: resolve gagal', [
                'nama_file' => $fileName,
                'pesan'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cari file berdasarkan nama, opsional dibatasi ke satu folder induk.
     *
     * @param  string       $fileName  Nama file
     * @param  string|null  $folderId  Folder induk atau null untuk seluruh Drive
     * @return string|null File ID atau null
     */
    private function searchFile(string $fileName, ?string $folderId): ?string
    {
        $nama = $this->escapeQueryValue($fileName);

        $query = "name = '{$nama}' and trashed = false";
        if ($folderId !== null) {
            $query = "name = '{$nama}' and '{$folderId}' in parents and trashed = false";
        }

        $response = $this->request('get', '/files', [
            'q'                         => $query,
            'fields'                    => 'files(id,name)',
            'pageSize'                  => 5,
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Pencarian file Drive gagal: HTTP ' . $response->status() . ' — '
                . mb_substr((string) $response->body(), 0, 300)
            );
        }

        $files = $response->json('files');
        if (!is_array($files) || $files === []) {
            return null;
        }

        $first = $files[0]['id'] ?? null;

        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * Kirim request ke Drive API memakai access token service account.
     *
     * @param  string  $method  Metode HTTP (get|post)
     * @param  string  $path    Path API, mis. "/files"
     * @param  array   $payload Query string untuk GET, body JSON untuk POST
     * @return \Illuminate\Http\Client\Response
     */
    private function request(string $method, string $path, array $payload = [])
    {
        $token = $this->accessToken();

        $pending = Http::withToken($token)
            ->acceptJson()
            ->timeout(self::TIMEOUT);

        $url = self::DRIVE_API . $path;

        return $method === 'get'
            ? $pending->get($url, $payload)
            : $pending->post($url, $payload);
    }

    /**
     * Bangun JWT assertion (RS256) dari kredensial service account.
     *
     * @param  array  $credentials  Isi file kredensial
     * @return string JWT yang sudah ditandatangani
     *
     * @throws RuntimeException Kalau private key tidak valid
     */
    private function buildJwtAssertion(array $credentials): string
    {
        $clientEmail = (string) ($credentials['client_email'] ?? '');
        $privateKey  = (string) ($credentials['private_key'] ?? '');

        if ($clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('File kredensial tidak memuat client_email atau private_key.');
        }

        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $clientEmail,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signingInput = $this->base64Url(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . $this->base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('Private key service account tidak bisa dibaca.');
        }

        $signature = '';
        $signed    = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        openssl_free_key($key);

        if (!$signed) {
            throw new RuntimeException('Gagal menandatangani JWT service account.');
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    /**
     * Baca file kredensial service account dari path di konfigurasi.
     *
     * @return array|null Isi kredensial atau null kalau tidak tersedia
     */
    private function loadCredentials(): ?array
    {
        if ($this->credentialsLoaded) {
            return $this->credentials;
        }

        $this->credentialsLoaded = true;

        $path = (string) config('services.google_drive.credentials_path', '');
        if ($path === '' || !is_file($path)) {
            return $this->credentials = null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $this->credentials = null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return $this->credentials = null;
        }

        return $this->credentials = $decoded;
    }

    /**
     * Encode base64 dengan varian URL-safe tanpa padding.
     *
     * @param  string  $value  Nilai yang di-encode
     * @return string Hasil base64url
     */
    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Escape nilai untuk query Drive API (backslash dan kutip tunggal).
     *
     * @param  string  $value  Nilai mentah
     * @return string Nilai siap masuk query
     */
    private function escapeQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
