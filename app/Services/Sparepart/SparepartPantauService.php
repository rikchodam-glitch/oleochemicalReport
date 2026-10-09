<?php

namespace App\Services\Sparepart;

use App\Models\SpMaterial;
use App\Models\SpPantau;
use App\Services\Sparepart\Concerns\SparepartHitungTrait;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan daftar pantau Spare Part.
 *
 * Daftar pantau adalah sumber populasi halaman Integrasi PR/PO, karena itu
 * pengelolaannya berdiri sendiri: impor dari berkas (sheet MASTER DATA atau
 * CSV), tambah/ubah/hapus manual, dan perhitungan ulang status material yang
 * terdampak (stok minimum berubah berarti status REORDER bisa berubah).
 */
class SparepartPantauService
{
    use SparepartHitungTrait;

    /** Mode impor: gabungkan dengan daftar yang ada (tambah/perbarui). */
    public const MODE_GABUNG = 'gabung';

    /** Mode impor: ganti seluruh daftar dengan isi berkas. */
    public const MODE_GANTI = 'ganti';

    /**
     * @param  SparepartReader $pembaca Pembaca berkas daftar pantau
     */
    public function __construct(private SparepartReader $pembaca)
    {
    }

    /**
     * Mengimpor daftar pantau dari berkas.
     *
     * Baris yang sudah ada diperbarui isinya tetapi penanda sumbernya
     * dipertahankan (baris manual tetap bertanda manual) supaya terlihat mana
     * yang diisi tangan.
     *
     * @param  string $path Path berkas (xlsx sheet MASTER DATA atau CSV)
     * @param  string $mode Mode impor (gabung/ganti)
     * @return array<string, mixed> Rincian hasil impor daftar pantau
     */
    public function imporDariBerkas(string $path, string $mode = self::MODE_GABUNG): array
    {
        $baca = $this->pembaca->bacaPantau($path);
        $data = (array) $baca['data'];

        $hasil = [
            'nama'       => basename($path),
            'lembar'     => (array) ($baca['lembar'] ?? []),
            'mode'       => $mode === self::MODE_GANTI ? self::MODE_GANTI : self::MODE_GABUNG,
            'baris'      => (int) ($baca['baris'] ?? 0),
            'kode'       => count($data),
            'ganda'      => (int) ($baca['ganda'] ?? 0),
            'baru'       => 0,
            'diperbarui' => 0,
            'dihapus'    => 0,
            'catatan'    => (array) ($baca['catatan'] ?? []),
        ];

        $terdampak = [];

        DB::transaction(function () use ($data, &$hasil, &$terdampak): void {
            foreach ($data as $kode => $item) {
                $pantau = SpPantau::query()->where('kode_material', $kode)->first();

                if ($pantau === null) {
                    SpPantau::create([
                        'kode_material' => $kode,
                        'deskripsi'     => $this->potong($item['deskripsi'] ?? null, 255),
                        'plant'         => $this->potong($item['plant'] ?? null, 100),
                        'model'         => $this->potong($item['model'] ?? null, 100),
                        'tag_no'        => $this->potong($item['tag_no'] ?? null, 100),
                        'uom'           => $this->potong($item['uom'] ?? null, 20),
                        'stok_min'      => $item['stok_min'],
                        'sumber'        => SpPantau::SUMBER_MASTER,
                        'aktif'         => true,
                    ]);

                    $hasil['baru']++;
                    $terdampak[] = $kode;
                    continue;
                }

                $pantau->fill([
                    'deskripsi' => $this->potong($item['deskripsi'] ?? null, 255) ?? $pantau->deskripsi,
                    'plant'     => $this->potong($item['plant'] ?? null, 100) ?? $pantau->plant,
                    'model'     => $this->potong($item['model'] ?? null, 100) ?? $pantau->model,
                    'tag_no'    => $this->potong($item['tag_no'] ?? null, 100) ?? $pantau->tag_no,
                    'uom'       => $this->potong($item['uom'] ?? null, 20) ?? $pantau->uom,
                    'stok_min'  => $item['stok_min'] ?? $pantau->stok_min,
                    'aktif'     => true,
                ]);

                if ($pantau->isDirty()) {
                    $pantau->save();
                    $hasil['diperbarui']++;
                    $terdampak[] = $kode;
                }
            }

            if ($hasil['mode'] === self::MODE_GANTI) {
                $luar = SpPantau::query()
                    ->whereNotIn('kode_material', array_keys($data))
                    ->pluck('kode_material')
                    ->all();

                foreach (array_chunk($luar, 500) as $potong) {
                    SpMaterial::query()->whereIn('kode_material', $potong)->delete();
                    SpPantau::query()->whereIn('kode_material', $potong)->delete();
                }

                $hasil['dihapus'] = count($luar);
            }
        });

        $hasil['dihitung_ulang'] = $this->hitungUlang($terdampak);

        return $hasil;
    }

