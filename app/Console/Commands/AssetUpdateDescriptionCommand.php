<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Perbarui kolom description aset dari berkas "Master List" (Excel).
 *
 * Kunci pencocokan utama adalah KODE ALAT, yaitu kolom assets.tech_ident_no
 * (dibandingkan tanpa peduli besar-kecil huruf dan spasi tepi).
 *
 * Bila Kode Alat di berkas tidak ada di database, baris dicocokkan lewat
 * CADANGAN Equipment No. yang identitasnya unik (kolomnya berindeks unik).
 * Cadangan ini perlu karena sebagian kode di berkas Master List ditulis tanpa
 * awalan yang ada di database (berkas "ALCY01" vs database "ESKALCY01",
 * "HM01" vs "ESKHM01").
 *
 * Bila satu Kode Alat dipakai lebih dari satu baris DI DALAM BERKAS (berkas
 * Master List nyata memuat kasus ini: "SC04" dipakai dua mesin berbeda),
 * Equipment No. juga dipakai sebagai pemutus supaya tiap baris masuk ke aset
 * yang benar. Pencocokan longgar lewat AKHIRAN kode TIDAK dipakai karena
 * berbahaya: "ESKALCY01" dan "MALCY01" sama-sama berakhiran "ALCY01" padahal
 * dua mesin berbeda di lokasi yang sama. Opsi --kode-saja mematikan cadangan
 * Equipment No. sehingga hanya Kode Alat persis yang dihitung.
 *
 * Baris Excel tanpa kolom Description atau dengan deskripsi yang sudah sama
 * tidak mengubah apa pun. Tanpa opsi --execute command hanya menyimulasikan.
 *
 * Opsi --perbarui-kode-alat menambahkan penyeragaman Kode Alat: kolom
 * assets.tech_ident_no disamakan dengan tulisan di berkas (mis. database
 * "ESKALCY01" menjadi "ALCY01"). Perubahan itu DIBATALKAN untuk baris yang kode
 * tujuannya sudah dipakai aset lain di PT yang sama, karena assets.tech_ident_no
 * tidak berindeks unik dan dua aset berkode sama membuat pencarian alat ambigu.
 *
 * Contoh pemakaian:
 *   php artisan assets:perbarui-deskripsi "C:\Users\sapusr\Downloads\Master List NPA rev 2.xlsx" --pt=NPA
 *   php artisan assets:perbarui-deskripsi "...xlsx" --pt=NPA --laporan=storage\app\laporan-perubahan.csv
 *   php artisan assets:perbarui-deskripsi "...xlsx" --pt=NPA --execute
 *   php artisan assets:perbarui-deskripsi "...xlsx" --pt=NPA --perbarui-kode-alat
 *   php artisan assets:perbarui-deskripsi "...xlsx" --pt=NPA --perbarui-kode-alat --execute
 */
class AssetUpdateDescriptionCommand extends Command
{
    /**
     * Nama dan tanda tangan command.
     *
     * @var string
     */
    protected $signature = 'assets:perbarui-deskripsi
                            {berkas : Path berkas Excel Master List (harus memuat kolom Kode Alat dan Description)}
                            {--sheet=List Equipment : Nama sheet sumber data}
                            {--pt= : Batasi ke satu PT (kode perusahaan, mis. NPA)}
                            {--kode-saja : Hanya cocokkan lewat Kode Alat persis (tanpa cadangan Equipment No.)}
                            {--perbarui-kode-alat : Ikut samakan Kode Alat (assets.tech_ident_no) dengan berkas bila berbeda}
                            {--laporan= : Path berkas CSV daftar perubahan (opsional)}
                            {--execute : Simpan ke database (tanpa opsi ini hanya simulasi)}';

    /**
     * Deskripsi command.
     *
     * @var string
     */
    protected $description = 'Perbarui deskripsi aset dari berkas Master List, dicocokkan lewat Kode Alat (Tech Ident No)';

