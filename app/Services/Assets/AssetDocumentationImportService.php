<?php

namespace App\Services\Assets;

use App\Models\Asset;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Impor dokumentasi foto equipment dari folder ke kolom assets.photos.
 *
 * Pencocokan memakai tech_ident_no dan equipment_no aset. Folder leluhur
 * terdekat yang cocok menang; jika tidak ada, nama file dicocokkan persis,
 * lalu tanpa spasi/tanda hubung, lalu tag yang tertanam di nama file.
 *
 * Mode bawaan adalah dry-run. Penulisan file dan perubahan database hanya
 * dilakukan bila opsi apply bernilai true.
 */
class AssetDocumentationImportService
{
    public const DISK = 'public';

    /**
     * Ekstensi gambar yang diterima secara bawaan.
     */
    private const EXT_DEFAULT = ['jpg', 'jpeg', 'png'];

    /**
     * Folder yang selalu dilewati.
     */
    private const SKIP_DIR_DEFAULT = ['__macosx'];

    /**
     * Panjang minimum tag untuk pencocokan di tengah nama file.
     */
    private const SMART_MIN_LENGTH = 5;

    private string $root = '';
    private bool $apply = false;
    private bool $smart = true;
    private int $limit = 0;

    /** @var array<int, string> */
    private array $ext = self::EXT_DEFAULT;

    /** @var array<int, string> */
    private array $skipDirs = self::SKIP_DIR_DEFAULT;

    private string $laporan = '';
    private string $laporanJson = '';
    private string $backup = '';

    /** @var callable|null */
    private $progress = null;

    /** @var array<string, array<int, true>> */
    private array $exact = [];

    /** @var array<string, array<int, true>> */
    private array $compact = [];

    /** @var array<int, array<string, mixed>> */
    private array $asets = [];

    /** @var array<int, array<string, string>> */
    private array $hashLama = [];

    /** @var array<int, array<string, true>> */
    private array $hashBaru = [];

    /** @var array<int, array<string, array<string, mixed>>> */
    private array $baru = [];

    /** @var array<int, true> */
    private array $asetCocok = [];

    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    /** @var array<string, int> */
    private array $indeksItem = [];

    /** @var array<string, int> */
    private array $ringkasan = [];

    /**
     * Jalankan pemindaian dan (bila apply) penyalinan foto.
     *
     * @param  string                $root Folder sumber dokumentasi
     * @param  array<string, mixed>  $opsi apply, report, limit, tag, ext, skip_dir, smart, progress
     * @return array<string, mixed>
     */
    public function jalankan(string $root, array $opsi = []): array
    {
        $this->root = rtrim(str_replace('/', '\\', $root), '\\');

        if (! is_dir($this->root)) {
            return [
                'ok'     => false,
                'error'  => 'Folder sumber tidak ditemukan: ' . $this->root,
            ];
        }

        $this->apply    = (bool) ($opsi['apply'] ?? false);
        $this->smart    = (bool) ($opsi['smart'] ?? true);
        $this->limit    = max(0, (int) ($opsi['limit'] ?? 0));
        $this->ext      = $this->normalisasiExt($opsi['ext'] ?? self::EXT_DEFAULT);
        $this->skipDirs = $this->normalisasiSkipDir($opsi['skip_dir'] ?? self::SKIP_DIR_DEFAULT);
        $this->progress = is_callable($opsi['progress'] ?? null) ? $opsi['progress'] : null;

        if ($this->ext === []) {
            return [
                'ok'    => false,
                'error' => 'Daftar ekstensi gambar kosong.',
            ];
        }

        $this->tentukanLaporan($opsi['report'] ?? null);
        $this->ringkasan = $this->ringkasanAwal();
        $this->muatAset((string) ($opsi['tag'] ?? ''));

        $this->pindai();
        $this->ringkasan['aset_cocok'] = count($this->asetCocok);

        if ($this->apply && $this->baru !== []) {
            $backup = $this->tulisBackup();

            if (! $backup['ok']) {
                return [
                    'ok'         => false,
                    'error'      => $backup['error'],
                    'ringkasan'  => $this->ringkasan,
                    'laporan'    => $this->laporan,
                ];
            }

            $this->terapkan();
        }

        $laporan = $this->tulisLaporan();

        if (! $laporan['ok']) {
            return [
                'ok'        => false,
                'error'     => $laporan['error'],
                'ringkasan' => $this->ringkasan,
            ];
        }

        return [
            'ok'        => true,
            'ringkasan' => $this->ringkasan,
            'laporan'   => $this->laporan,
            'json'      => $this->laporanJson,
            'backup'    => $this->apply && $this->baru !== [] ? $this->backup : null,
            'disk'      => self::DISK,
            'disk_path' => $this->diskPath(),
        ];
    }

