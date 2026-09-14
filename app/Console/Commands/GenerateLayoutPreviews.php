<?php

namespace App\Console\Commands;

use App\Models\ItLayout;
use App\Services\LayoutRasterService;
use Illuminate\Console\Command;

class GenerateLayoutPreviews extends Command
{
    /**
     * Nama & argumen perintah.
     *
     * @var string
     */
    protected $signature = 'denah:previews {--dpi= : Override DPI render (opsional)}';

    /**
     * Deskripsi perintah.
     *
     * @var string
     */
    protected $description = 'Pra-render PDF denah menjadi gambar agar halaman denah terbuka cepat';

    /**
     * Jalankan perintah: render gambar untuk semua denah bertipe PDF.
     *
     * @param  LayoutRasterService  $raster
     * @return int
     */
    public function handle(LayoutRasterService $raster): int
    {
        $binary = $raster->resolveBinary();
        if ($binary === null) {
            $this->error('Ghostscript tidak ditemukan. Set GHOSTSCRIPT_PATH di .env atau pastikan gswin64c terpasang.');

            return self::FAILURE;
        }

        $this->line('Ghostscript: ' . $binary);

        if ($this->option('dpi')) {
            config(['denah.preview_dpi' => (int) $this->option('dpi')]);
        }
        $this->line('DPI: ' . config('denah.preview_dpi'));

        $layouts = ItLayout::where('file_type', 'pdf')->get();

        if ($layouts->isEmpty()) {
            $this->info('Tidak ada denah bertipe PDF.');

            return self::SUCCESS;
        }

        foreach ($layouts as $layout) {
            $this->line('Render #' . $layout->id . ' ' . $layout->name . ' ...');
            $path = $raster->generate($layout);

            if ($path !== null) {
                $this->info('  OK -> ' . $path);
            } else {
                $this->warn('  GAGAL (cek file PDF / Ghostscript)');
            }
        }

        return self::SUCCESS;
    }
}