    /**
     * Jalankan command: baca berkas Master List, cocokkan tiap baris ke aset
     * lewat Kode Alat, lalu perbarui kolom description bila --execute diberikan.
     *
     * @return int  Kode keluar command
     */
    public function handle(): int
    {
        $berkas      = (string) $this->argument('berkas');
        $namaSheet   = (string) $this->option('sheet');
        $kodePt      = mb_strtoupper(trim((string) $this->option('pt')));
        $kodeSaja    = (bool) $this->option('kode-saja');
        $kodeIkut    = (bool) $this->option('perbarui-kode-alat');
        $simpan      = (bool) $this->option('execute');
        $pathLaporan = trim((string) $this->option('laporan'));

        $this->line('Database tujuan : ' . (string) DB::connection()->getDatabaseName());
        $this->info($simpan
            ? 'MODE SIMPAN: perubahan akan ditulis ke database.'
            : 'MODE SIMULASI: tidak ada perubahan ditulis (tambahkan --execute untuk menyimpan).');
        $this->newLine();

        if ($kodeIkut) {
            $this->line('Opsi --perbarui-kode-alat aktif: Kode Alat (tech_ident_no) di database ikut disamakan dengan berkas.');
            $this->newLine();
        }

        if (! is_file($berkas)) {
            $this->error('Berkas tidak ditemukan: ' . $berkas);

            return self::FAILURE;
        }

        try {
            $barisExcel = $this->bacaBarisExcel($berkas, $namaSheet);
            $peta       = $this->petaAset($kodePt);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Gagal membaca berkas: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Sheet "%s": %d baris data | aset pembanding: %d baris%s',
            $namaSheet,
            count($barisExcel),
            $peta['jumlah'],
            $kodePt !== '' ? ' (PT ' . $kodePt . ')' : ' (semua PT)'
        ));
        $this->newLine();

        // Hitungan 'kode_disamakan', 'kode_bentrok', dan 'kode_gagal' TIDAK ikut
        // penjaga keseimbangan baris: ketiganya menempel pada baris yang sudah
        // dihitung di 'diperbarui' atau 'sama', karena satu baris berkas bisa
        // memicu dua perubahan sekaligus (Kode Alat dan deskripsi).
        $ringkas = [
            'dibaca'         => count($barisExcel),
            'kode_alat'      => 0,
            'equipment_no'   => 0,
            'diperbarui'     => 0,
            'sama'           => 0,
            'ambigu'         => 0,
            'tidak_ketemu'   => 0,
            'kode_kosong'    => 0,
            'gagal'          => 0,
            'kode_disamakan' => 0,
            'kode_bentrok'   => 0,
            'kode_gagal'     => 0,
        ];

        $laporan = [];  // seluruh baris yang dibaca; dasar berkas laporan

        // Kode Alat yang muncul lebih dari sekali DI DALAM BERKAS. Berkas nyata
        // memuat kasus ini (mis. "SC04" dipakai dua mesin berbeda), sehingga
        // Equipment No. dipakai sebagai pemutus untuk baris seperti itu.
        $hitungKode = [];

        foreach ($barisExcel as $baris) {
            $kunci = mb_strtoupper(trim((string) $baris['kode']));

            if ($kunci !== '') {
                $hitungKode[$kunci] = ($hitungKode[$kunci] ?? 0) + 1;
            }
        }