    /**
     * Muat aset dan indeks tag ke memori.
     *
     * @param  string $tagFilter Batasi ke satu tag aset (kosong = semua)
     * @return void
     */
    private function muatAset(string $tagFilter): void
    {
        $query = Asset::query()->select(['id', 'tech_ident_no', 'equipment_no', 'photos']);

        if (trim($tagFilter) !== '') {
            $tag = strtoupper(trim($tagFilter));

            $query->where(function ($sub) use ($tag): void {
                $sub->whereRaw('UPPER(TRIM(tech_ident_no)) = ?', [$tag])
                    ->orWhereRaw('UPPER(TRIM(equipment_no)) = ?', [$tag]);
            });
        }

        foreach ($query->get() as $aset) {
            $this->asets[$aset->id] = [
                'id'           => $aset->id,
                'tech_ident_no'=> $aset->tech_ident_no,
                'equipment_no' => $aset->equipment_no,
                'photos'       => $aset->photos ?? [],
            ];

            foreach ([$aset->tech_ident_no, $aset->equipment_no] as $tag) {
                $exact = $this->exactValue($tag);

                if ($exact === '') {
                    continue;
                }

                $this->exact[$exact][$aset->id] = true;
                $compact = $this->compactValue($tag);

                if ($compact !== '' && strlen($compact) >= 3) {
                    $this->compact[$compact][$aset->id] = true;
                }
            }

            $this->hashFotoLama($aset->id);
        }
    }

    /**
     * Hitung hash foto yang sudah ada agar import ulang tidak menggandakan.
     *
     * @param  int $assetId
     * @return void
     */
    private function hashFotoLama(int $assetId): void
    {
        foreach (($this->asets[$assetId]['photos'] ?? []) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            try {
                $disk = Storage::disk(self::DISK);

                if (! $disk->exists($path)) {
                    continue;
                }

                $lengkap = $disk->path($path);
                $hash    = @hash_file('sha256', $lengkap);

                if ($hash !== false) {
                    $this->hashLama[$assetId][$hash] = $path;
                }
            } catch (Throwable $galat) {
                Log::warning('Import dokumentasi: gagal hash foto lama', [
                    'asset_id' => $assetId,
                    'foto'     => $path,
                    'error'    => $galat->getMessage(),
                ]);
            }
        }
    }

    /**
     * Pindai seluruh file sumber.
     *
     * @return void
     */
    private function pindai(): void
    {
        $direktori = new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS);
        $filter    = new RecursiveCallbackFilterIterator(
            $direktori,
            function (SplFileInfo $current): bool {
                if (! $current->isDir()) {
                    return true;
                }

                return ! in_array(strtolower($current->getFilename()), $this->skipDirs, true);
            }
        );
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $this->ringkasan['berkas']++;
            $ext = strtolower($file->getExtension());

            if (! in_array($ext, $this->ext, true)) {
                $this->ringkasan['dilewati_ekstensi']++;
                $this->tambahBerkas($file, 'dilewati_ekstensi', 'Ekstensi .' . $ext . ' tidak termasuk.');
                continue;
            }

            if ($this->limit > 0 && $this->ringkasan['gambar'] >= $this->limit) {
                break;
            }

            $this->ringkasan['gambar']++;
            $nama = $file->getFilename();

            if (str_starts_with($nama, '.') || str_starts_with($nama, '._')) {
                $this->ringkasan['dilewati_tersembunyi']++;
                $this->tambahBerkas($file, 'dilewati_tersembunyi', 'Berkas tersembunyi/AppleDouble.');
                continue;
            }

            try {
                $ukuran = $file->getSize();
            } catch (Throwable $galat) {
                $this->ringkasan['gagal']++;
                $this->tambahBerkas($file, 'gagal', $galat->getMessage());
                continue;
            }

            if ($ukuran <= 0) {
                $this->ringkasan['dilewati_kosong']++;
                $this->tambahBerkas($file, 'dilewati_kosong', 'Ukuran file 0 byte.');
                continue;
            }

