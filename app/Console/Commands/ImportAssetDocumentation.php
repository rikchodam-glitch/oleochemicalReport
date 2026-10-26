<?php

namespace App\Console\Commands;

use App\Services\Assets\AssetDocumentationImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Impor dokumentasi foto equipment ke assets.photos.
 *
 * Mode bawaan adalah dry-run: hanya membaca folder sumber dan menulis laporan.
 * Tambahkan --apply untuk menyalin foto dan memperbarui database.
 */
class ImportAssetDocumentation extends Command
{
    protected $signature = 'assets:import-dokumentasi
        {--path= : Folder sumber dokumentasi}
        {--apply : Tulis foto dan perbarui assets.photos}
        {--report= : Lokasi laporan CSV}
        {--limit=0 : Batasi jumlah gambar yang diproses (0 = semua)}
        {--tag= : Batasi import ke satu tag aset}
        {--ext=jpg,jpeg,png : Ekstensi gambar yang diproses}
        {--skip-dir=__MACOSX : Folder yang dilewati (pisahkan dengan koma)}
        {--no-smart : Matikan pencocokan tag di tengah nama file}';

    protected $description = 'Impor dokumentasi foto equipment ke assets.photos (dry-run/apply)';

    /**
     * Jalankan perintah import.
     *
     * @param  AssetDocumentationImportService $layanan
     * @return int
     */
    public function handle(AssetDocumentationImportService $layanan): int
    {
        $path = trim((string) $this->option('path'));

        if ($path === '') {
            $this->error('Opsi --path wajib diisi. Contoh: --path="E:\\Dokumentasi Equipment"');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->info('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Sumber      : ' . $path);
        $this->line('Disk public : ' . $this->diskPath());
        $this->line('');

        $hasil = $layanan->jalankan($path, [
            'apply'    => $apply,
            'report'   => $this->option('report') ?: null,
            'limit'    => (int) $this->option('limit'),
            'tag'      => (string) $this->option('tag'),
            'ext'      => (string) $this->option('ext'),
            'skip_dir' => (string) $this->option('skip-dir'),
            'smart'    => ! $this->option('no-smart'),
            'progress' => function (int $jumlah): void {
                $this->line('  ' . number_format($jumlah, 0, ',', '.') . ' gambar diproses...');
            },
        ]);

        if (! $hasil['ok']) {
            $this->error($hasil['error']);

            return self::FAILURE;
        }

        $r = $hasil['ringkasan'];

        $this->table(
            ['Ringkasan', 'Jumlah'],
            [
                ['Berkas dipindai', number_format($r['berkas'], 0, ',', '.')],
                ['Gambar diproses', number_format($r['gambar'], 0, ',', '.')],
                ['Cocok', number_format($r['cocok'], 0, ',', '.')],
                ['- folder persis', number_format($r['cocok_folder_exact'], 0, ',', '.')],
                ['- folder abaikan spasi/tanda', number_format($r['cocok_folder_compact'], 0, ',', '.')],
                ['- nama file persis', number_format($r['cocok_file_exact'], 0, ',', '.')],
                ['- nama file abaikan spasi/tanda', number_format($r['cocok_file_compact'], 0, ',', '.')],
                ['- tag di nama file', number_format($r['cocok_smart'], 0, ',', '.')],
                ['Belum cocok', number_format($r['belum_cocok'], 0, ',', '.')],
                ['Ambigu', number_format($r['ambigu'], 0, ',', '.')],
                ['Duplikat', number_format($r['duplikat'], 0, ',', '.')],
                ['Dilewati: ekstensi', number_format($r['dilewati_ekstensi'], 0, ',', '.')],
                ['Dilewati: tersembunyi', number_format($r['dilewati_tersembunyi'], 0, ',', '.')],
                ['Dilewati: kosong', number_format($r['dilewati_kosong'], 0, ',', '.')],
                ['Dilewati: rusak', number_format($r['dilewati_rusak'], 0, ',', '.')],
                ['Aset cocok', number_format($r['aset_cocok'], 0, ',', '.')],
                ['Aset diperbarui', number_format($r['aset_diupdate'], 0, ',', '.')],
                [$apply ? 'Foto disalin' : 'Foto akan disalin', number_format($apply ? $r['foto_disalin'] : $r['cocok'], 0, ',', '.')],
                ['Foto sudah ada', number_format($r['foto_sudah_ada'], 0, ',', '.')],
                [$apply ? 'Foto ditambahkan ke aset' : 'Foto akan ditambahkan ke aset', number_format($apply ? $r['foto_ditambahkan'] : $r['cocok'], 0, ',', '.')],
                ['Gagal', number_format($r['gagal'], 0, ',', '.')],
                [$apply ? 'Ukuran disalin' : 'Perkiraan ukuran', $this->ukuran($apply ? (int) $r['bytes_disalin'] : (int) $r['bytes_cocok'])],
            ]
        );

        $this->info('Laporan CSV : ' . $hasil['laporan']);
        $this->line('Ringkasan JSON: ' . $hasil['json']);

        if ($hasil['backup'] !== null) {
            $this->line('Backup foto : ' . $hasil['backup']);
        }

        if (! $apply) {
            $this->warn('DRY-RUN: tidak ada foto atau data aset yang diubah. Periksa laporan, lalu jalankan ulang dengan --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Path fisik disk public yang dipakai saat ini.
     *
     * @return string
     */
    private function diskPath(): string
    {
        $path = Storage::disk(AssetDocumentationImportService::DISK)->path('');

        return realpath($path) ?: $path;
    }

    /**
     * Format ukuran byte menjadi GB/MB.
     *
     * @param  int $bytes
     * @return string
     */
    private function ukuran(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
        }

        return number_format($bytes, 0, ',', '.') . ' byte';
    }
}