        foreach ($barisExcel as $baris) {
            $kodeGandaBerkas = ($hitungKode[mb_strtoupper(trim((string) $baris['kode']))] ?? 0) > 1;
            $hasil           = $this->cocokkanBaris($baris, $peta, $kodeSaja, $kodeGandaBerkas);

            if ($hasil['kategori'] !== 'cocok') {
                $ringkas[$hasil['kategori']]++;
                $laporan[] = $this->catatBaris($hasil['kategori'], $baris, null, $hasil['catatan']);

                continue;
            }

            $aset = $hasil['aset'];
            $ringkas[$hasil['cara']]++;

            // Opsi --perbarui-kode-alat dijalankan LEBIH DULU daripada deskripsi,
            // supaya baris yang deskripsinya sudah sama tetap ikut disamakan
            // Kode Alat-nya (kalau tidak, baris itu keluar lewat cabang "sama").
            $kode        = $this->samakanKodeAlat($aset, (string) $baris['kode'], $kodeIkut, $simpan);
            $kodeBaru    = $kode['status'] === 'disamakan' ? $kode['kode'] : '';
            $catatanKode = $kode['catatan'] !== '' ? ' | ' . $kode['catatan'] : '';

            if ($kode['status'] === 'disamakan') {
                $ringkas['kode_disamakan']++;
            } elseif ($kode['status'] === 'bentrok') {
                $ringkas['kode_bentrok']++;
            } elseif ($kode['status'] === 'gagal') {
                $ringkas['kode_gagal']++;
            }

            // Deskripsi yang isinya sama (beda besar-kecil huruf saja tidak
            // dianggap perubahan) tidak perlu ditulis ulang.
            if (mb_strtoupper(trim((string) $aset->description)) === mb_strtoupper(trim($baris['deskripsi']))) {
                $ringkas['sama']++;
                $laporan[] = $this->catatBaris('sama', $baris, $aset, 'deskripsi sudah sama' . $catatanKode, $kodeBaru);

                continue;
            }

            $cara = $hasil['catatan'] . $catatanKode;

            try {
                if ($simpan) {
                    Asset::whereKey($aset->id)->update(['description' => $baris['deskripsi']]);
                }

                $ringkas['diperbarui']++;
                $laporan[] = $this->catatBaris('diperbarui', $baris, $aset, $cara, $kodeBaru);
            } catch (Throwable $e) {
                $ringkas['gagal']++;
                $laporan[] = $this->catatBaris('gagal', $baris, $aset, $e->getMessage());
                Log::warning('Gagal memperbarui deskripsi aset', [
                    'asset_id' => $aset->id,
                    'pesan'    => $e->getMessage(),
                ]);
            }
        }

        $contohUbah    = array_slice($this->saringLaporan($laporan, ['diperbarui']), 0, 15);
        $contohMasalah = array_slice($this->saringLaporan($laporan, ['ambigu', 'tidak_ketemu', 'gagal']), 0, 10);

        if ($contohUbah !== []) {
            $this->line('Contoh perubahan (' . $ringkas['diperbarui'] . ' baris):');

            foreach ($contohUbah as $catatan) {
                $this->line('   ' . $this->teksBaris($catatan));
            }

            $this->newLine();
        }

        if ($contohMasalah !== []) {
            $this->line('Baris yang tidak diubah:');

            foreach ($contohMasalah as $catatan) {
                $this->line('   ' . $this->teksBaris($catatan));
            }

            $this->newLine();
        }

        $tabel = [
            ['Baris Excel dibaca', $ringkas['dibaca']],
            ['Cocok lewat Kode Alat', $ringkas['kode_alat']],
            ['Cocok lewat cadangan Equipment No.', $ringkas['equipment_no']],
            ['   - deskripsi diperbarui', $ringkas['diperbarui']],
            ['   - deskripsi sudah sama', $ringkas['sama']],
            ['Kode alat ambigu di database', $ringkas['ambigu']],
            ['Kode alat tidak ditemukan', $ringkas['tidak_ketemu']],
            ['Baris tanpa kode alat', $ringkas['kode_kosong']],
            ['Gagal disimpan', $ringkas['gagal']],
        ];

        if ($kodeIkut) {
            $tabel[] = ['Kode Alat disamakan dengan berkas', $ringkas['kode_disamakan']];
            $tabel[] = ['Kode Alat batal (sudah dipakai aset lain)', $ringkas['kode_bentrok']];
            $tabel[] = ['Kode Alat gagal disamakan', $ringkas['kode_gagal']];
        }

        $this->table(['Kategori', 'Jumlah'], $tabel);

        // Penjaga keseimbangan: setiap baris yang dibaca WAJIB berakhir di salah
        // satu kategori (AGENTS.md poin 9) supaya tidak ada baris hilang diam-diam.
        $tercatat = $ringkas['diperbarui'] + $ringkas['sama'] + $ringkas['ambigu']
            + $ringkas['tidak_ketemu'] + $ringkas['kode_kosong'] + $ringkas['gagal'];

