<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\PanelRuangan;

/**
 * PanelAssetSyncService
 *
 * Membuat/memperbarui baris cermin ruang panel di tabel `assets` supaya:
 *
 *   1. Ruang panel bisa dipilih sebagai equipment pada laporan (wizard
 *      Telegram maupun form web), karena pool pencariannya tabel assets.
 *   2. Lokasinya memakai Functional Location yang sama dengan aset industri
 *      (PT -> Departemen -> Area -> Section).
 *   3. Halaman Monitoring Ruang Panel tetap menjadi tempat pengelolaannya;
 *      baris di tabel assets hanyalah cermin yang ditandai data_source
 *      'panel_sync' dan menunjuk balik lewat kolom panel_ruangan_id.
 *
 * Aturan pengisian (KEPUTUSAN BISNIS, jangan diubah tanpa instruksi user):
 *   - Kode alat (assets.tech_ident_no) DIISI MANUAL pada form ruangan
 *     (kolom panel_ruangan.kode_alat).
 *   - Nomor equipment dibuat OTOMATIS 8 digit mengikuti seri nomor PT
 *     bersangkutan lewat AssetNumberService; nomor yang sudah ada pada baris
 *     yang diadopsi TIDAK ditimpa.
 *   - Ruangan tanpa lokasi atau tanpa kode alat TIDAK dipaksa masuk: baris
 *     cerminnya dibuang bila sebelumnya pernah ada, dan alasannya dilaporkan
 *     supaya bisa ditindaklanjuti dari halaman ruangan.
 */
class PanelAssetSyncService
{
    /** Penanda baris cermin ruang panel pada kolom assets.data_source. */
    public const SUMBER = 'panel_sync';

    /** Jenis alat bawaan bila form ruangan tidak mengisi jenis alat. */
    public const TIPE_EQUIPMENT = 'Panel Monitoring';

    /** Object type bawaan (kelompok lain-lain pada daftar kode alat). */
    public const OBJECT_TYPE = 'ZPM-OTH';

    /** Sumber baris cermin milik modul IT Assets; tidak boleh diadopsi. */
    protected const SUMBER_CERMIN_IT = 'it_sync';

    public function __construct(
        protected AssetNumberService $penomoran,
        protected AssetLocationService $lokasi,
    ) {
    }

    /**
     * Sinkronkan seluruh ruangan panel ke tabel assets.
     *
     * @param  bool  $dryRun  true = hanya menghitung, tidak menyimpan
     * @return array{created: int, updated: int, adopted: int, skipped: int, deleted: int, dilewati: array<int, array{nama: string, alasan: string}>}
     */
    public function syncAll(bool $dryRun = false): array
    {
        $rekap   = ['created' => 0, 'updated' => 0, 'adopted' => 0, 'skipped' => 0, 'deleted' => 0, 'dilewati' => []];
        $siapIds = [];

        foreach (PanelRuangan::with('funcloc')->orderBy('id')->get() as $ruangan) {
            $alasan = $this->alasanLewatSinkron($ruangan);

            if ($alasan !== null) {
                $rekap['skipped']++;
                $rekap['dilewati'][] = [
                    'nama'   => (string) $ruangan->nama_ruangan,
                    'alasan' => $alasan,
                ];

                // Baris cermin lama dibuang supaya tidak menampilkan data yang
                // sudah tidak sesuai keadaan ruangan.
                if (!$dryRun) {
                    $this->hapusCermin($ruangan);
                }

                continue;
            }

            $siapIds[]     = $ruangan->id;
            $hasil         = $this->syncOne($ruangan, $dryRun);
            $rekap[$hasil] = ($rekap[$hasil] ?? 0) + 1;
        }

        $rekap['deleted'] = $this->removeStaleMirrors($siapIds, $dryRun);

        return $rekap;
    }

    /**
     * Sinkronkan satu ruangan panel. Mengembalikan jenis aksi yang dilakukan
     * supaya bisa direkap: created, updated, adopted, atau skipped.
     *
     * @param  PanelRuangan  $ruangan
     * @param  bool          $dryRun
     * @return string
     */
    public function syncOne(PanelRuangan $ruangan, bool $dryRun = false): string
    {
        if ($this->alasanLewatSinkron($ruangan) !== null) {
            if (!$dryRun) {
                $this->hapusCermin($ruangan);
            }

            return 'skipped';
        }

        $funcloc = $this->funclocUntuk($ruangan);
        $kode    = trim((string) $ruangan->kode_alat);

        $mirror  = $this->asetCermin($ruangan);
        $adopted = false;

        // Adopsi baris aset lama yang Kode Alatnya sama (mis. panel yang dulu
        // pernah didaftarkan manual di halaman Asset Management) supaya laporan
        // lama yang menunjuk baris tersebut tetap bertaut ke alat yang benar.
        if ($mirror === null) {
            $mirror = Asset::whereNull('panel_ruangan_id')
                ->where('data_source', '!=', self::SUMBER_CERMIN_IT)
                ->where('tech_ident_no', $kode)
                ->first();

            $adopted = $mirror !== null;
        }

        $payload = $this->buildMirrorPayload($ruangan, $funcloc);

        if ($dryRun) {
            if ($mirror === null) {
                return 'created';
            }

            return $adopted ? 'adopted' : 'updated';
        }

        if ($mirror !== null) {
            // Nomor equipment baris lama (hasil adopsi) dipertahankan supaya
            // nomor yang sudah beredar di laporan lama tidak hilang.
            if ($adopted) {
                unset($payload['equipment_no']);
            }

            $mirror->update($payload);

            return $adopted ? 'adopted' : 'updated';
        }

        Asset::create($payload);

        return 'created';
    }

