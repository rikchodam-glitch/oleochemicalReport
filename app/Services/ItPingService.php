<?php

namespace App\Services;

class ItPingService
{
    /**
     * Daftar port umum yang dicoba pada metode fallback fsockopen, bila
     * exec() tidak tersedia di server. Diurutkan dari yang paling umum
     * terbuka pada perangkat jaringan/IT (web admin, RDP, RTSP CCTV, dll).
     *
     * @var array<int, int>
     */
    private const FALLBACK_PORTS = [80, 443, 22, 3389, 554, 8000];

    /**
     * Ping satu alamat IP dan kembalikan status online/offline beserta
     * latency dalam milidetik.
     *
     * Metode utama: exec() ping ICMP native OS (sintaks Windows: ping -n 1
     * -w 1000, sesuai lingkungan server saat ini). Bila exec() dinonaktifkan
     * (banyak hosting mematikannya demi keamanan) atau proses ping gagal
     * dijalankan sama sekali, fallback ke pengecekan koneksi TCP lewat
     * fsockopen sebagai indikasi kasar "perangkat merespons di jaringan".
     *
     * @param  string  $ip
     * @return array{status: string, latency_ms: int|null}
     */
    public function ping(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['status' => 'invalid', 'latency_ms' => null];
        }

        if ($this->execAvailable()) {
            $result = $this->pingViaExec($ip);

            if ($result !== null) {
                return $result;
            }
        }

        return $this->pingViaFsockopen($ip);
    }

    /**
     * Cek apakah fungsi exec() bisa dipakai (tidak ada di disable_functions
     * php.ini). Perlu dicek karena banyak hosting mematikan exec() demi
     * keamanan, sehingga fitur ping harus tetap berfungsi lewat fallback.
     *
     * @return bool
     */
    private function execAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }

    /**
     * Jalankan ping ICMP native lewat exec() dan parse hasilnya. IP sudah
     * divalidasi filter_var() sebelum sampai di sini, tapi tetap dibungkus
     * escapeshellarg() sebagai lapisan keamanan tambahan terhadap injeksi
     * shell.
     *
     * Return null bila proses sama sekali gagal dijalankan (bukan berarti
     * perangkat offline) -- caller harus fallback ke metode lain.
     *
     * @param  string  $ip
     * @return array{status: string, latency_ms: int|null}|null
     */
    private function pingViaExec(string $ip): ?array
    {
        $escapedIp = escapeshellarg($ip);
        $isWindows = PHP_OS_FAMILY === 'Windows';

        $command = $isWindows
            ? "ping -n 1 -w 1000 {$escapedIp} 2>&1"
            : "ping -c 1 -W 1 {$escapedIp} 2>&1";

        $output = [];
        $exitCode = null;

        exec($command, $output, $exitCode);

        if (empty($output)) {
            return null;
        }

        $text = implode("\n", $output);
        $hasTtl = stripos($text, 'ttl=') !== false;

        $isOnline = $isWindows
            ? $hasTtl
            : ($exitCode === 0 && $hasTtl);

        if (!$isOnline) {
            return ['status' => 'offline', 'latency_ms' => null];
        }

        return ['status' => 'online', 'latency_ms' => $this->parseLatency($text)];
    }

    /**
     * Ekstrak nilai latency (ms) dari output teks ping. Mendukung format
     * Windows ("time=12ms" / "time<1ms") dan Linux ("time=12.3 ms").
     *
     * @param  string  $text
     * @return int|null
     */
    private function parseLatency(string $text): ?int
    {
        if (preg_match('/time[<=]\s*([\d.]+)\s*ms/i', $text, $matches)) {
            return (int) round((float) $matches[1]);
        }

        return null;
    }

    /**
     * Fallback bila exec() tidak tersedia: cek konektivitas TCP lewat
     * fsockopen ke beberapa port umum. Ini bukan ICMP asli, hanya indikasi
     * kasar apakah host merespons di jaringan, dengan latency dihitung dari
     * durasi proses connect.
     *
     * @param  string  $ip
     * @return array{status: string, latency_ms: int|null}
     */
    private function pingViaFsockopen(string $ip): array
    {
        foreach (self::FALLBACK_PORTS as $port) {
            $start = microtime(true);
            $connection = @fsockopen($ip, $port, $errno, $errstr, 1);

            if ($connection) {
                fclose($connection);

                return [
                    'status'     => 'online',
                    'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                ];
            }
        }

        return ['status' => 'offline', 'latency_ms' => null];
    }
}
