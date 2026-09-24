<?php

namespace App\Console\Commands;

use App\Services\CollaborationService;
use Illuminate\Console\Command;

class CollabSyncGroupsCommand extends Command
{
    protected $signature = 'collab:sync-groups
                            {--with-reguler : Sekalian membuat kelompok Reguler (otomasi dimatikan)}';

    protected $description = 'Buat kelompok kolaborasi dari grup shift teknisi (Grub A sampai D) dan isi anggotanya';

    /**
     * Buat kelompok kolaborasi bawaan dari grup teknisi.
     *
     * Anggota awal diambil dari kolom technicians.group yang sama dengan kode
     * kelompok (mis. grub_d -> teknisi bergrup grub_d). Setelah dibuat, susunan
     * anggota bisa diubah lewat halaman Kolaborasi tanpa mengubah data teknisi.
     * Perintah ini idempoten: kelompok yang sudah ada tidak dibuat ulang, hanya
     * anggotanya diselaraskan.
     *
     * @param  CollaborationService  $kolaborasi
     * @return int
     */
    public function handle(CollaborationService $kolaborasi): int
    {
        $hasil = $kolaborasi->sinkronkanKelompokGrupTeknisi((bool) $this->option('with-reguler'));

        if ($hasil === []) {
            $this->warn('Tidak ada grup teknisi yang diproses.');

            return self::SUCCESS;
        }

        foreach ($hasil as $baris) {
            $this->line(sprintf(
                '%-10s %-8s anggota baru: %d',
                $baris['code'],
                $baris['created'] ? '(dibuat)' : '(sudah ada)',
                $baris['members']
            ));
        }

        $this->info('Kelompok kolaborasi selesai disiapkan.');

        return self::SUCCESS;
    }
}