    /**
     * Menambah satu baris daftar pantau secara manual.
     *
     * @param  array<string, mixed> $data Nilai kolom (kode_material, deskripsi, stok_min, dst.)
     * @return SpPantau                   Baris yang dibuat
     */
    public function tambah(array $data): SpPantau
    {
        $pantau = SpPantau::create([
            'kode_material' => trim((string) $data['kode_material']),
            'deskripsi'     => $this->potong($data['deskripsi'] ?? null, 255),
            'plant'         => $this->potong($data['plant'] ?? null, 100),
            'model'         => $this->potong($data['model'] ?? null, 100),
            'tag_no'        => $this->potong($data['tag_no'] ?? null, 100),
            'uom'           => $this->potong($data['uom'] ?? null, 20),
            'stok_min'      => ($data['stok_min'] ?? '') === '' ? null : (float) $data['stok_min'],
            'sumber'        => SpPantau::SUMBER_MANUAL,
            'aktif'         => true,
        ]);

        $this->hitungUlang([$pantau->kode_material]);

        return $pantau;
    }

    /**
     * Mengubah satu baris daftar pantau lalu menghitung ulang materialnya.
     *
     * @param  SpPantau             $pantau Baris yang diubah
     * @param  array<string, mixed> $data   Nilai kolom baru
     * @return SpPantau                     Baris setelah disimpan
     */
    public function ubah(SpPantau $pantau, array $data): SpPantau
    {
        $pantau->fill([
            'kode_material' => trim((string) ($data['kode_material'] ?? $pantau->kode_material)),
            'deskripsi'     => $this->potong($data['deskripsi'] ?? null, 255),
            'plant'         => $this->potong($data['plant'] ?? null, 100),
            'model'         => $this->potong($data['model'] ?? null, 100),
            'tag_no'        => $this->potong($data['tag_no'] ?? null, 100),
            'uom'           => $this->potong($data['uom'] ?? null, 20),
            'stok_min'      => ($data['stok_min'] ?? '') === '' ? null : (float) $data['stok_min'],
            'sumber'        => SpPantau::SUMBER_MANUAL,
        ])->save();

        $this->hitungUlang([$pantau->kode_material]);

        return $pantau;
    }

    /**
     * Menghapus satu baris daftar pantau beserta keadaan materialnya.
     *
     * Baris keadaan ikut dibuang supaya halaman tidak menyimpan material yang
     * sudah tidak dipantau; bila ditambahkan lagi, nilainya diisi ulang oleh
     * import berikutnya.
     *
     * @param  SpPantau $pantau Baris yang dihapus
     * @return void
     */
    public function hapus(SpPantau $pantau): void
    {
        DB::transaction(function () use ($pantau): void {
            SpMaterial::query()->where('kode_material', $pantau->kode_material)->delete();
            $pantau->delete();
        });
    }

    /**
     * Menghitung ulang status material yang terdampak perubahan daftar pantau.
     *
     * Yang dihitung ulang hanya bagian yang bergantung pada stok (reorder),
     * penanda, kolom aksi, dan keterangan; nilai PR/PO/stok dari import
     * terakhir dibiarkan apa adanya.
     *
     * @param  array<int, string|null> $kode Daftar kode material
     * @return int                           Jumlah baris keadaan yang dihitung ulang
     */
    public function hitungUlang(array $kode): int
    {
        $bersih = array_values(array_unique(array_filter(
            array_map(fn (mixed $k): string => trim((string) $k), $kode),
            fn (string $k): bool => $k !== ''
        )));

        if ($bersih === []) {
            return 0;
        }

        $pantau = SpPantau::query()->whereIn('kode_material', $bersih)->get()->keyBy('kode_material');
        $jumlah = 0;

        foreach ($bersih as $satu) {
            $barisPantau = $pantau->get($satu);

            if ($barisPantau === null) {
                continue;
            }

            $keadaan = SpMaterial::query()->where('kode_material', $satu)->first()
                ?? new SpMaterial(['kode_material' => $satu]);

            $keadaan->stok_min  = $barisPantau->stok_min;
            $keadaan->deskripsi = $keadaan->deskripsi ?? $barisPantau->deskripsi;
            $keadaan->reorder   = $barisPantau->stok_min !== null
                && (float) ($keadaan->stok_tersedia ?? 0.0) <= (float) $barisPantau->stok_min;

            $adaPr = (int) $keadaan->pr_line > 0;
            $adaPo = (int) $keadaan->po_line > 0;

            $keadaan->ket = ($adaPr || $adaPo)
                ? 'Sudah ada PR/PO'
                : ($keadaan->reorder ? 'REORDER - belum ada PR/PO' : 'AMAN');

            $keadaan->aksi = (int) $keadaan->po_open > 0
                ? SpMaterial::AKSI_KEJAR
                : ((int) $keadaan->pr_terbuka > 0
                    ? SpMaterial::AKSI_BUAT_PO
                    : ($adaPr ? SpMaterial::AKSI_CEK : SpMaterial::AKSI_BUAT_PR));

            $penanda = [];

            if ($keadaan->stok_tersedia === null) {
                $penanda[] = 'tanpa catatan stok gudang';
            }

            if ($barisPantau->stok_min === null) {
                $penanda[] = 'stok minimum belum diisi';
            }

            $keadaan->penanda = $this->potong(implode('; ', $penanda), 120);
            $keadaan->save();
            $jumlah++;
        }

        return $jumlah;
    }
}