        if ($tercatat !== $ringkas['dibaca']) {
            $this->warn(sprintf(
                'PERINGATAN: jumlah baris tidak seimbang (dibaca %d, tercatat %d).',
                $ringkas['dibaca'],
                $tercatat
            ));
        }

        if ($pathLaporan !== '') {
            $this->simpanLaporan($pathLaporan, $laporan, $ringkas, $kodePt);
        }

        if (! $simpan && ($ringkas['diperbarui'] > 0 || $ringkas['kode_disamakan'] > 0)) {
            $this->newLine();
            $this->comment('Simulasi saja: jalankan ulang dengan --execute untuk menyimpan perubahan di atas.');
        }

        if ($ringkas['tidak_ketemu'] > 0 || $ringkas['ambigu'] > 0) {
            $this->comment('Baris yang tidak ketemu/ambigu TIDAK diubah; lengkapi lebih dulu Kode Alat di berkas sumber atau di database.');
        }

        return self::SUCCESS;
    }

    /**
     * Baca baris data dari berkas Excel Master List.
     *
     * Kolom dikenali dari baris judul (bukan dari urutan/nama kolom Excel), jadi
     * berkas revisi berikutnya boleh menggeser posisi kolom.
     *
     * @param  string  $berkas     Path berkas Excel
     * @param  string  $namaSheet  Nama sheet sumber data
     * @return array<int, array{baris: int, kode: string, funcloc: string, eq_no: string, deskripsi: string}>
     */
    private function bacaBarisExcel(string $berkas, string $namaSheet): array
    {
        ini_set('memory_limit', '512M');

        $pembaca = IOFactory::createReader('Xlsx');
        $pembaca->setReadDataOnly(true);
        $pembaca->setLoadSheetsOnly([$namaSheet]);

        $buku   = $pembaca->load($berkas);
        $lembar = $buku->getSheetByName($namaSheet);

        if ($lembar === null) {
            throw new RuntimeException('Sheet "' . $namaSheet . '" tidak ada di berkas ' . basename($berkas) . '.');
        }

        $kolomKode    = null;
        $kolomDesk    = null;
        $kolomFuncloc = null;
        $kolomEqNo    = null;
        $kolomAkhir   = $lembar->getHighestDataColumn();

        foreach (range('A', $kolomAkhir) as $kolom) {
            $judul = mb_strtolower(trim((string) $lembar->getCell($kolom . '1')->getValue()));

            if ($judul === 'kode alat') {
                $kolomKode = $kolom;
            } elseif (in_array($judul, ['description', 'deskripsi'], true)) {
                $kolomDesk = $kolom;
            } elseif (mb_strpos($judul, 'functional loc') !== false) {
                $kolomFuncloc = $kolom;
            } elseif (mb_strpos($judul, 'equipment no') !== false) {
                $kolomEqNo = $kolom;
            }
        }

        if ($kolomKode === null || $kolomDesk === null) {
            throw new RuntimeException(
                'Kolom "Kode Alat" dan/atau "Description" tidak ditemukan pada baris judul sheet "' . $namaSheet . '".'
            );
        }

        $barisExcel = [];
        $barisAkhir = $lembar->getHighestDataRow();

        for ($r = 2; $r <= $barisAkhir; $r++) {
            $kode = $this->teksSel($lembar->getCell($kolomKode . $r)->getValue());
            $desk = $this->teksSel($lembar->getCell($kolomDesk . $r)->getValue());

            if ($kode === '' && $desk === '') {
                continue;
            }

            $barisExcel[] = [
                'baris'     => $r,
                'kode'      => $kode,
                'deskripsi' => $desk,
                'funcloc'   => $kolomFuncloc !== null
                    ? $this->teksSel($lembar->getCell($kolomFuncloc . $r)->getValue())
                    : '',
                'eq_no'     => $kolomEqNo !== null
                    ? $this->teksSel($lembar->getCell($kolomEqNo . $r)->getValue())
                    : '',
            ];
        }

        // Bebaskan memori berkas (AGENTS.md insiden #5) sebelum berkas besar
        // berikutnya dibaca.
        $buku->disconnectWorksheets();
        unset($buku, $lembar);
        gc_collect_cycles();

        if ($barisExcel === []) {
            throw new RuntimeException('Tidak ada baris data pada sheet "' . $namaSheet . '".');
        }

        return $barisExcel;
    }

    /**
     * Ubah isi sel menjadi teks bersih; angka bulat tidak ditulis dengan ".0".
     *
     * @param  mixed  $nilai  Isi sel mentah
     * @return string
     */
    private function teksSel(mixed $nilai): string
    {
        $teks = trim((string) $nilai);

        if (preg_match('/^([0-9]+)\.0+$/', $teks, $cocok) === 1) {
            return $cocok[1];
        }

        return $teks;
    }

    /**
     * Ambil aset pembanding satu PT beserta indeks Kode Alat dan Equipment No.
     *
     * @param  string  $kodePt  Kode PT pembatas (kosong berarti semua PT)
     * @return array{kode: array<string, array<int, Asset>>, equipment_no: array<string, array<int, Asset>>, jumlah: int}
     */
    private function petaAset(string $kodePt): array
    {
        $query = Asset::query()->orderBy('id');

        if ($kodePt !== '') {
            $perusahaan = Company::where('code', $kodePt)->first();

            if ($perusahaan === null) {
                throw new RuntimeException('PT dengan kode ' . $kodePt . ' tidak ada di tabel companies.');
            }

            $query->where('company_id', $perusahaan->id);
        }

        $peta  = ['kode' => [], 'equipment_no' => [], 'jumlah' => 0];
        // company_id ikut diambil karena dipakai penjaga bentrok Kode Alat pada
        // opsi --perbarui-kode-alat (samakanKodeAlat) - tanpa kolom ini nilai
        // $aset->company_id null dan penjaga itu tidak pernah aktif.
        $kolom = ['id', 'equipment_no', 'tech_ident_no', 'functional_loc', 'description', 'company_id'];

        foreach ($query->get($kolom) as $aset) {
            $peta['jumlah']++;

            $kunci = mb_strtoupper(trim((string) $aset->tech_ident_no));

            if ($kunci !== '') {
                $peta['kode'][$kunci][] = $aset;
            }

            $nomor = trim((string) $aset->equipment_no);

            if ($nomor !== '') {
                $peta['equipment_no'][$nomor][] = $aset;
            }
        }

        return $peta;
    }

    /**
     * Cocokkan satu baris berkas dengan aset di database.
     *
     * Urutan pencocokan:
     * (1) Kode Alat persis dan muncul sekali saja (baik di berkas maupun di
     *     database) - inilah kunci utama;
     * (2) bila Kode Alat ganda (di berkas atau di database) atau sama sekali
     *     tidak ada, Equipment No. dipakai sebagai pemutus/cadangan karena
     *     identitasnya unik (kolomnya berindeks unik);
     * (3) sisanya baru dinyatakan ambigu atau tidak ketemu.
     * Opsi --kode-saja mematikan langkah (2).
     *
     * @param  array  $baris            Baris berkas (kunci 'kode' dan 'eq_no')
     * @param  array{kode: array<string, array<int, Asset>>, equipment_no: array<string, array<int, Asset>>, jumlah: int}  $peta  Indeks aset
     * @param  bool   $kodeSaja         True bila hanya Kode Alat yang boleh dipakai
     * @param  bool   $kodeGandaBerkas  True bila Kode Alat baris ini muncul lebih dari sekali di berkas
     * @return array{kategori: string, aset: Asset|null, cara: string, catatan: string}
     */
    private function cocokkanBaris(array $baris, array $peta, bool $kodeSaja, bool $kodeGandaBerkas = false): array
    {
        $kunci     = mb_strtoupper(trim((string) $baris['kode']));
        $nomor     = trim((string) $baris['eq_no']);
        $lewatKode = $kunci !== '' ? ($peta['kode'][$kunci] ?? []) : [];

        if (count($lewatKode) === 1 && ! $kodeGandaBerkas) {
            return $this->hasilCocok($lewatKode[0], 'kode_alat', 'Kode Alat persis', $kunci);
        }

        if (! $kodeSaja && $nomor !== '') {
            $lewatNomor = $peta['equipment_no'][$nomor] ?? [];

            if (count($lewatNomor) === 1) {
                $alasan = 'via Equipment No.';

                if (count($lewatKode) > 1) {
                    $alasan = 'via Equipment No. (Kode Alat ganda di database)';
                } elseif ($kodeGandaBerkas) {
                    $alasan = 'via Equipment No. (Kode Alat ganda di berkas)';
                } elseif ($kunci === '') {
                    $alasan = 'via Equipment No. (Kode Alat kosong)';
                }

                return $this->hasilCocok($lewatNomor[0], 'equipment_no', $alasan, $kunci);
            }
        }

        if (count($lewatKode) === 1) {
            return $this->hasilCocok(
                $lewatKode[0],
                'kode_alat',
                'Kode Alat persis (Equipment No. berkas tidak ada di database)',
                $kunci
            );
        }

        if (count($lewatKode) > 1) {
            return [
                'kategori' => 'ambigu',
                'aset'     => null,
                'cara'     => '',
                'catatan'  => count($lewatKode) . ' aset memakai Kode Alat ini',
            ];
        }

        if ($kunci === '' && $nomor === '') {
            return [
                'kategori' => 'kode_kosong',
                'aset'     => null,
                'cara'     => '',
                'catatan'  => 'Kode Alat dan Equipment No. kosong',
            ];
        }

        return [
            'kategori' => 'tidak_ketemu',
            'aset'     => null,
            'cara'     => '',
            'catatan'  => 'tidak ada aset dengan Kode Alat "'
                . ($kunci !== '' ? $kunci : '(kosong)') . '"'
                . ($nomor !== '' ? ' maupun Equipment No. ' . $nomor : ''),
        ];
    }

    /**
     * Susun hasil pencocokan yang berhasil, sekaligus menyebut perbedaan Kode
     * Alat antara berkas dan database bila ada.
     *
     * @param  Asset   $aset         Aset tujuan
     * @param  string  $cara         'kode_alat' bila cocok lewat Kode Alat, 'equipment_no' bila lewat nomor
     * @param  string  $alasan       Kalimat alasan untuk laporan
     * @param  string  $kunciBerkas  Kode Alat sebagaimana ditulis di berkas (boleh kosong)
     * @return array{kategori: string, aset: Asset|null, cara: string, catatan: string}
     */
    private function hasilCocok(Asset $aset, string $cara, string $alasan, string $kunciBerkas): array
    {
        $kodeDb = mb_strtoupper(trim((string) $aset->tech_ident_no));

        if ($kunciBerkas !== '' && $kodeDb !== '' && $kodeDb !== $kunciBerkas) {
            $alasan .= ' (Kode Alat di database: ' . $aset->tech_ident_no . ')';
        }

        return [
            'kategori' => 'cocok',
            'aset'     => $aset,
            'cara'     => $cara,
            'catatan'  => $alasan,
        ];
    }

    /**
     * Susun satu baris laporan (dipakai untuk berkas CSV dan tampilan contoh).
     *
     * @param  string      $status      Kategori baris (diperbarui, sama, ambigu, tidak_ketemu, kode_kosong, gagal)
     * @param  array       $baris       Baris Excel yang diperiksa
     * @param  Asset|null  $aset        Aset tujuan (null bila tidak ketemu)
     * @param  string      $keterangan  Catatan tambahan
     * @param  string      $kodeBaru    Kode Alat sesudah disamakan (kosong bila tidak berubah)
     * @return array<string, string|int|null>
     */
    private function catatBaris(string $status, array $baris, ?Asset $aset, string $keterangan, string $kodeBaru = ''): array
    {
        return [
            'status'         => $status,
            'baris_excel'    => $baris['baris'],
            'id_aset'        => $aset?->id,
            'equipment_no'   => (string) ($aset?->equipment_no ?? $baris['eq_no']),
            'kode_alat'      => $baris['kode'],
            'kode_alat_db'   => (string) ($aset?->tech_ident_no ?? ''),
            'kode_alat_baru' => $kodeBaru,
            'functional_loc' => (string) ($aset?->functional_loc ?? $baris['funcloc']),
            'deskripsi_lama' => (string) ($aset?->description ?? ''),
            'deskripsi_baru' => $baris['deskripsi'],
            'keterangan'     => $keterangan,
        ];
    }

    /**
     * Samakan Kode Alat (assets.tech_ident_no) aset dengan tulisan di berkas
     * Master List. Dijalankan hanya bila opsi --perbarui-kode-alat diberikan.
     *
     * Penjaga penting: kolom tech_ident_no TIDAK berindeks unik (lihat migration
     * 0001_01_01_000007_create_assets_table.php), sehingga dua aset bisa berkode
     * sama. Karena itu kode tujuan DIBATALKAN bila kode itu sudah dipakai aset
     * lain di PT yang sama - dua aset berkode sama membuat pencarian alat
     * ambigu (berkas nyata memang memakai "SC04" untuk dua mesin; kasus seperti
     * itu harus diputuskan lewat Equipment No., bukan dengan menyeragamkan kode).
     *
     * @param  Asset   $aset        Aset tujuan yang sudah cocok dengan baris berkas
     * @param  string  $kodeBerkas  Kode Alat sebagaimana ditulis di berkas
     * @param  bool    $aktif       True bila opsi --perbarui-kode-alat diberikan
     * @param  bool    $simpan      True bila perubahan boleh ditulis ke database
     * @return array{kode: string, status: string, catatan: string}  status:
     *   'tidak_berlaku' (opsi mati/kode sama/kode berkas kosong), 'disamakan',
     *   'bentrok' (dibatalkan karena kode sudah dipakai aset lain), 'gagal'
     */
    private function samakanKodeAlat(Asset $aset, string $kodeBerkas, bool $aktif, bool $simpan): array
    {
        $kodeBerkas = trim($kodeBerkas);
        $kodeLama   = trim((string) $aset->tech_ident_no);

        if (! $aktif || $kodeBerkas === '' || mb_strtoupper($kodeBerkas) === mb_strtoupper($kodeLama)) {
            return ['kode' => $kodeLama, 'status' => 'tidak_berlaku', 'catatan' => ''];
        }

        // Pembanding dibuat peka terhadap besar-kecil huruf lewat UPPER() supaya
        // perilakunya sama di MySQL (collation ci) maupun SQLite (dipakai test).
        $terpakaiAsetLain = Asset::query()
            ->where('company_id', $aset->company_id)
            ->where('id', '<>', $aset->id)
            ->whereRaw('UPPER(tech_ident_no) = ?', [mb_strtoupper($kodeBerkas)])
            ->exists();

        if ($terpakaiAsetLain) {
            return [
                'kode'    => $kodeLama,
                'status'  => 'bentrok',
                'catatan' => 'Kode Alat "' . $kodeBerkas . '" sudah dipakai aset lain di PT yang sama, Kode Alat TIDAK diubah',
            ];
        }

        try {
            if ($simpan) {
                Asset::whereKey($aset->id)->update(['tech_ident_no' => $kodeBerkas]);
            }

            return [
                'kode'    => $kodeBerkas,
                'status'  => 'disamakan',
                'catatan' => 'Kode Alat disamakan: ' . ($kodeLama !== '' ? $kodeLama : '(kosong)') . ' -> ' . $kodeBerkas,
            ];
        } catch (Throwable $e) {
            Log::warning('Gagal menyamakan Kode Alat aset', [
                'asset_id' => $aset->id,
                'pesan'    => $e->getMessage(),
            ]);

            return [
                'kode'    => $kodeLama,
                'status'  => 'gagal',
                'catatan' => 'Gagal menyamakan Kode Alat: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Saring daftar laporan berdasarkan statusnya.
     *
     * @param  array<int, array<string, mixed>>  $laporan  Daftar baris laporan
     * @param  array<int, string>                $status   Status yang diinginkan
     * @return array<int, array<string, mixed>>
     */
    private function saringLaporan(array $laporan, array $status): array
    {
        return array_values(array_filter(
            $laporan,
            fn (array $catatan): bool => in_array((string) $catatan['status'], $status, true)
        ));
    }

    /**
     * Susun teks satu baris laporan untuk ditampilkan di terminal.
     *
     * @param  array<string, mixed>  $catatan  Baris laporan
     * @return string
     */
    private function teksBaris(array $catatan): string
    {
        if ((string) $catatan['deskripsi_lama'] !== '') {
            return sprintf(
                '%-14s | #%-5s | %-18s | "%s" -> "%s" | %s',
                (string) $catatan['kode_alat'],
                (string) ($catatan['id_aset'] ?? '-'),
                mb_substr((string) $catatan['functional_loc'], 0, 18),
                mb_substr((string) $catatan['deskripsi_lama'], 0, 22),
                mb_substr((string) $catatan['deskripsi_baru'], 0, 22),
                (string) $catatan['keterangan']
            );
        }

        return sprintf(
            '%-14s | %-28s | %s',
            (string) $catatan['kode_alat'],
            mb_substr((string) $catatan['functional_loc'], 0, 28),
            (string) $catatan['keterangan']
        );
    }

    /**
     * Tulis seluruh baris laporan ke berkas CSV supaya bisa diperiksa manusia.
     *
     * @param  string  $path                          Path berkas CSV tujuan
     * @param  array<int, array<string, mixed>>  $laporan  Daftar baris laporan
     * @param  array<string, int>  $ringkas           Ringkasan angka
     * @param  string  $kodePt                        Kode PT pembatas (boleh kosong)
     * @return bool                                   True bila berkas berhasil ditulis
     */
    private function simpanLaporan(string $path, array $laporan, array $ringkas, string $kodePt): bool
    {
        $folder = dirname($path);

        if ($folder !== '' && $folder !== '.' && ! is_dir($folder)) {
            @mkdir($folder, 0775, true);
        }

        $tangan = @fopen($path, 'w');

        if ($tangan === false) {
            $this->warn('Gagal menulis berkas laporan: ' . $path);

            return false;
        }

        fputcsv($tangan, [
            'status',
            'baris_excel',
            'id_aset',
            'equipment_no',
            'kode_alat',
            'kode_alat_db',
            'kode_alat_baru',
            'functional_location',
            'deskripsi_lama',
            'deskripsi_baru',
            'keterangan',
        ]);

        foreach ($laporan as $catatan) {
            fputcsv($tangan, array_values($catatan));
        }

        fputcsv($tangan, []);
        fputcsv($tangan, [
            'ringkasan',
            'baris_dibaca=' . $ringkas['dibaca'],
            'diperbarui=' . $ringkas['diperbarui'],
            'lewat_kode_alat=' . $ringkas['kode_alat'],
            'lewat_equipment_no=' . $ringkas['equipment_no'],
            'sama=' . $ringkas['sama'],
            'ambigu=' . $ringkas['ambigu'],
            'tidak_ketemu=' . $ringkas['tidak_ketemu'],
            'kode_kosong=' . $ringkas['kode_kosong'],
            'gagal=' . $ringkas['gagal'],
            'kode_alat_disamakan=' . $ringkas['kode_disamakan'],
            'kode_alat_bentrok=' . $ringkas['kode_bentrok'],
            'kode_alat_gagal=' . $ringkas['kode_gagal'],
            'pt=' . ($kodePt !== '' ? $kodePt : 'semua'),
        ]);

        fclose($tangan);

        $this->line('Laporan perubahan ditulis ke: ' . $path);

        return true;
    }
}




