<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * WindowsProcess
 *
 * Pembuat Symfony Process dengan lingkungan Windows yang lengkap.
 *
 * Symfony menyusun lingkungan proses anak dari $_SERVER/$_ENV yang bisa tidak
 * lengkap (mis. ketika PHP dijalankan oleh web server, queue worker, atau
 * proses yang environment-nya terbatas). Akibatnya program seperti mysqladmin
 * gagal inisialisasi Winsock dengan error 10106 ("Can't create TCP/IP socket")
 * walau perintahnya benar. Helper ini memastikan variabel sistem wajib selalu
 * ikut dikirim ke proses anak.
 */
class WindowsProcess
{
    /**
     * Buat Process dengan lingkungan Windows lengkap.
     *
     * @param  array<int, string>  $command  Perintah beserta argumennya
     * @param  int                 $timeout  Batas waktu dalam detik
     * @return Process
     */
    public static function make(array $command, int $timeout = 300): Process
    {
        $process = new Process($command, null, self::environment());
        $process->setTimeout($timeout);

        return $process;
    }

    /**
     * Variabel lingkungan untuk proses anak: variabel induk ditambah variabel
     * sistem wajib (SystemRoot, windir, TEMP, PATH, dan sejenisnya).
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $windows = rtrim(self::value('SystemRoot') ?? self::value('windir') ?? 'C:\\Windows', '\\');
        $temp = self::value('TEMP') ?? $windows . '\\Temp';

        return array_merge([
            'SystemRoot'  => $windows,
            'windir'      => $windows,
            'SystemDrive' => self::value('SystemDrive') ?? substr($windows, 0, 2),
            'PATH'        => self::value('PATH') ?? $windows . '\\System32;' . $windows,
            'TEMP'        => $temp,
            'TMP'         => self::value('TMP') ?? $temp,
            'COMSPEC'     => self::value('COMSPEC') ?? $windows . '\\System32\\cmd.exe',
            'PATHEXT'     => self::value('PATHEXT') ?? '.COM;.EXE;.BAT;.CMD',
            'USERPROFILE' => self::value('USERPROFILE') ?? '',
            'APPDATA'     => self::value('APPDATA') ?? '',
            'HOMEDRIVE'   => self::value('HOMEDRIVE') ?? '',
            'HOMEPATH'    => self::value('HOMEPATH') ?? '',
        ], self::parentEnvironment());
    }

    /**
     * Variabel lingkungan milik proses PHP saat ini (hanya nilai teks).
     *
     * @return array<string, string>
     */
    private static function parentEnvironment(): array
    {
        $environment = [];

        foreach ([$_SERVER, $_ENV] as $source) {
            foreach ($source as $key => $value) {
                if (is_string($key) && is_string($value) && $value !== '') {
                    $environment[$key] = $value;
                }
            }
        }

        return $environment;
    }

    /**
     * Baca satu variabel lingkungan (null bila kosong atau tidak ada).
     *
     * @param  string  $name
     * @return string|null
     */
    private static function value(string $name): ?string
    {
        $value = getenv($name);

        if ($value === false || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