            if (@getimagesize($file->getPathname()) === false) {
                $this->ringkasan['dilewati_rusak']++;
                $this->tambahBerkas($file, 'dilewati_rusak', 'Berkas tidak terbaca sebagai gambar.');
                continue;
            }

            $this->prosesGambar($file, $ext, $ukuran);

            if ($this->progress !== null && $this->ringkasan['gambar'] % 100 === 0) {
                call_user_func($this->progress, $this->ringkasan['gambar']);
            }
        }
    }

    /**
     * Proses satu gambar menjadi baris laporan dan kandidat foto aset.
     *
     * @param  SplFileInfo $file
     * @param  string      $ext
     * @param  int         $ukuran
     * @return void
     */
    private function prosesGambar(SplFileInfo $file, string $ext, int $ukuran): void
    {
        $path   = $file->getPathname();
        $rel    = $this->relatif($path);
        $cocok  = $this->cocokkan($path);
        $dasar  = [
            'source'         => $path,
            'source_rel'     => $rel,
            'ext'            => $ext,
            'bytes'          => $ukuran,
        ];

        if ($cocok['status'] === 'ambigu') {
            $this->ringkasan['ambigu']++;
            $this->tambahBaris(array_merge($dasar, [
                'status'   => 'ambigu',
                'catatan'  => $cocok['catatan'] . ' Kandidat: ' . implode(', ', $cocok['ids']),
                'asset_id' => implode('; ', $cocok['ids']),
            ]));

            return;
        }

        if ($cocok['status'] === 'belum_cocok') {
            $this->ringkasan['belum_cocok']++;
            $this->tambahBaris(array_merge($dasar, [
                'status'  => 'belum_cocok',
                'catatan' => 'Tidak ada folder/nama file yang cocok dengan tag aset.',
            ]));

            return;
        }

        $assetId = (int) $cocok['id'];
        $hash    = @hash_file('sha256', $path);

        if ($hash === false) {
            $this->ringkasan['gagal']++;
            $this->tambahBaris(array_merge($dasar, [
                'status'   => 'gagal',
                'catatan'  => 'Gagal menghitung hash file.',
                'asset_id' => $assetId,
            ]));

            return;
        }

        $aset = $this->asets[$assetId];
        $this->asetCocok[$assetId] = true;

        if (isset($this->hashBaru[$assetId][$hash]) || isset($this->hashLama[$assetId][$hash])) {
            $this->ringkasan['duplikat']++;
            $this->tambahBaris(array_merge($dasar, [
                'status'         => 'duplikat',
                'catatan'        => 'Isi file sama dengan foto lain pada aset ini.',
                'asset_id'       => $assetId,
                'tech_ident_no'  => $aset['tech_ident_no'],
                'equipment_no'   => $aset['equipment_no'],
                'hash'           => $hash,
                'cocok'          => $this->labelCocok($cocok['how']),
            ]));

            return;
        }

        $tujuan = 'assets/photos/' . $assetId . '/' . $hash . '.' . $ext;

        $this->hashBaru[$assetId][$hash] = true;
        $this->baru[$assetId][$hash]     = [
            'source' => $path,
            'tujuan' => $tujuan,
            'bytes'  => $ukuran,
        ];

        $this->ringkasan['cocok']++;
        $this->ringkasan['bytes_cocok'] += $ukuran;
        $this->ringkasan['cocok_' . $cocok['how']] = ($this->ringkasan['cocok_' . $cocok['how']] ?? 0) + 1;

        $index = $this->tambahBaris(array_merge($dasar, [
            'status'        => 'cocok',
            'catatan'       => $cocok['catatan'],
            'asset_id'      => $assetId,
            'tech_ident_no' => $aset['tech_ident_no'],
            'equipment_no'  => $aset['equipment_no'],
            'hash'          => $hash,
            'tujuan'        => $tujuan,
            'cocok'         => $this->labelCocok($cocok['how']),
        ]));

        $this->indeksItem[$path] = $index;
    }

    /**
     * Cocokkan satu path gambar ke aset.
     *
     * @param  string $path
     * @return array<string, mixed>
     */
    private function cocokkan(string $path): array
    {
        $folder = $this->cariFolder($path);
        $file   = $this->cariTag(pathinfo($path, PATHINFO_FILENAME));

        if ($file !== null) {
            $file['how'] = 'file_' . $file['how'];
        }

        $smart = null;

        if ($file === null && $this->smart) {
            $smart = $this->cariSmart(pathinfo($path, PATHINFO_FILENAME));
        }

        if ($folder !== null) {
            return $this->pilihCocok($folder, $file, 'folder');
        }

        if ($file !== null) {
            return $this->pilihCocok($file, null, 'file');
        }

        if ($smart !== null) {
            return $this->pilihCocok($smart, null, 'smart');
        }

        return ['status' => 'belum_cocok'];
    }

    /**
     * Tentukan aset pemenang dari hasil pencocokan folder/file/smart.
     *
     * @param  array<string, mixed>      $utama
     * @param  array<string, mixed>|null $pembanding
     * @param  string                    $jenis
     * @return array<string, mixed>
     */
    private function pilihCocok(array $utama, ?array $pembanding, string $jenis): array
    {
        $ids = $utama['ids'];
        $catatan = '';

        if (count($ids) > 1) {
            if ($pembanding !== null
                && count($pembanding['ids']) === 1
                && in_array($pembanding['ids'][0], $ids, true)) {
                $catatan = 'Kandidat utama ambigu; nama file memilih aset ' . $pembanding['ids'][0] . '.';
                $ids     = [$pembanding['ids'][0]];
            } else {
                return [
                    'status'  => 'ambigu',
                    'ids'     => $ids,
                    'catatan' => 'Kandidat ' . $jenis . ' cocok ke beberapa aset.',
                ];
            }
        }

        if ($pembanding !== null
            && count($pembanding['ids']) === 1
            && $pembanding['ids'][0] !== $ids[0]
            && count($utama['ids']) === 1) {
            $catatan = 'Folder/utama cocok ke aset ' . $ids[0]
                . ', nama file cocok ke aset ' . $pembanding['ids'][0]
                . '; kandidat utama dipakai.';
        }

        if ($jenis === 'smart' && isset($utama['sub'])) {
            $catatan = trim($catatan . ' Smart match: ' . $utama['sub']);
        }

        return [
            'status'  => 'cocok',
            'id'      => $ids[0],
            'how'     => $utama['how'],
            'catatan' => $catatan,
        ];
    }

    /**
     * Cari folder leluhur terdekat yang cocok dengan tag aset.
     *
     * @param  string $path
     * @return array<string, mixed>|null
     */
    private function cariFolder(string $path): ?array
    {
        $root = rtrim($this->root, '\\');
        $dir  = dirname($path);

        while (strlen($dir) >= strlen($root) && strcasecmp($dir, $root) !== 0) {
            $cocok = $this->cariTag(basename($dir));

            if ($cocok !== null) {
                $cocok['how']  = 'folder_' . $cocok['how'];
                $cocok['nama'] = basename($dir);

                return $cocok;
            }

            $induk = dirname($dir);

            if ($induk === $dir) {
                break;
            }

            $dir = $induk;
        }

        return null;
    }

    /**
     * Cocokkan satu nilai (nama folder/file) dengan indeks tag aset.
     *
     * @param  string $nilai
     * @return array<string, mixed>|null
     */
    private function cariTag(string $nilai): ?array
    {
        $exact = $this->exactValue($nilai);

        if ($exact !== '' && isset($this->exact[$exact])) {
            return ['ids' => array_keys($this->exact[$exact]), 'how' => 'exact'];
        }

        $compact = $this->compactValue($nilai);

        if ($compact !== '' && isset($this->compact[$compact])) {
            return ['ids' => array_keys($this->compact[$compact]), 'how' => 'compact'];
        }

        return null;
    }

    /**
     * Cari tag yang tertanam di dalam nama file deskriptif.
     *
     * @param  string $nama
     * @return array<string, mixed>|null
     */
    private function cariSmart(string $nama): ?array
    {
        $compact = $this->compactValue($nama);
        $panjang = strlen($compact);

        if ($panjang < self::SMART_MIN_LENGTH) {
            return null;
        }

        for ($len = min(16, $panjang); $len >= self::SMART_MIN_LENGTH; $len--) {
            $ids  = [];
            $sub  = null;

            for ($i = 0; $i + $len <= $panjang; $i++) {
                $potong = substr($compact, $i, $len);

                if (! isset($this->compact[$potong])) {
                    continue;
                }

                foreach (array_keys($this->compact[$potong]) as $id) {
                    $ids[$id] = true;
                }

                $sub ??= $potong;
            }

            if ($ids !== []) {
                return [
                    'ids' => array_keys($ids),
                    'how' => 'smart',
                    'sub' => $sub,
                ];
            }
        }

        return null;
    }

    /**
     * Salin foto dan perbarui assets.photos.
     *
     * @return void
     */
    private function terapkan(): void
    {
        foreach ($this->baru as $assetId => $daftar) {
            $aset = Asset::query()->find($assetId);

            if ($aset === null) {
                foreach ($daftar as $item) {
                    $this->tandaiGagal($item['source'], 'Aset tidak ditemukan saat apply.');
                }

                continue;
            }

            $paths = [];

            foreach ($daftar as $item) {
                $tujuan = $item['tujuan'];

                try {
                    $disk = Storage::disk(self::DISK);

                    if ($disk->exists($tujuan) && (int) $disk->size($tujuan) === (int) $item['bytes']) {
                        $paths[] = $tujuan;
                        $this->ringkasan['foto_sudah_ada']++;
                        $this->tandaiStatus($item['source'], 'sudah_ada', 'File sudah ada di storage; path ditambahkan ke aset.');
                        continue;
                    }

                    $stream = @fopen($item['source'], 'rb');

                    if ($stream === false) {
                        throw new \RuntimeException('Gagal membuka file sumber.');
                    }

                    try {
                        $ok = Storage::disk(self::DISK)->put($tujuan, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    if (! $ok) {
                        throw new \RuntimeException('Storage gagal menulis file tujuan.');
                    }

                    $paths[] = $tujuan;
                    $this->ringkasan['foto_disalin']++;
                    $this->ringkasan['bytes_disalin'] += (int) $item['bytes'];
                    $this->tandaiStatus($item['source'], 'disalin', 'Foto disalin ke storage.');
                } catch (Throwable $galat) {
                    $this->ringkasan['gagal']++;
                    $this->tandaiGagal($item['source'], $galat->getMessage());
                }
            }

            if ($paths === []) {
                continue;
            }

            $lama = $aset->photos ?? [];
            $baru = array_values(array_unique(array_merge($lama, $paths)));

            if ($baru === $lama) {
                continue;
            }

            try {
                $aset->update(['photos' => $baru]);
                $this->ringkasan['aset_diupdate']++;
                $this->ringkasan['foto_ditambahkan'] += count($baru) - count($lama);
            } catch (Throwable $galat) {
                $this->ringkasan['gagal']++;
                Log::error('Import dokumentasi: gagal update aset', [
                    'asset_id' => $assetId,
                    'error'    => $galat->getMessage(),
                ]);

                foreach ($paths as $path) {
                    if (! in_array($path, $lama, true)) {
                        Storage::disk(self::DISK)->delete($path);
                    }
                }

                foreach ($daftar as $item) {
                    $this->tandaiGagal($item['source'], 'Gagal menyimpan path ke aset.');
                }
            }
        }
    }

    /**
     * Tulis backup daftar foto lama aset yang akan berubah.
     *
     * @return array<string, mixed>
     */
    private function tulisBackup(): array
    {
        $data = [];

        foreach (array_keys($this->baru) as $assetId) {
            $aset = $this->asets[$assetId] ?? null;

            if ($aset === null) {
                continue;
            }

            $data[] = [
                'id'            => $aset['id'],
                'tech_ident_no' => $aset['tech_ident_no'],
                'equipment_no'  => $aset['equipment_no'],
                'photos'        => $aset['photos'],
            ];
        }

        try {
            File::ensureDirectoryExists(dirname($this->backup));

            $tertulis = File::put(
                $this->backup,
                json_encode([
                    'dibuat_at' => now()->toIso8601String(),
                    'sumber'    => $this->root,
                    'aset'      => $data,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
            );

            if ($tertulis === false) {
                throw new \RuntimeException('File backup tidak bisa ditulis.');
            }
        } catch (Throwable $galat) {
            return [
                'ok'    => false,
                'error' => 'Gagal menulis backup foto: ' . $galat->getMessage(),
            ];
        }

        return ['ok' => true];
    }

    /**
     * Tulis laporan CSV dan ringkasan JSON.
     *
     * @return array<string, mixed>
     */
    private function tulisLaporan(): array
    {
        try {
            File::ensureDirectoryExists(dirname($this->laporan));

            $handle = fopen($this->laporan, 'wb');

            if ($handle === false) {
                throw new \RuntimeException('Gagal membuka laporan CSV untuk ditulis.');
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'status',
                'catatan',
                'asset_id',
                'tech_ident_no',
                'equipment_no',
                'source_rel',
                'source',
                'ext',
                'bytes',
                'hash',
                'tujuan',
                'cocok',
            ]);

            foreach ($this->items as $item) {
                fputcsv($handle, [
                    $item['status'] ?? '',
                    $item['catatan'] ?? '',
                    $item['asset_id'] ?? '',
                    $item['tech_ident_no'] ?? '',
                    $item['equipment_no'] ?? '',
                    $item['source_rel'] ?? '',
                    $item['source'] ?? '',
                    $item['ext'] ?? '',
                    $item['bytes'] ?? 0,
                    $item['hash'] ?? '',
                    $item['tujuan'] ?? '',
                    $item['cocok'] ?? '',
                ]);
            }

            fclose($handle);

            $ringkasanAset = [];

            foreach (array_keys($this->baru) as $assetId) {
                $aset = $this->asets[$assetId] ?? null;

                if ($aset === null) {
                    continue;
                }

                $ringkasanAset[] = [
                    'id'            => $aset['id'],
                    'tech_ident_no' => $aset['tech_ident_no'],
                    'equipment_no'  => $aset['equipment_no'],
                    'photos_lama'   => $aset['photos'],
                    'foto_baru'     => array_values(array_map(
                        static fn (array $item): string => $item['tujuan'],
                        $this->baru[$assetId]
                    )),
                ];
            }

            $tertulis = File::put(
                $this->laporanJson,
                json_encode([
                    'dibuat_at' => now()->toIso8601String(),
                    'mode'      => $this->apply ? 'apply' : 'dry-run',
                    'sumber'    => $this->root,
                    'disk'      => self::DISK,
                    'disk_path' => $this->diskPath(),
                    'ringkasan' => $this->ringkasan,
                    'aset'      => $ringkasanAset,
                    'laporan'   => $this->laporan,
                    'backup'    => $this->apply && $this->baru !== [] ? $this->backup : null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
            );

            if ($tertulis === false) {
                throw new \RuntimeException('Ringkasan JSON tidak bisa ditulis.');
            }
        } catch (Throwable $galat) {
            return [
                'ok'    => false,
                'error' => 'Gagal menulis laporan: ' . $galat->getMessage(),
            ];
        }

        return ['ok' => true];
    }

    /**
     * Tambah satu baris laporan dan kembalikan indeksnya.
     *
     * @param  array<string, mixed> $baris
     * @return int
     */
    private function tambahBaris(array $baris): int
    {
        $this->items[] = $baris;

        return array_key_last($this->items);
    }

    /**
     * Tambah baris laporan untuk berkas yang tidak diproses.
     *
     * @param  SplFileInfo $file
     * @param  string      $status
     * @param  string      $catatan
     * @return void
     */
    private function tambahBerkas(SplFileInfo $file, string $status, string $catatan): void
    {
        try {
            $ukuran = $file->getSize();
        } catch (Throwable) {
            $ukuran = 0;
        }

        $this->tambahBaris([
            'status'     => $status,
            'catatan'    => $catatan,
            'source'     => $file->getPathname(),
            'source_rel' => $this->relatif($file->getPathname()),
            'ext'        => strtolower($file->getExtension()),
            'bytes'      => $ukuran,
        ]);
    }

    /**
     * Ubah status baris laporan hasil apply.
     *
     * @param  string $source
     * @param  string $status
     * @param  string $catatan
     * @return void
     */
    private function tandaiStatus(string $source, string $status, string $catatan): void
    {
        $index = $this->indeksItem[$source] ?? null;

        if ($index === null) {
            return;
        }

        $this->items[$index]['status'] = $status;
        $this->items[$index]['catatan'] = trim(($this->items[$index]['catatan'] ?? '') . ' ' . $catatan);
    }

    /**
     * Tandai baris laporan sebagai gagal.
     *
     * @param  string $source
     * @param  string $catatan
     * @return void
     */
    private function tandaiGagal(string $source, string $catatan): void
    {
        $this->tandaiStatus($source, 'gagal', $catatan);
    }

    /**
     * Path relatif sumber terhadap root dokumentasi.
     *
     * @param  string $path
     * @return string
     */
    private function relatif(string $path): string
    {
        $path = str_replace('/', '\\', $path);
        $root = $this->root . '\\';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Tentukan lokasi laporan CSV dan JSON.
     *
     * @param  mixed $report
     * @return void
     */
    private function tentukanLaporan(mixed $report): void
    {
        $default = storage_path('app/imports/dokumentasi-' . now()->format('Ymd-His') . '.csv');
        $path    = is_string($report) && trim($report) !== '' ? trim($report) : $default;

        if (! preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2})/', $path)) {
            $path = base_path($path);
        }

        $this->laporan     = $path;
        $this->laporanJson = preg_replace('/\.csv$/i', '', $path) . '.json';
        $this->backup      = dirname($path) . DIRECTORY_SEPARATOR
            . 'backup-fotos-' . now()->format('Ymd-His') . '.json';
    }

    /**
     * Path fisik disk public yang sedang dipakai.
     *
     * @return string
     */
    private function diskPath(): string
    {
        $path = Storage::disk(self::DISK)->path('');

        return realpath($path) ?: $path;
    }

    /**
     * Normalisasi daftar ekstensi.
     *
     * @param  mixed $ext
     * @return array<int, string>
     */
    private function normalisasiExt(mixed $ext): array
    {
        $daftar = is_array($ext) ? $ext : explode(',', (string) $ext);
        $daftar = array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $daftar);
        $daftar = array_intersect($daftar, ['jpg', 'jpeg', 'png', 'webp']);

        return array_values(array_unique($daftar));
    }

    /**
     * Normalisasi daftar folder yang dilewati.
     *
     * @param  mixed $dirs
     * @return array<int, string>
     */
    private function normalisasiSkipDir(mixed $dirs): array
    {
        $daftar = is_array($dirs) ? $dirs : explode(',', (string) $dirs);
        $daftar = array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $daftar);
        $daftar = array_filter(array_merge($daftar, self::SKIP_DIR_DEFAULT));

        return array_values(array_unique($daftar));
    }

    /**
     * Nilai tag persis (huruf besar, trim).
     *
     * @param  mixed $nilai
     * @return string
     */
    private function exactValue(mixed $nilai): string
    {
        return strtoupper(trim((string) $nilai));
    }

    /**
     * Nilai tag tanpa spasi/tanda baca.
     *
     * @param  mixed $nilai
     * @return string
     */
    private function compactValue(mixed $nilai): string
    {
        return preg_replace('/[^A-Z0-9]/', '', $this->exactValue($nilai)) ?? '';
    }

    /**
     * Label jenis pencocokan untuk laporan.
     *
     * @param  string $how
     * @return string
     */
    private function labelCocok(string $how): string
    {
        return match ($how) {
            'folder_exact'   => 'Folder (persis)',
            'folder_compact' => 'Folder (abaikan spasi/tanda)',
            'file_exact'     => 'Nama file (persis)',
            'file_compact'   => 'Nama file (abaikan spasi/tanda)',
            'smart'          => 'Tag di nama file',
            default          => $how,
        };
    }

    /**
     * Ringkasan awal seluruh penghitung.
     *
     * @return array<string, int>
     */
    private function ringkasanAwal(): array
    {
        return [
            'berkas'              => 0,
            'gambar'              => 0,
            'cocok'               => 0,
            'belum_cocok'         => 0,
            'ambigu'              => 0,
            'duplikat'            => 0,
            'dilewati_ekstensi'   => 0,
            'dilewati_tersembunyi'=> 0,
            'dilewati_kosong'     => 0,
            'dilewati_rusak'      => 0,
            'cocok_folder_exact'  => 0,
            'cocok_folder_compact'=> 0,
            'cocok_file_exact'    => 0,
            'cocok_file_compact'  => 0,
            'cocok_smart'         => 0,
            'aset_cocok'          => 0,
            'aset_diupdate'       => 0,
            'foto_disalin'        => 0,
            'foto_sudah_ada'      => 0,
            'foto_ditambahkan'    => 0,
            'gagal'               => 0,
            'bytes_cocok'         => 0,
            'bytes_disalin'       => 0,
        ];
    }
}