    /**
     * Hapus baris cermin milik satu ruangan.
     *
     * @param  PanelRuangan  $ruangan
     * @param  bool          $dryRun
     * @return int  jumlah baris yang dihapus (atau akan dihapus)
     */
    public function hapusCermin(PanelRuangan $ruangan, bool $dryRun = false): int
    {
        $query  = Asset::where('panel_ruangan_id', $ruangan->id);
        $jumlah = (clone $query)->count();

        if (!$dryRun) {
            $query->delete();
        }

        return $jumlah;
    }

    /**
     * Hapus baris cermin yang ruangannya sudah tidak disinkronkan lagi
     * (mis. lokasinya dikosongkan atau ruangannya sudah dihapus).
     *
     * @param  array<int, int>  $siapIds  Id ruangan yang berhasil disinkronkan
     * @param  bool             $dryRun
     * @return int  jumlah baris yang dihapus (atau akan dihapus)
     */
    public function removeStaleMirrors(array $siapIds, bool $dryRun = false): int
    {
        $query = Asset::whereNotNull('panel_ruangan_id');

        if ($siapIds !== []) {
            $query->whereNotIn('panel_ruangan_id', $siapIds);
        }

        $stale = $query->get();

        if (!$dryRun) {
            foreach ($stale as $asset) {
                $asset->delete();
            }
        }

        return $stale->count();
    }

    /**
     * Baris aset cermin milik sebuah ruangan (bila ada).
     *
     * @param  PanelRuangan  $ruangan
     * @return Asset|null
     */
    public function asetCermin(PanelRuangan $ruangan): ?Asset
    {
        if ($ruangan->id === null) {
            return null;
        }

        return Asset::where('panel_ruangan_id', $ruangan->id)->first();
    }

    /**
     * Alasan sebuah ruangan belum bisa disinkronkan ke tabel assets.
     *
     * @param  PanelRuangan  $ruangan
     * @return string|null  null bila ruangan sudah siap disinkronkan
     */
    public function alasanLewatSinkron(PanelRuangan $ruangan): ?string
    {
        if ($ruangan->funcloc_id === null) {
            return 'Lokasi (Functional Location) belum dipilih.';
        }

        if (trim((string) $ruangan->kode_alat) === '') {
            return 'Kode alat belum diisi.';
        }

        if ($this->funclocUntuk($ruangan) === null) {
            return 'Lokasi (Functional Location) yang dipilih tidak ditemukan lagi.';
        }

        return null;
    }

    /**
     * Susun data baris cermin di tabel assets untuk satu ruangan panel.
     *
     * @param  PanelRuangan        $ruangan
     * @param  FunctionalLocation  $funcloc
     * @return array<string, mixed>
     */
    public function buildMirrorPayload(PanelRuangan $ruangan, FunctionalLocation $funcloc): array
    {
        $cerminLama = $this->asetCermin($ruangan);
        $nomorLama  = $cerminLama?->equipment_no;
        $tipeAlat   = trim((string) ($ruangan->equipment_type ?? ''));

        return array_merge([
            // Nomor lama dipertahankan bila baris cermin sudah ada; kalau belum
            // ada, nomor dibuat otomatis mengikuti seri nomor PT lokasi ini.
            'equipment_no'     => $nomorLama !== null && $nomorLama !== ''
                ? $nomorLama
                : $this->penomoran->generateForFuncLoc($funcloc),
            'description'      => $this->buildDescription($ruangan, $funcloc),
            'tech_ident_no'    => trim((string) $ruangan->kode_alat),
            'object_type'      => self::OBJECT_TYPE,
            'equipment_type'   => $tipeAlat !== '' ? $tipeAlat : self::TIPE_EQUIPMENT,
            'functional_loc'   => $funcloc->code,
            'funcloc_id'       => $funcloc->id,
            'status'           => $ruangan->aktif ? 'active' : 'inactive',
            'has_equipment_no' => true,
            'data_source'      => self::SUMBER,
            'imported_at'      => now(),
            'panel_ruangan_id' => $ruangan->id,
        ], $this->lokasi->kolomLokasi($funcloc));
    }

    /**
     * Deskripsi baris cermin: nama ruangan, dilengkapi kode lokasi supaya
     * mudah dikenali pada daftar kandidat equipment di laporan.
     *
     * @param  PanelRuangan        $ruangan
     * @param  FunctionalLocation  $funcloc
     * @return string
     */
    protected function buildDescription(PanelRuangan $ruangan, FunctionalLocation $funcloc): string
    {
        $nama = trim((string) $ruangan->nama_ruangan);
        $kode = trim((string) $funcloc->code);

        if ($kode !== '' && !str_contains($nama, $kode)) {
            $nama .= ' (' . $kode . ')';
        }

        return $nama;
    }

    /**
     * Ambil node Functional Location milik ruangan (null bila belum dipilih
     * atau node-nya sudah tidak ada).
     *
     * @param  PanelRuangan  $ruangan
     * @return FunctionalLocation|null
     */
    protected function funclocUntuk(PanelRuangan $ruangan): ?FunctionalLocation
    {
        if ($ruangan->funcloc_id === null) {
            return null;
        }

        return $ruangan->relationLoaded('funcloc')
            ? $ruangan->funcloc
            : FunctionalLocation::find($ruangan->funcloc_id);
    }
}
