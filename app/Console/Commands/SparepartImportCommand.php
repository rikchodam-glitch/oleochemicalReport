<?php

namespace App\Console\Commands;

use App\Exceptions\SparepartFormatException;
use App\Models\SpMaterial;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Impor berkas Integrasi PR/PO Spare Part dari baris perintah.
 *
 * Dipakai sebagai alternatif halaman unggah (mis. untuk pengujian atau saat
 * berkas berada di server): keempat berkas sumber boleh sebagian, dan daftar
 * pantau dapat ikut diimpor lewat opsi --pantau.
 */
class SparepartImportCommand extends Command
{
    protected $signature = 'sparepart:import
        {--stok= : Path laporan stok gudang (xlsx)}
        {--pr= : Path EXPORT PR (xlsx, SAP ME5A)}
        {--po= : Path EXPORT PO (xlsx, SAP ME2M)}
        {--mrp= : Path EXPORT MRP (xlsx, SAP MD07/MD04)}
        {--pantau= : Path berkas daftar pantau (xlsx sheet MASTER DATA atau CSV)}
        {--ganti-pantau : Daftar pantau diganti seluruhnya dengan isi berkas --pantau}
        {--label= : Catatan label import}
        {--oleh= : Id pengguna yang menjalankan import}';

    protected $description = 'Impor keadaan Spare Part (stok gudang, PR, PO, MRP) dan perbarui tabel per kode material';

    /**
     * Menjalankan import dan menampilkan analisanya.
     *
     * @param  SparepartImportService $layanan Import keadaan material
     * @param  SparepartPantauService $pantau  Pengelola daftar pantau
     * @return int                             Kode keluar (SUCCESS atau FAILURE)
     */
    public function handle(SparepartImportService $layanan, SparepartPantauService $pantau): int
    {
        $oleh  = (string) ($this->option('oleh') ?? '');
        $label = (string) ($this->option('label') ?? '');

        if ($oleh !== '' && ! is_numeric($oleh)) {
            $this->error('Opsi --oleh harus berupa angka id pengguna.');

            return self::FAILURE;
        }

        try {
            if ((string) ($this->option('pantau') ?? '') !== '') {
                $this->info('Mengimpor daftar pantau...');

                $hasil = $pantau->imporDariBerkas(
                    (string) $this->option('pantau'),
                    $this->option('ganti-pantau') ? SparepartPantauService::MODE_GANTI : SparepartPantauService::MODE_GABUNG
                );

                $this->line(sprintf(
                    'Daftar pantau %s: %d kode (baru %d, diperbarui %d, dihapus %d).',
                    $hasil['nama'],
                    $hasil['kode'],
                    $hasil['baru'],
                    $hasil['diperbarui'],
                    $hasil['dihapus']
                ));
            }

            $impor = $layanan->import(
                [
                    SparepartImportService::SUMBER_STOK => (string) ($this->option('stok') ?? '') ?: null,
                    SparepartImportService::SUMBER_PR   => (string) ($this->option('pr') ?? '') ?: null,
                    SparepartImportService::SUMBER_PO   => (string) ($this->option('po') ?? '') ?: null,
                    SparepartImportService::SUMBER_MRP  => (string) ($this->option('mrp') ?? '') ?: null,
                ],
                $oleh === '' ? null : (int) $oleh,
                $label === '' ? null : $label
            );
        } catch (SparepartFormatException $e) {
            $this->error('Format berkas tidak sesuai: ' . $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Impor gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $analisa = (array) ($impor->analisa ?? []);

        $this->info(sprintf('Impor #%d selesai.', $impor->id));

        foreach ((array) ($analisa['sumber'] ?? []) as $kunci => $rincian) {
            if (empty($rincian['dipakai'])) {
                $this->line(sprintf('  %-5s : tidak diunggah', $kunci));
                continue;
            }

            $this->line(sprintf(
                '  %-5s : %s (%d baris dibaca, %d diabaikan, %d material)',
                $kunci,
                $rincian['nama'],
                $rincian['baris'],
                $rincian['diabaikan'],
                $rincian['material']
            ));
        }

        $keadaan = (array) ($analisa['keadaan'] ?? []);
        $status  = (array) ($analisa['status'] ?? []);

        $this->line(sprintf(
            'Keadaan: baru %d, diperbarui %d, tidak berubah %d.',
            $keadaan['baru'] ?? 0,
            $keadaan['diperbarui'] ?? 0,
            $keadaan['tetap'] ?? 0
        ));

        $this->line(sprintf(
            'Status stok: REORDER %d, aman %d, belum dinilai %d.',
            $status['reorder'] ?? 0,
            $status['aman'] ?? 0,
            $status['belum_dinilai'] ?? 0
        ));

        foreach ((array) ($analisa['aksi'] ?? []) as $aksi => $jumlah) {
            $this->line(sprintf('  %-26s %d', SpMaterial::labelAksi((string) $aksi), $jumlah));
        }

        foreach ((array) ($analisa['catatan'] ?? []) as $catatan) {
            $this->warn('  ' . $catatan);
        }

        return self::SUCCESS;
    }
}
