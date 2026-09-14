<?php

namespace App\Services;

use App\Models\ItLayout;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class LayoutRasterService
{
    /**
     * Render halaman pertama PDF denah menjadi PNG (sekali) lalu simpan di
     * disk. Mengembalikan path relatif hasil render, atau null bila gagal
     * (mis. bukan PDF, Ghostscript tidak ada, atau file sumber hilang).
     *
     * @param  ItLayout  $layout
     * @return string|null
     */
    public function generate(ItLayout $layout): ?string
    {
        if ($layout->file_type !== 'pdf') {
            return null;
        }

        $binary = $this->resolveBinary();
        if ($binary === null) {
            return null;
        }

        $disk = Storage::disk(config('denah.disk', 'public'));
        $source = $disk->path($layout->file_path);

        if (!is_file($source)) {
            return null;
        }

        $relative = $layout->previewRelativePath();
        $target = $disk->path($relative);
        File::ensureDirectoryExists(dirname($target));

        $dpi = (int) config('denah.preview_dpi', 200);

        $command = escapeshellarg($binary)
            . ' -dSAFER -dBATCH -dNOPAUSE -dQUIET'
            . ' -sDEVICE=png16m -r' . $dpi
            . ' -dFirstPage=1 -dLastPage=1'
            . ' -sOutputFile=' . escapeshellarg($target)
            . ' ' . escapeshellarg($source);

        @exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || !is_file($target)) {
            return null;
        }

        return $relative;
    }

    /**
     * Cari binary Ghostscript: dari config, PATH, atau folder install umum.
     *
     * @return string|null
     */
    public function resolveBinary(): ?string
    {
        $configured = (string) config('denah.gs_path', '');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        foreach (['gswin64c', 'gswin32c', 'gs'] as $name) {
            $found = $this->which($name);
            if ($found !== null) {
                return $found;
            }
        }

        foreach (['C:/Program Files/gs', 'C:/Program Files (x86)/gs'] as $base) {
            $candidates = glob($base . '/gs*/bin/gswin64c.exe') ?: [];
            foreach ($candidates as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Cari binary di PATH (where/which).
     *
     * @param  string  $name
     * @return string|null
     */
    private function which(string $name): ?string
    {
        $isWindows = stripos(PHP_OS, 'WIN') === 0;
        $command = $isWindows ? 'where ' . $name : 'which ' . $name;
        $output = [];
        $code = 1;
        @exec($command . ($isWindows ? ' 2>NUL' : ' 2>/dev/null'), $output, $code);

        if ($code === 0 && !empty($output[0])) {
            $path = trim($output[0]);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
