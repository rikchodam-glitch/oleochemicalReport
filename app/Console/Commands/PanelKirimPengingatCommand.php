<?php

namespace App\Console\Commands;

use App\Models\Technician;
use App\Services\PanelNotifikasiService;
use Illuminate\Console\Command;

/**
 * Perintah terjadwal pengirim pengingat harian ruang panel.
 *
 * Dijalankan tiap menit oleh scheduler (lihat routes/console.php). Setiap
 * penanggung jawab ruang panel menerima satu pesan Telegram berisi kondisi
 * terkini ruangannya pada awal jam masuk kerja orang itu (jam kerja biasa atau
 * jadwal shiftnya). Pengiriman hanya sekali per hari per teknisi, dijaga tabel
 * panel_notifikasi_terkirim.
 */
class PanelKirimPengingatCommand extends Command
{
    protected $signature = 'panel:kirim-pengingat
        {--uji-kirim : Kirim sekarang juga tanpa menunggu jam kerja (untuk pengujian)}
        {--teknisi= : ID teknisi tertentu saja saat uji kirim}';

    protected $description = 'Kirim pengingat harian kondisi ruang panel kepada penanggung jawab pada awal jam masuk kerja mereka';

    /**
     * Jalankan pengiriman pengingat.
     *
     * @param  PanelNotifikasiService  $notifikasi
     * @return int Kode keluar perintah
     */
    public function handle(PanelNotifikasiService $notifikasi): int
    {
        $ringkasan = $this->option('uji-kirim')
            ? $notifikasi->kirimPaksa($this->teknisiUji())
            : $notifikasi->kirimOtomatis();

        if ($ringkasan['diperiksa'] === 0) {
            $this->warn('Belum ada teknisi yang ditetapkan sebagai penanggung jawab ruang panel.');

            return self::SUCCESS;
        }

        foreach ($ringkasan['detail'] as $detail) {
            $this->line(sprintf(
                '  %-28s jam %s, %d ruangan, %s',
                $detail['teknisi'],
                $detail['jam_kerja'],
                $detail['ruangan'],
                $detail['status']
            ));
        }

        $this->info(sprintf(
            'Pengingat ruang panel (%s): %d terkirim, %d gagal, %d dilewati dari %d teknisi penanggung jawab.',
            $ringkasan['waktu'],
            $ringkasan['terkirim'],
            $ringkasan['gagal'],
            $ringkasan['dilewati'],
            $ringkasan['diperiksa']
        ));

        return self::SUCCESS;
    }

    /**
     * Teknisi yang dipilih untuk uji kirim (null berarti semua penanggung jawab).
     *
     * @return Technician|null
     */
    private function teknisiUji(): ?Technician
    {
        $id = $this->option('teknisi');

        if ($id === null || $id === '') {
            return null;
        }

        return Technician::query()->find($id);
    }
}
