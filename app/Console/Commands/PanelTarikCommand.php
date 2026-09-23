<?php

namespace App\Console\Commands;

use App\Models\PanelRuangan;
use App\Services\PanelSensorService;
use Illuminate\Console\Command;

/**
 * Perintah terjadwal penarik data sensor ruang panel.
 *
 * Dijalankan tiap menit oleh scheduler (lihat routes/console.php). Karena
 * server yang menarik data, tidak ada port masuk yang perlu dibuka di
 * firewall komputer ini.
 */
class PanelTarikCommand extends Command
{
    protected $signature = 'panel:tarik {--ruangan= : ID ruangan tertentu saja (opsional)}';

    protected $description = 'Tarik data suhu/kelembapan dari perangkat sensor ruang panel lalu simpan ke database';

    /**
     * Jalankan penarikan data untuk satu ruangan atau seluruh ruangan aktif.
     *
     * @param  PanelSensorService  $sensor
     * @return int Kode keluar perintah.
     */
    public function handle(PanelSensorService $sensor): int
    {
        $idRuangan = $this->option('ruangan');

        if ($idRuangan !== null) {
            $ruangan = PanelRuangan::query()->find($idRuangan);

            if ($ruangan === null) {
                $this->error('Ruangan panel dengan ID ' . $idRuangan . ' tidak ditemukan.');

                return self::FAILURE;
            }

            $hasil = $sensor->tarikPembacaan($ruangan);

            $this->line(sprintf(
                '  %-30s %s',
                $ruangan->nama_ruangan,
                $hasil['pesan']
            ));

            return $hasil['berhasil'] ? self::SUCCESS : self::FAILURE;
        }

        $ringkasan = $sensor->tarikSemua();

        // Ruangan yang dilewati (sedang offline) tetap dihitung sebagai
        // ruangan terdaftar, supaya pesan "belum ada ruangan" tidak salah muncul.
        if (($ringkasan['ruangan'] + $ringkasan['dilewati']) === 0) {
            $this->warn('Belum ada ruangan panel aktif yang terdaftar.');

            return self::SUCCESS;
        }

        foreach ($ringkasan['detail'] as $detail) {
            $this->line(sprintf('  %-30s %s', $detail['nama'], $detail['pesan']));
        }

        if ($ringkasan['dilewati'] > 0) {
            $this->line(sprintf(
                '  %d ruangan dilewati karena baru saja tidak bisa dihubungi (jeda diatur di config/panel.php).',
                $ringkasan['dilewati']
            ));
        }

        $this->info(sprintf(
            'Selesai: %d ruangan ditarik, %d terhubung, %d dilewati, %d pembacaan baru tersimpan.',
            $ringkasan['ruangan'],
            $ringkasan['berhasil'],
            $ringkasan['dilewati'],
            $ringkasan['baru']
        ));

        return self::SUCCESS;
    }
}
