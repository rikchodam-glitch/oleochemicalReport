<?php

namespace App\Services\Sparepart;

use Illuminate\Http\UploadedFile;

/**
 * Penyimpanan sementara berkas unggahan Spare Part.
 *
 * Berkas unggahan PHP tidak punya ekstensi, sedangkan pembaca Excel mengenali
 * jenis berkas dari ekstensinya. Karena itu unggahan disimpan lebih dulu ke
 * folder sementara dengan ekstensi aslinya, dibaca, lalu dihapus lagi.
 */
class SparepartUnggahan
{
    /** Folder sementara (relatif terhadap storage) untuk berkas unggahan. */
    public const FOLDER = 'sparepart-unggahan';

    /**
     * Menyimpan berkas unggahan ke folder sementara.
     *
     * @param  UploadedFile $berkas Berkas unggahan
     * @param  string       $kunci  Kunci sumber (stok/pr/po/pantau)
     * @return string               Path berkas sementara
     */
    public function simpan(UploadedFile $berkas, string $kunci): string
    {
        $folder = storage_path('app/' . self::FOLDER);

        if (! is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        $ekstensi = strtolower($berkas->getClientOriginalExtension() ?: 'xlsx');
        $nama     = $kunci . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ekstensi;

        $berkas->move($folder, $nama);

        return $folder . DIRECTORY_SEPARATOR . $nama;
    }

    /**
     * Menghapus berkas sementara yang sudah dipakai.
     *
     * @param  array<string, string> $berkas Path berkas per kunci sumber
     * @return void
     */
    public function hapus(array $berkas): void
    {
        foreach ($berkas as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
