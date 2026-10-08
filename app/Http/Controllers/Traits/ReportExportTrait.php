<?php

namespace App\Http\Controllers\Traits;

use App\Models\Area;
use App\Models\Report;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Trait ReportExportTrait
 *
 * Ekspor DAFTAR LAPORAN pekerjaan ke Excel (.xlsx) dan CSV dengan tata letak
 * laporan resmi: blok judul + keterangan (periode, filter, jumlah, pencatat),
 * tabel berheader teal, autofilter, freeze pane, ditambah dua lembar
 * pendukung: "Ringkasan" (rekap per jenis pekerjaan/status/PT/teknisi) dan
 * "Petunjuk" (dokumentasi arti setiap kolom).
 *
 * ATURAN BARIS (keputusan user 08/10/2026): satu laporan = satu baris. Salinan
 * kolaborasi (collaborator_of) dan salinan multi alat (multi_of) TIDAK
 * menghasilkan baris tambahan; nama kolaborator cukup dicantumkan pada kolom
 * "Kolaborator" di baris laporan induknya. Aturan ini sama dengan tabel
 * halaman Laporan dan tidak boleh diubah tanpa instruksi user.
 *
 * FOTO (keputusan user 08/10/2026): kolom "Foto Dokumentasi (tautan)" dan
 * "Foto Hygiene (tautan)" SELALU ada dan memuat URL publik foto (satu URL per
 * baris sel). Bila pengguna mencentang "sertakan foto", ditambah kolom
 * "... (gambar)" yang isinya gambar disematkan langsung ke sel (foto pertama
 * tiap jenis, dibatasi BATAS_GAMBAR_EXPORT gambar) supaya berkas bisa dibaca
 * tanpa membuka aplikasi. Foto yang berkasnya tidak ada di server ini
 * (mis. salinan DEV yang tidak memuat foto asli) hanya diberi catatan teks,
 * bukan dianggap kosong tanpa keterangan.
 *
 * TAMPILAN (keputusan user 08/10/2026): selain "reguler" dan "shift", popup
 * export boleh meminta "semua" (keduanya sekaligus). Nilai "semua" HANYA
 * dikirim popup export; halaman Laporan tetap selalu reguler/shift.
 *
 * Trait ini dipakai App\Http\Controllers\ReportController, sehingga boleh
 * memanggil method privat controller tersebut (buildReportQuery, resolveGrub,
 * resolveTanggal).
 */
trait ReportExportTrait
{
    /** Judul kolom foto: tautan selalu ada, gambar hanya bila diminta. */
    public const KOLOM_TAUTAN_DOKUMENTASI = 'Foto Dokumentasi (tautan)';
    public const KOLOM_TAUTAN_HYGIENE     = 'Foto Hygiene (tautan)';
    public const KOLOM_GAMBAR_DOKUMENTASI = 'Foto Dokumentasi (gambar)';
    public const KOLOM_GAMBAR_HYGIENE     = 'Foto Hygiene (gambar)';

    /**
     * Batas jumlah gambar yang disematkan pada SATU berkas Excel (dikirim lewat
     * centang "sertakan foto"). Foto di atas batas ini tetap punya tautannya
     * pada kolom "... (tautan)"; hanya gambarnya tidak ditempel supaya ukuran
     * berkas tetap wajar dan proses unduh tidak lambat.
     */
    private const BATAS_GAMBAR_EXPORT = 300;

    /** Tinggi gambar yang disematkan (piksel) dan tinggi barisnya (poin). */
    private const TINGGI_GAMBAR_EXPORT = 56;
    private const TINGGI_BARIS_GAMBAR  = 46;

    /** Jumlah tautan foto yang ditulis pada satu sel (sisanya diringkas). */
    private const BATAS_TAUTAN_FOTO = 5;

    /**
     * Ekspor daftar laporan ke berkas Excel (.xlsx) tiga lembar: Daftar
     * Laporan, Ringkasan, dan Petunjuk.
     *
     * @param  Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportExcel(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $laporan = $this->ambilLaporanExport($request);
        $sertakanFoto = $request->boolean('sertakan_foto');
        $kolom        = $this->kolomExportLaporan($sertakanFoto);

        $spreadsheet = new Spreadsheet();
        $daftar      = $spreadsheet->getActiveSheet();
        $daftar->setTitle('Daftar Laporan');

        $barisHeader = $this->tulisBlokJudul(
            $daftar,
            count($kolom),
            'DAFTAR LAPORAN PEKERJAAN - MAINTENANCE REPORT',
            'Sistem Manajemen Pemeliharaan - Oleochemical Maintenance',
            $this->periodeExport($request),
            $this->ringkasanFilterExport($request),
            $this->keteranganJumlahExport($laporan->count(), $sertakanFoto)
        );

        $kolomTerakhir = Coordinate::stringFromColumnIndex(count($kolom));
        $kolomBungkus  = $this->hurufKolomBungkus($kolom);
        $hurufTautan   = [
            'dokumentasi' => $this->hurufKolomExport($kolom, self::KOLOM_TAUTAN_DOKUMENTASI),
            'hygiene'     => $this->hurufKolomExport($kolom, self::KOLOM_TAUTAN_HYGIENE),
        ];

        $this->tulisHeaderKolom($daftar, $kolom, $barisHeader);

        $rowNum          = $barisHeader + 1;
        $no              = 1;
        $gambarTerpasang = 0;

        foreach ($laporan as $report) {
            $gambar = $sertakanFoto ? $this->gambarBarisExport($report) : null;

            foreach ($this->barisExportLaporan($report, $no, $gambar) as $i => $teks) {
                $daftar->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($i + 1) . $rowNum,
                    (string) ($teks ?? ''),
                    DataType::TYPE_STRING
                );
            }

            $this->gayaBarisData($daftar, $rowNum, $kolomTerakhir, $no, $kolomBungkus);
            $this->pasangTautanFotoExport($daftar, $rowNum, $report, $hurufTautan);

            if ($gambar !== null) {
                $gambarTerpasang += $this->sematkanGambarExport(
                    $daftar,
                    $rowNum,
                    $gambar,
                    $kolom,
                    $gambarTerpasang
                );
            }

            $rowNum++;
            $no++;
        }

        if ($laporan->isEmpty()) {
            $daftar->setCellValue('A' . $rowNum, 'Tidak ada laporan pada periode/filter ini.');
            $daftar->getStyle('A' . $rowNum)->getFont()->setItalic(true)->getColor()->setARGB('FF94A3B8');
        }

        $this->rapikanTabel($daftar, $kolom, $barisHeader, $laporan->isNotEmpty());
        $this->tulisSheetRingkasan($spreadsheet, $laporan, $request);
        $this->tulisSheetPetunjuk($spreadsheet, $request, $laporan->count());

        $spreadsheet->setActiveSheetIndex(0);

        $writer   = new Xlsx($spreadsheet);
        $filename = 'laporan-export-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Ekspor daftar laporan ke berkas CSV ber-BOM UTF-8.
     * Blok judul & keterangannya sama dengan versi Excel supaya berkas CSV
     * tetap terbaca sebagai laporan, bukan sekadar data mentah.
     *
     * @param  Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportCsv(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $laporan = $this->ambilLaporanExport($request);
        $kolom   = array_column($this->kolomExportLaporan(), 'judul');

        $info = [
            'DAFTAR LAPORAN PEKERJAAN - MAINTENANCE REPORT',
            'Sistem Manajemen Pemeliharaan - Oleochemical Maintenance',
            'Periode: ' . $this->periodeExport($request),
            'Diekspor: ' . now()->format('d/m/Y H:i') . ' WIB'
                . (auth()->check() ? ' oleh ' . auth()->user()->name : ''),
            'Filter: ' . $this->ringkasanFilterExport($request),
            'Jumlah: ' . number_format($laporan->count()) . ' laporan (satu laporan = satu baris)',
            'Catatan: nama kolaborator dicantumkan pada kolom Kolaborator, tidak dibuat baris terpisah.',
            'Catatan: format CSV tidak menyematkan gambar; tautan foto ada di kolom "'
                . self::KOLOM_TAUTAN_DOKUMENTASI . '" dan "' . self::KOLOM_TAUTAN_HYGIENE
                . '" (satu tautan per baris dalam sel).',
        ];

        return Response::stream(function () use ($info, $kolom, $laporan) {
            $keluaran = fopen('php://output', 'w');

            // BOM UTF-8 supaya Excel membaca huruf beraksen dengan benar.
            fwrite($keluaran, "\xEF\xBB\xBF");

            foreach ($info as $teks) {
                fputcsv($keluaran, [$teks]);
            }

            fputcsv($keluaran, []);
            fputcsv($keluaran, $kolom);

            $no = 1;

            foreach ($laporan as $report) {
                fputcsv($keluaran, $this->barisExportLaporan($report, $no));
                $no++;
            }

            fclose($keluaran);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="laporan-export-'
                . now()->format('Y-m-d-His') . '.csv"',
        ]);
    }

    /**
     * Tulis blok judul + keterangan di baris 1-6 dan kembalikan nomor baris
     * header kolom (selalu baris 7, sama dengan export asset).
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @param  int     $jumlahKolom  Jumlah kolom tabel
     * @param  string  $judul        Judul besar berkas
     * @param  string  $subjudul     Nama sistem
     * @param  string  $periode      Keterangan periode data
     * @param  string  $filter       Ringkasan filter yang dipakai
     * @param  string  $jumlah       Keterangan jumlah baris
     * @return int  Nomor baris header kolom
     */
    private function tulisBlokJudul($sheet, int $jumlahKolom, string $judul, string $subjudul, string $periode, string $filter, string $jumlah): int
    {
        $kolomTerakhir = Coordinate::stringFromColumnIndex(max(1, $jumlahKolom));

        $sheet->mergeCells('A1:' . $kolomTerakhir . '1');
        $sheet->setCellValue('A1', $judul);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $barisInfo = [
            2 => $subjudul,
            3 => 'Periode: ' . $periode,
            4 => 'Diekspor: ' . now()->format('d/m/Y H:i') . ' WIB'
                . (auth()->check() ? ' oleh ' . auth()->user()->name : ''),
            5 => 'Filter: ' . $filter,
            6 => 'Jumlah: ' . $jumlah,
        ];

        foreach ($barisInfo as $nomor => $teks) {
            $sheet->mergeCells('A' . $nomor . ':' . $kolomTerakhir . $nomor);
            $sheet->setCellValue('A' . $nomor, $teks);
            $sheet->getStyle('A' . $nomor)->getFont()->setSize(10)->getColor()->setARGB('FF475569');
            $sheet->getStyle('A' . $nomor)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($nomor)->setRowHeight(18);
        }

        return 7;
    }

    /**
     * Tulis baris header kolom lengkap dengan lebar kolomnya.
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @param  array<int, array{judul: string, lebar: int, buka?: bool}>  $kolom
     * @param  int  $barisHeader
     * @return void
     */
    private function tulisHeaderKolom($sheet, array $kolom, int $barisHeader): void
    {
        foreach (array_values($kolom) as $i => $definisi) {
            $huruf = Coordinate::stringFromColumnIndex($i + 1);

            $sheet->setCellValue($huruf . $barisHeader, $definisi['judul']);

            $style = $sheet->getStyle($huruf . $barisHeader);
            $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
            $style->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF0B7A6E');

            $sheet->getColumnDimension($huruf)->setWidth($definisi['lebar']);
        }

        $sheet->getRowDimension($barisHeader)->setRowHeight(30);
    }

    /**
     * Gaya satu baris data: border tipis, zebra, dan pembungkusan teks pada
     * kolom yang isinya panjang (uraian pekerjaan, temuan, penyebab, dst).
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @param  int     $rowNum          Nomor baris Excel
     * @param  string  $kolomTerakhir   Huruf kolom paling kanan
     * @param  int     $no              Nomor urut baris (untuk zebra)
     * @param  array<int, string>  $kolomBungkus  Huruf kolom yang dibungkus
     * @return void
     */
    private function gayaBarisData($sheet, int $rowNum, string $kolomTerakhir, int $no, array $kolomBungkus): void
    {
        $rentang = 'A' . $rowNum . ':' . $kolomTerakhir . $rowNum;

        $sheet->getStyle($rentang)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');
        $sheet->getStyle($rentang)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ($kolomBungkus as $huruf) {
            $sheet->getStyle($huruf . $rowNum)->getAlignment()->setWrapText(true);
        }

        // Baris zebra supaya laporan lebih mudah dibaca.
        if ($no % 2 === 0) {
            $sheet->getStyle($rentang)->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
        }
    }

    /**
     * Penyempurnaan tampilan tabel: freeze pane, autofilter, dan pengaturan
     * cetak (landscape, muat 1 halaman lebar, baris judul diulang saat cetak).
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @param  array<int, array{judul: string, lebar: int, buka?: bool}>  $kolom
     * @param  int   $barisHeader
     * @param  bool  $adaData
     * @return void
     */
    private function rapikanTabel($sheet, array $kolom, int $barisHeader, bool $adaData): void
    {
        $kolomTerakhir = Coordinate::stringFromColumnIndex(count($kolom));

        if ($adaData) {
            $sheet->freezePane('A' . ($barisHeader + 1));
            $sheet->setAutoFilter('A' . $barisHeader . ':' . $kolomTerakhir . $barisHeader);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $barisHeader);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.3)->setRight(0.3);
    }

    /**
     * Huruf kolom yang isinya perlu dibungkus (ditandai 'buka' pada definisi
     * kolom), dipakai saat menulis setiap baris data.
     *
     * @param  array<int, array{judul: string, lebar: int, buka?: bool}>  $kolom
     * @return array<int, string>
     */
    private function hurufKolomBungkus(array $kolom): array
    {
        $huruf = [];

        foreach (array_values($kolom) as $i => $definisi) {
            if (($definisi['buka'] ?? false) === true) {
                $huruf[] = Coordinate::stringFromColumnIndex($i + 1);
            }
        }

        return $huruf;
    }

    /**
     * Ambil laporan yang akan diekspor memakai query halaman Laporan supaya
     * filternya tidak pernah berbeda antara halaman dan berkas export.
     *
     * Filter jenis pekerjaan pada popup export boleh BANYAK nilai (checkbox),
     * jadi dikirim lewat kunci `jenis_pekerjaan_multi` dan diterapkan di sini
     * dengan whereIn. Kunci `jenis_pekerjaan` (tunggal) tetap didukung supaya
     * tautan lama yang sudah beredar tidak berubah perilakunya.
     *
     * Kunci `tampilan=semua` (Reguler + Shift sekaligus) juga khusus popup
     * export; halaman Laporan selalu mengirim 'reguler' atau 'shift'.
     *
     * @param  Request  $request
     * @return \Illuminate\Support\Collection<int, Report>
     */
    private function ambilLaporanExport(Request $request)
    {
        $tampilan  = $this->tampilanExport($request);
        $grubAktif = $tampilan === 'shift' ? $this->resolveGrub($request->get('grub')) : null;
        $tanggal   = $this->resolveTanggal($request->get('tanggal'));
        $jenis     = $this->daftarJenisExport($request);

        $query = $this->buildReportQuery($request, $tampilan, $grubAktif, $tanggal);

        if ($jenis !== []) {
            $query->whereIn('jenis_pekerjaan', $jenis);
        }

        return $query
            ->with([
                'technician',
                'area.department.company',
                'functionalLocation.company',
                'functionalLocation.department',
                'functionalLocation.area',
                'functionalLocation.subArea',
                'asset.company',
                'asset.department',
                'asset.area',
                'asset.subArea',
                'collaboratorReports.technician',
                'multiItems.asset',
                'multiItems.functionalLocation',
            ])
            // Urutan sama dengan halaman Laporan: yang terbaru di atas.
            ->orderByDesc('report_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Tampilan yang diminta berkas export: 'reguler', 'shift', atau 'semua'.
     *
     * 'semua' (diputuskan user 08/10/2026) berarti pekerja reguler DAN shift
     * ikut diekspor — inilah pilihan "Keduanya" pada popup export. Nilai lain
     * apa pun diperlakukan sebagai 'reguler', sama seperti halaman Laporan,
     * supaya tautan lama tidak berubah perilakunya.
     *
     * @param  Request  $request
     * @return string  'reguler' | 'shift' | 'semua'
     */
    private function tampilanExport(Request $request): string
    {
        $nilai = (string) $request->get('tampilan');

        if ($nilai === 'shift' || $nilai === 'semua') {
            return $nilai;
        }

        return 'reguler';
    }

    /**
     * Daftar jenis pekerjaan yang dipilih pada popup export.
     *
     * @param  Request  $request
     * @return array<int, string>
     */
    private function daftarJenisExport(Request $request): array
    {
        $mentah = (array) $request->input('jenis_pekerjaan_multi', []);
        $bersih = [];

        foreach ($mentah as $nilai) {
            $nilai = trim((string) $nilai);

            if ($nilai !== '') {
                $bersih[] = $nilai;
            }
        }

        return array_values(array_unique($bersih));
    }

    /**
     * Keterangan periode data yang diekspor.
     *
     * @param  Request  $request
     * @return string
     */
    private function periodeExport(Request $request): string
    {
        if ($request->boolean('tanpa_batas')) {
            return 'seluruh data (tanpa batas tanggal)';
        }

        $dari   = $this->tanggalAman($request->input('date_from'));
        $sampai = $this->tanggalAman($request->input('date_to'));

        if ($dari !== null || $sampai !== null) {
            return ($dari?->format('d/m/Y') ?? 'awal data')
                . ' s.d. ' . ($sampai?->format('d/m/Y') ?? 'sekarang');
        }

        return $this->resolveTanggal($request->get('tanggal'))->format('d/m/Y') . ' (satu hari)';
    }

    /**
     * Ubah teks tanggal menjadi Carbon dengan aman (null bila tidak sah).
     *
     * @param  string|null  $nilai
     * @return Carbon|null
     */
    private function tanggalAman(?string $nilai): ?Carbon
    {
        if ($nilai === null || trim($nilai) === '') {
            return null;
        }

        try {
            return Carbon::parse($nilai);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ringkasan filter yang dipakai pada berkas export, ditulis pada baris
     * keterangan supaya pembaca tahu isi berkas ini disaring dengan apa.
     *
     * @param  Request  $request
     * @return string  Contoh: "Tampilan: Shift (Grub D) | Jenis: EI, Sipil"
     */
    private function ringkasanFilterExport(Request $request): string
    {
        $bagian = [];

        $tampilan = $this->tampilanExport($request);

        if ($tampilan === 'semua') {
            // Pilihan "Keduanya" pada popup export: reguler dan shift sekaligus.
            $bagian[] = 'Tampilan: Reguler + Shift (keduanya)';
        } elseif ($tampilan === 'shift') {
            $grub    = $this->resolveGrub($request->get('grub'));
            $bagian[] = 'Tampilan: Shift'
                . ($grub !== null ? ' (' . (Technician::GROUPS[$grub] ?? $grub) . ')' : ' (semua grub)');
        } else {
            $bagian[] = 'Tampilan: Reguler';
        }

        $jenis = $this->daftarJenisExport($request);

        if ($jenis === [] && $request->filled('jenis_pekerjaan')) {
            $jenis = [(string) $request->input('jenis_pekerjaan')];
        }

        if ($jenis !== []) {
            $bagian[] = 'Jenis Pekerjaan: ' . implode(', ', $jenis);
        }

        if ($request->filled('status')) {
            $bagian[] = 'Status: ' . $this->labelStatusExport((string) $request->input('status'));
        }

        if ($request->filled('report_type')) {
            $bagian[] = 'Tipe: ' . $this->labelTipeExport((string) $request->input('report_type'));
        }

        if ($request->filled('work_classification')) {
            $bagian[] = 'Klasifikasi: ' . $this->labelKlasifikasiExport((string) $request->input('work_classification'));
        }

        if ($request->filled('area_id')) {
            $area     = Area::find($request->input('area_id'));
            $bagian[] = 'Area: ' . ($area !== null ? $this->labelKodeNama($area->code, $area->name) : $request->input('area_id'));
        }

        if ($request->filled('technician_id')) {
            $teknisi  = Technician::find($request->input('technician_id'));
            $bagian[] = 'Teknisi: ' . ($teknisi !== null
                ? trim($teknisi->name . ($teknisi->nik ? ' (' . $teknisi->nik . ')' : ''))
                : $request->input('technician_id'));
        }

        if ($request->filled('asset_code')) {
            $bagian[] = 'Kode Alat: ' . $request->input('asset_code');
        }

        if ($request->filled('search')) {
            $bagian[] = 'Pencarian: ' . $request->input('search');
        }

        if ($request->filled('report_code')) {
            $bagian[] = 'Kode Laporan: ' . $request->input('report_code');
        }

        return $bagian === []
            ? 'semua data (satu laporan = satu baris; kolaborator ada di kolom Kolaborator)'
            : implode(' | ', $bagian);
    }

    /**
     * Definisi kolom berkas export laporan (urutan tampil = urutan array).
     * 'buka' = true berarti isi kolom dibungkus (wrap text) supaya tetap
     * terbaca saat dicetak.
     *
     * Kolom foto berupa TAUTAN selalu disertakan; kolom foto berupa GAMBAR
     * hanya disertakan bila $sertakanGambar true supaya berkas tanpa foto
     * tidak penuh kolom kosong.
     *
     * @param  bool  $sertakanGambar  Ikutkan kolom gambar yang disematkan
     * @return array<int, array{judul: string, lebar: int, buka: bool}>
     */
    private function kolomExportLaporan(bool $sertakanGambar = false): array
    {
        $definisi = [
            ['No', 5, false],
            ['Kode Laporan', 16, false],
            ['Tanggal', 11, false],
            ['Hari', 9, false],
            ['Jam Mulai', 9, false],
            ['Jam Selesai', 10, false],
            ['Durasi (menit)', 11, false],
            ['Durasi (jam mnt)', 13, false],
            ['Teknisi', 20, false],
            ['NIK', 12, false],
            ['Bagian / Group', 17, false],
            ['Kolaborator', 22, true],
            ['Jenis Pekerjaan', 14, false],
            ['Klasifikasi (ZPM)', 17, false],
            ['Tipe Laporan', 15, false],
            ['Status', 13, false],
            ['Lanjutan', 9, false],
            ['PT', 18, false],
            ['Departemen', 16, false],
            ['Area', 18, false],
            ['Sub Area', 14, false],
            ['Section (Lokasi)', 26, true],
            ['Kode Alat', 14, false],
            ['Deskripsi Alat', 30, true],
            ['Equipment No', 14, false],
            ['Alat / Lokasi Tambahan', 30, true],
            ['Deskripsi Pekerjaan', 55, true],
            ['Temuan', 45, true],
            ['Penyebab (Root Cause)', 45, true],
            ['Sesuai Rencana', 13, false],
            ['Tanggal Kejadian', 14, false],
            ['Foto Dokumentasi', 13, false],
            ['Foto Hygiene', 11, false],
            ['Kecocokan AI', 14, false],
            ['Dicatat Pada', 16, false],
            // Foto: tautan selalu disertakan (satu tautan per baris sel);
            // gambar hanya bila diminta lewat centang "sertakan foto".
            [self::KOLOM_TAUTAN_DOKUMENTASI, 42, true],
            [self::KOLOM_TAUTAN_HYGIENE, 42, true],
        ];

        if ($sertakanGambar) {
            $definisi[] = [self::KOLOM_GAMBAR_DOKUMENTASI, 26, false];
            $definisi[] = [self::KOLOM_GAMBAR_HYGIENE, 26, false];
        }

        return array_map(fn ($item) => [
            'judul' => $item[0],
            'lebar' => $item[1],
            'buka'  => $item[2],
        ], $definisi);
    }

    /**
     * Susun satu baris data export (urutannya WAJIB sama dengan
     * kolomExportLaporan()).
     *
     * @param  Report      $report  Laporan yang ditulis
     * @param  int         $no      Nomor urut baris
     * @param  array|null  $gambar  Hasil gambarBarisExport() bila kolom gambar diminta
     * @return array<int, string>
     */
    private function barisExportLaporan(Report $report, int $no, ?array $gambar = null): array
    {
        $lokasi  = $this->lokasiExportLaporan($report);
        $menit   = (int) ($report->work_duration_minutes ?? 0);
        $teknisi = $report->technician;

        $baris = [
            (string) $no,
            (string) ($report->report_code ?? '-'),
            $report->report_date?->format('d/m/Y') ?? '-',
            $this->hariIndo($report->report_date),
            $this->jamExport($report->start_time),
            $this->jamExport($report->end_time),
            (string) $menit,
            $this->durasiJamMenit($menit),
            (string) ($teknisi?->name ?? '-'),
            (string) ($teknisi?->nik ?? '-'),
            $this->labelBagianGroupExport($teknisi),
            $this->kolaboratorExport($report),
            trim((string) $report->jenis_pekerjaan) !== '' ? (string) $report->jenis_pekerjaan : 'Tanpa Jenis',
            $this->labelKlasifikasiExport($report->work_classification),
            $this->labelTipeExport($report->report_type),
            $this->labelStatusExport($report->status),
            $report->continued_from_id ? 'Ya' : 'Tidak',
            $lokasi['pt'],
            $lokasi['departemen'],
            $lokasi['area'],
            $lokasi['sub_area'],
            $lokasi['section'],
            $lokasi['kode_alat'],
            $lokasi['deskripsi_alat'],
            $lokasi['equipment_no'],
            $this->alatTambahanExport($report),
            (string) ($report->work_description ?? ''),
            (string) ($report->finding ?? ''),
            (string) ($report->root_cause ?? ''),
            $this->labelSesuaiRencanaExport($report->sesuai_rencana),
            $report->tanggal_kejadian?->format('d/m/Y') ?? '-',
            (string) count($report->photo_documentation ?? []),
            (string) count($report->photo_hygiene_clearance ?? []),
            $report->ai_confidence ? ((string) round((float) $report->ai_confidence) . '%') : 'belum dianalisa',
            $report->created_at?->format('d/m/Y H:i') ?? '-',
            $this->tautanFotoExport($report, 'dokumentasi'),
            $this->tautanFotoExport($report, 'hygiene'),
        ];

        // Kolom gambar hanya ada bila popup meminta foto disematkan; isi
        // selnya catatan teks, sedangkan gambarnya ditempel setelah baris ini
        // ditulis (lihat sematkanGambarExport()).
        if ($gambar !== null) {
            $baris[] = $this->catatanKolomGambarExport($gambar['dokumentasi'] ?? null);
            $baris[] = $this->catatanKolomGambarExport($gambar['hygiene'] ?? null);
        }

        return $baris;
    }

    /**
     * Keterangan jumlah baris pada blok judul; ditambah keterangan foto bila
     * gambarnya diminta disematkan supaya pembaca tahu berkas memuat gambar.
     *
     * @param  int   $jumlah        Jumlah laporan pada berkas
     * @param  bool  $sertakanFoto  Kolom gambar disertakan
     * @return string
     */
    private function keteranganJumlahExport(int $jumlah, bool $sertakanFoto): string
    {
        $teks = number_format($jumlah) . ' laporan (satu laporan = satu baris)';

        if (! $sertakanFoto) {
            return $teks;
        }

        return $teks . ' | Foto: foto pertama tiap jenis disematkan sebagai gambar (maksimal '
            . number_format(self::BATAS_GAMBAR_EXPORT) . ' gambar)';
    }

    /**
     * Huruf kolom dari judul kolom tertentu (mis. "AJ"), null bila judulnya
     * tidak dipakai pada berkas ini.
     *
     * @param  array<int, array{judul: string, lebar: int, buka?: bool}>  $kolom
     * @param  string  $judul
     * @return string|null
     */
    private function hurufKolomExport(array $kolom, string $judul): ?string
    {
        foreach (array_values($kolom) as $i => $definisi) {
            if (($definisi['judul'] ?? '') === $judul) {
                return Coordinate::stringFromColumnIndex($i + 1);
            }
        }

        return null;
    }

    /**
     * Daftar tautan publik foto satu laporan untuk satu jenis foto.
     *
     * Tautan ditulis satu per baris dalam satu sel; bila lebih dari
     * BATAS_TAUTAN_FOTO, sisanya diringkas menjadi keterangan jumlah supaya
     * berkas tetap mudah dibaca.
     *
     * @param  Report  $report
     * @param  string  $jenis  'dokumentasi' atau 'hygiene'
     * @return string
     */
    private function tautanFotoExport(Report $report, string $jenis): string
    {
        $paths = $this->pathFotoExport($report, $jenis);

        if ($paths === []) {
            return 'tidak ada foto';
        }

        $disk   = (string) config('telegram.photo_disk', 'public');
        $tautan = [];

        foreach (array_slice($paths, 0, self::BATAS_TAUTAN_FOTO) as $path) {
            $alamat = null;

            try {
                $alamat = Storage::disk($disk)->url($path);
            } catch (\Throwable $e) {
                $alamat = null;
            }

            if (is_string($alamat) && trim($alamat) !== '') {
                $tautan[] = $this->tautanAbsolutExport($alamat);
            }
        }

        if ($tautan === []) {
            return count($paths) . ' foto (tautan tidak bisa dibentuk pada konfigurasi ini)';
        }

        $sisa = count($paths) - count($tautan);

        return implode("\n", $tautan) . ($sisa > 0 ? "\n(+" . $sisa . ' foto lainnya)' : '');
    }

    /**
     * Path foto satu laporan untuk satu jenis, hanya yang terisi.
     *
     * @param  Report  $report
     * @param  string  $jenis  'dokumentasi' atau 'hygiene'
     * @return array<int, string>
     */
    private function pathFotoExport(Report $report, string $jenis): array
    {
        $mentah = $jenis === 'hygiene'
            ? ($report->photo_hygiene_clearance ?? [])
            : ($report->photo_documentation ?? []);

        $bersih = [];

        foreach ((array) $mentah as $path) {
            $path = trim((string) $path);

            if ($path !== '') {
                $bersih[] = $path;
            }
        }

        return array_values($bersih);
    }

    /**
     * Jadikan tautan penyimpanan menjadi alamat absolut supaya bisa diklik dari
     * berkas Excel (tautan relatif tidak bisa dibuka dari luar aplikasi).
     *
     * @param  string  $tautan
     * @return string
     */
    private function tautanAbsolutExport(string $tautan): string
    {
        if (str_starts_with($tautan, 'http://') || str_starts_with($tautan, 'https://')) {
            return $tautan;
        }

        return url($tautan);
    }

    /**
     * Siapkan berkas gambar yang bisa disematkan untuk satu laporan: foto
     * PERTAMA tiap jenis yang berkasnya benar-benar ada di server ini.
     *
     * @param  Report  $report
     * @return array{dokumentasi: array{path: string|null, jumlah: int}, hygiene: array{path: string|null, jumlah: int}}
     */
    private function gambarBarisExport(Report $report): array
    {
        return [
            'dokumentasi' => $this->gambarJenisExport($report, 'dokumentasi'),
            'hygiene'     => $this->gambarJenisExport($report, 'hygiene'),
        ];
    }

    /**
     * Berkas gambar satu jenis foto: path absolut gambar pertama yang ada dan
     * bisa dibaca sebagai gambar, plus jumlah foto pada jenis itu.
     *
     * @param  Report  $report
     * @param  string  $jenis  'dokumentasi' atau 'hygiene'
     * @return array{path: string|null, jumlah: int}
     */
    private function gambarJenisExport(Report $report, string $jenis): array
    {
        $paths = $this->pathFotoExport($report, $jenis);
        $disk  = (string) config('telegram.photo_disk', 'public');
        $lokal = null;

        foreach ($paths as $path) {
            $kandidat = $this->berkasLokalExport($disk, $path);

            if ($kandidat !== null) {
                $lokal = $kandidat;
                break;
            }
        }

        return ['path' => $lokal, 'jumlah' => count($paths)];
    }

    /**
     * Path absolut berkas foto di server ini; null bila berkasnya tidak ada
     * atau bukan gambar (mis. salinan DEV yang tidak memuat foto asli, disk
     * awan yang tidak punya berkas lokal, atau berkas rusak) supaya export
     * tidak pernah gagal hanya karena foto.
     *
     * @param  string  $disk
     * @param  string  $path
     * @return string|null
     */
    private function berkasLokalExport(string $disk, string $path): ?string
    {
        try {
            $penyimpanan = Storage::disk($disk);

            if (! $penyimpanan->exists($path)) {
                return null;
            }

            $lokal = $penyimpanan->path($path);
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_string($lokal) || ! is_file($lokal) || @getimagesize($lokal) === false) {
            return null;
        }

        return $lokal;
    }

    /**
     * Isi sel kolom gambar berupa catatan singkat (gambarnya ditempel di atas
     * sel itu). Foto yang berkasnya tidak ada di server diberi keterangan
     * supaya tidak tampak seperti data yang hilang.
     *
     * @param  array|null  $info  Hasil gambarJenisExport()
     * @return string
     */
    private function catatanKolomGambarExport(?array $info): string
    {
        $jumlah = (int) ($info['jumlah'] ?? 0);

        if ($jumlah === 0) {
            return 'tidak ada foto';
        }

        if (($info['path'] ?? null) === null) {
            return $jumlah . ' foto, berkasnya tidak ada di server ini';
        }

        return $jumlah > 1
            ? 'lihat gambar (1 dari ' . $jumlah . ' foto)'
            : 'lihat gambar';
    }

    /**
     * Tempelkan gambar foto ke sel kolom gambar (satu gambar per jenis foto per
     * baris) dan kembalikan jumlah gambar yang benar-benar terpasang.
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @param  int    $rowNum          Nomor baris Excel
     * @param  array  $gambar          Hasil gambarBarisExport()
     * @param  array<int, array{judul: string, lebar: int, buka?: bool}>  $kolom
     * @param  int    $sudahTerpasang  Jumlah gambar yang sudah terpasang di berkas
     * @return int  Jumlah gambar yang terpasang pada baris ini
     */
    private function sematkanGambarExport($sheet, int $rowNum, array $gambar, array $kolom, int $sudahTerpasang): int
    {
        $pasangan = [
            'dokumentasi' => self::KOLOM_GAMBAR_DOKUMENTASI,
            'hygiene'     => self::KOLOM_GAMBAR_HYGIENE,
        ];

        $terpasang = 0;

        foreach ($pasangan as $jenis => $judul) {
            $path  = $gambar[$jenis]['path'] ?? null;
            $huruf = $this->hurufKolomExport($kolom, $judul);

            if ($path === null || $huruf === null) {
                continue;
            }

            if ($sudahTerpasang + $terpasang >= self::BATAS_GAMBAR_EXPORT) {
                // Batas gambar tercapai: selnya diberi keterangan supaya pembaca
                // tahu gambarnya sengaja tidak disertakan.
                $sheet->setCellValueExplicit(
                    $huruf . $rowNum,
                    'lihat kolom tautan (batas gambar tercapai)',
                    DataType::TYPE_STRING
                );

                continue;
            }

            $gambarSel = new Drawing();
            $gambarSel->setName('Foto ' . $jenis . ' baris ' . $rowNum);
            $gambarSel->setPath($path);
            $gambarSel->setHeight(self::TINGGI_GAMBAR_EXPORT);
            $gambarSel->setOffsetX(6);
            $gambarSel->setOffsetY(6);
            $gambarSel->setCoordinates($huruf . $rowNum);
            $gambarSel->setWorksheet($sheet);

            $sheet->getRowDimension($rowNum)->setRowHeight(self::TINGGI_BARIS_GAMBAR);

            $terpasang++;
        }

        return $terpasang;
    }

    /**
     * Jadikan sel kolom tautan foto bisa diklik bila selnya memuat satu tautan
     * saja; sel dengan banyak tautan dibiarkan sebagai teks karena satu sel
     * hanya bisa menunjuk satu alamat.
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @param  int    $rowNum               Nomor baris Excel
     * @param  Report  $report
     * @param  array<string, string|null>  $hurufTautan  Huruf kolom tautan per jenis foto
     * @return void
     */
    private function pasangTautanFotoExport($sheet, int $rowNum, Report $report, array $hurufTautan): void
    {
        foreach (['dokumentasi', 'hygiene'] as $jenis) {
            $huruf = $hurufTautan[$jenis] ?? null;

            if ($huruf === null) {
                continue;
            }

            $tautan = $this->tautanFotoExport($report, $jenis);

            if (str_contains($tautan, "\n") || ! str_starts_with($tautan, 'http')) {
                continue;
            }

            $sheet->getCell($huruf . $rowNum)->getHyperlink()->setUrl($tautan);
        }
    }

    /**
     * Lokasi & alat satu laporan secara lengkap dan jelas: PT, departemen,
     * area, sub area, section, kode alat, deskripsi alat, dan nomor equipment.
     *
     * Sumbernya berurutan: lokasi pekerjaan (Functional Location) lalu alat
     * yang diperbaiki; bila keduanya kosong dipakai Area laporan. Dengan begitu
     * laporan pekerjaan area tetap punya keterangan lokasi yang bisa dibaca.
     *
     * @param  Report  $report
     * @return array{pt: string, departemen: string, area: string, sub_area: string, section: string, kode_alat: string, deskripsi_alat: string, equipment_no: string}
     */
    private function lokasiExportLaporan(Report $report): array
    {
        $funcloc = $report->functionalLocation;
        $asset   = $report->asset;

        $company    = $funcloc?->company ?? $asset?->company ?? $report->area?->department?->company;
        $department = $funcloc?->department ?? $asset?->department;
        $area       = $funcloc?->area ?? $asset?->area ?? $report->area;
        $subArea    = $funcloc?->subArea ?? $asset?->subArea;

        $kodeAlat = $asset?->tech_ident_no ?: null;
        $kodeAlat = $kodeAlat ?? ($report->equipment_tag ?: null);
        $kodeAlat = $kodeAlat ?? ($asset?->equipment_no ?: null);

        return [
            'pt'             => $this->labelKodeNama($company?->code, $company?->name),
            'departemen'     => $this->labelKodeNama($department?->code, $department?->name),
            'area'           => $this->labelKodeNama($area?->code, $area?->name),
            'sub_area'       => $this->labelKodeNama($subArea?->code, $subArea?->name),
            'section'        => $this->labelKodeNama($funcloc?->code, $funcloc?->name),
            'kode_alat'      => (string) ($kodeAlat ?? '-'),
            'deskripsi_alat' => (string) ($asset?->description ?? '-'),
            'equipment_no'   => (string) ($asset?->equipment_no ?? '-'),
        ];
    }

    /**
     * Gabung kode dan nama lokasi menjadi satu keterangan, mis.
     * "EPE - PT Ethylene Polyethylene". Bagian yang kosong dibuang; hasil
     * kosong ditandai "-" supaya sel tidak pernah tampak bolong tanpa alasan.
     *
     * @param  string|null  $kode
     * @param  string|null  $nama
     * @return string
     */
    private function labelKodeNama(?string $kode, ?string $nama): string
    {
        $bagian = array_values(array_filter(
            [trim((string) $kode), trim((string) $nama)],
            fn ($teks) => $teks !== ''
        ));

        return $bagian === [] ? '-' : implode(' - ', $bagian);
    }

    /**
     * Nama hari Bahasa Indonesia dari tanggal laporan.
     *
     * @param  Carbon|null  $tanggal
     * @return string
     */
    private function hariIndo(?Carbon $tanggal): string
    {
        if ($tanggal === null) {
            return '-';
        }

        $hari = [
            'Monday'    => 'Senin',
            'Tuesday'   => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday'  => 'Kamis',
            'Friday'    => 'Jumat',
            'Saturday'  => 'Sabtu',
            'Sunday'    => 'Minggu',
        ];

        return $hari[$tanggal->format('l')] ?? $tanggal->format('l');
    }

    /**
     * Rapikan jam kerja dari database (mis. "08:00:00") menjadi "08:00".
     *
     * @param  string|null  $jam
     * @return string
     */
    private function jamExport(?string $jam): string
    {
        $teks = trim((string) $jam);

        if ($teks === '') {
            return '-';
        }

        return strlen($teks) >= 5 ? substr($teks, 0, 5) : $teks;
    }

    /**
     * Durasi menit menjadi teks "8 jam 30 mnt".
     *
     * @param  int  $menit
     * @return string
     */
    private function durasiJamMenit(int $menit): string
    {
        if ($menit <= 0) {
            return '-';
        }

        return intdiv($menit, 60) . ' jam ' . str_pad((string) ($menit % 60), 2, '0', STR_PAD_LEFT) . ' mnt';
    }

    /**
     * Label Bagian - Group seorang teknisi (mis. "Mekanik - Reguler Staff").
     *
     * @param  Technician|null  $teknisi
     * @return string
     */
    private function labelBagianGroupExport(?Technician $teknisi): string
    {
        if ($teknisi === null) {
            return '-';
        }

        $bagian = Technician::SECTIONS[$teknisi->section] ?? ($teknisi->section ?: null);
        $grup   = Technician::GROUPS[$teknisi->group] ?? ($teknisi->group ?: null);

        $bagian = array_values(array_filter([$bagian, $grup], fn ($teks) => $teks !== null && $teks !== ''));

        return $bagian === [] ? '-' : implode(' - ', $bagian);
    }

    /**
     * Nama seluruh kolaborator satu laporan (dipisah koma). Laporan kolaborasi
     * tetap SATU baris; daftar nama inilah bentuk keterangannya.
     *
     * @param  Report  $report
     * @return string
     */
    private function kolaboratorExport(Report $report): string
    {
        $nama = $report->collaboratorReports
            ->map(fn ($baris) => $baris->technician?->name)
            ->filter()
            ->values()
            ->implode(', ');

        return $nama !== '' ? $nama : '-';
    }

    /**
     * Keterangan alat/lokasi tambahan yang dikerjakan bersamaan (pekerjaan
     * multi item) pada laporan induk, mis. "2 item: ALCY01 - Pompa A; ALCY02".
     *
     * @param  Report  $report
     * @return string
     */
    private function alatTambahanExport(Report $report): string
    {
        $items = $report->multiItems;

        if ($items->isEmpty()) {
            return '-';
        }

        return $items->count() . ' item: '
            . $items->map(fn ($item) => $item->multi_item_label)->implode('; ');
    }

    /**
     * Label status pekerjaan (sama dengan lencana di halaman Laporan).
     *
     * @param  string|null  $status
     * @return string
     */
    private function labelStatusExport(?string $status): string
    {
        $peta = [
            'completed'    => 'Done',
            'continue'     => 'Continue',
            'needs_review' => 'Perlu Diperiksa',
        ];

        $kunci = trim((string) $status);

        if ($kunci === '') {
            return '-';
        }

        return $peta[$kunci] ?? $kunci;
    }

    /**
     * Label tipe laporan (sama dengan pilihan pada filter lanjutan).
     *
     * @param  string|null  $tipe
     * @return string
     */
    private function labelTipeExport(?string $tipe): string
    {
        $peta = [
            'equipment_repair' => 'Perbaikan Equipment',
            'area_work'        => 'Pekerjaan Area',
            'general'          => 'Umum',
        ];

        $kunci = trim((string) $tipe);

        if ($kunci === '') {
            return '-';
        }

        return $peta[$kunci] ?? $kunci;
    }

    /**
     * Label klasifikasi pekerjaan wizard (kode ZPM).
     *
     * @param  string|null  $klasifikasi
     * @return string
     */
    private function labelKlasifikasiExport(?string $klasifikasi): string
    {
        $peta = [
            'zpm1' => 'ZPM 1 - Corrective',
            'zpm3' => 'ZPM 3 - Preventive',
            'zpm5' => 'ZPM 5 - Fabrikasi',
            'zpm6' => 'ZPM 6 - Planned',
            'zpm7' => 'ZPM 7 - Sipil',
            'zpm8' => 'ZPM 8 - Project',
        ];

        $kunci = trim((string) $klasifikasi);

        if ($kunci === '') {
            return '-';
        }

        return $peta[$kunci] ?? $kunci;
    }

    /**
     * Label kolom "Sesuai Rencana" (hanya diisi untuk pekerjaan dCM).
     *
     * @param  string|null  $nilai
     * @return string
     */
    private function labelSesuaiRencanaExport(?string $nilai): string
    {
        return match (trim((string) $nilai)) {
            'ya'    => 'Ya',
            'tidak' => 'Tidak',
            default => '-',
        };
    }

    /**
     * Lembar "Ringkasan": rekap jumlah & durasi pekerjaan per jenis pekerjaan,
     * per status, per PT, dan per teknisi.
     *
     * @param  Spreadsheet  $spreadsheet
     * @param  \Illuminate\Support\Collection<int, Report>  $laporan
     * @param  Request  $request
     * @return void
     */
    private function tulisSheetRingkasan(Spreadsheet $spreadsheet, $laporan, Request $request): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Ringkasan');

        $jumlah     = $laporan->count();
        $totalMenit = (int) $laporan->sum(fn (Report $report) => (int) ($report->work_duration_minutes ?? 0));

        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'RINGKASAN LAPORAN PEKERJAAN');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $info = [
            2 => 'Periode: ' . $this->periodeExport($request),
            3 => 'Filter: ' . $this->ringkasanFilterExport($request),
            4 => 'Total: ' . number_format($jumlah) . ' laporan | Total durasi: '
                . number_format($totalMenit) . ' menit (' . $this->durasiJamMenit($totalMenit) . ')'
                . ' | Rata-rata: ' . ($jumlah > 0 ? round($totalMenit / $jumlah, 1) . ' menit/laporan' : '-'),
        ];

        foreach ($info as $nomor => $teks) {
            $sheet->mergeCells('A' . $nomor . ':E' . $nomor);
            $sheet->setCellValue('A' . $nomor, $teks);
            $sheet->getStyle('A' . $nomor)->getFont()->setSize(10)->getColor()->setARGB('FF475569');
            $sheet->getStyle('A' . $nomor)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        foreach (['A' => 34, 'B' => 12, 'C' => 18, 'D' => 15, 'E' => 10] as $huruf => $lebar) {
            $sheet->getColumnDimension($huruf)->setWidth($lebar);
        }

        $baris = 6;

        $baris = $this->tulisBlokRekap($sheet, $baris, 'Per Jenis Pekerjaan', $this->rekapExport(
            $laporan,
            fn (Report $report) => trim((string) $report->jenis_pekerjaan) !== ''
                ? (string) $report->jenis_pekerjaan
                : 'Tanpa Jenis'
        ), $jumlah);

        $baris = $this->tulisBlokRekap($sheet, $baris, 'Per Status Pekerjaan', $this->rekapExport(
            $laporan,
            fn (Report $report) => $this->labelStatusExport($report->status)
        ), $jumlah);

        $baris = $this->tulisBlokRekap($sheet, $baris, 'Per PT', $this->rekapExport(
            $laporan,
            fn (Report $report) => $this->lokasiExportLaporan($report)['pt']
        ), $jumlah);

        $this->tulisBlokRekap($sheet, $baris, 'Per Teknisi', $this->rekapExport(
            $laporan,
            fn (Report $report) => (string) ($report->technician?->name ?? 'Tanpa teknisi')
        ), $jumlah);
    }

    /**
     * Hitung rekap jumlah & durasi per kategori, urut dari yang terbanyak.
     *
     * @param  \Illuminate\Support\Collection<int, Report>  $laporan
     * @param  callable  $kunci  Fungsi penentu label kategori satu laporan
     * @return array<int, array{label: string, jumlah: int, menit: int}>
     */
    private function rekapExport($laporan, callable $kunci): array
    {
        $hasil = [];

        foreach ($laporan as $report) {
            $label = trim((string) $kunci($report));
            $label = $label !== '' ? $label : '(kosong)';

            if (!isset($hasil[$label])) {
                $hasil[$label] = ['label' => $label, 'jumlah' => 0, 'menit' => 0];
            }

            $hasil[$label]['jumlah']++;
            $hasil[$label]['menit'] += (int) ($report->work_duration_minutes ?? 0);
        }

        $hasil = array_values($hasil);

        usort($hasil, function ($a, $b) {
            return ($b['jumlah'] <=> $a['jumlah']) ?: strcmp($a['label'], $b['label']);
        });

        return $hasil;
    }

    /**
     * Tulis satu blok rekap (judul + header + baris + total) dan kembalikan
     * nomor baris kosong berikutnya.
     *
     * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
     * @param  int     $baris         Baris awal blok
     * @param  string  $judul         Judul blok rekap
     * @param  array<int, array{label: string, jumlah: int, menit: int}>  $rekap
     * @param  int     $jumlahTotal   Jumlah laporan seluruh filter (untuk porsi)
     * @return int  Baris berikutnya setelah blok
     */
    private function tulisBlokRekap($sheet, int $baris, string $judul, array $rekap, int $jumlahTotal): int
    {
        $sheet->mergeCells('A' . $baris . ':E' . $baris);
        $sheet->setCellValue('A' . $baris, $judul);
        $sheet->getStyle('A' . $baris)->getFont()->setBold(true)->getColor()->setARGB('FF0B7A6E');
        $sheet->getStyle('A' . $baris)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F3F0');
        $sheet->getStyle('A' . $baris)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($baris)->setRowHeight(20);
        $baris++;

        foreach (['Kategori', 'Jumlah', 'Total Durasi (menit)', 'Durasi (jam mnt)', 'Porsi'] as $i => $teks) {
            $huruf = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($huruf . $baris, $teks);

            $style = $sheet->getStyle($huruf . $baris);
            $style->getFont()->setBold(true)->getColor()->setARGB('FF334155');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        }

        $sheet->getRowDimension($baris)->setRowHeight(26);
        $baris++;

        if ($rekap === []) {
            $sheet->setCellValue('A' . $baris, '(tidak ada data pada filter ini)');
            $sheet->getStyle('A' . $baris)->getFont()->setItalic(true)->getColor()->setARGB('FF94A3B8');

            return $baris + 2;
        }

        $jumlahBlok = 0;
        $menitBlok  = 0;

        foreach ($rekap as $i => $item) {
            $nilai = [
                $item['label'],
                number_format($item['jumlah']),
                number_format($item['menit']),
                $this->durasiJamMenit($item['menit']),
                $jumlahTotal > 0 ? round($item['jumlah'] / $jumlahTotal * 100, 1) . '%' : '0%',
            ];

            foreach ($nilai as $k => $teks) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($k + 1) . $baris,
                    (string) $teks,
                    DataType::TYPE_STRING
                );
            }

            $rentang = 'A' . $baris . ':E' . $baris;
            $sheet->getStyle($rentang)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');

            if ($i % 2 === 1) {
                $sheet->getStyle($rentang)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }

            $jumlahBlok += $item['jumlah'];
            $menitBlok  += $item['menit'];
            $baris++;
        }

        foreach ([
            'TOTAL',
            number_format($jumlahBlok),
            number_format($menitBlok),
            $this->durasiJamMenit($menitBlok),
            $jumlahTotal > 0 ? round($jumlahBlok / $jumlahTotal * 100, 1) . '%' : '0%',
        ] as $k => $teks) {
            $huruf = Coordinate::stringFromColumnIndex($k + 1);
            $sheet->setCellValueExplicit($huruf . $baris, (string) $teks, DataType::TYPE_STRING);
        }

        $sheet->getStyle('A' . $baris . ':E' . $baris)->getFont()->setBold(true);
        $sheet->getStyle('A' . $baris . ':E' . $baris)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        $sheet->getStyle('A' . $baris . ':E' . $baris)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F3F0');

        return $baris + 2;
    }

    /**
     * Lembar "Petunjuk": dokumentasi berkas berisi aturan baris data dan arti
     * setiap kolom, supaya berkas bisa dibaca tanpa bertanya ke pembuatnya.
     *
     * @param  Spreadsheet  $spreadsheet
     * @param  Request      $request
     * @param  int          $jumlahBaris  Jumlah baris data pada lembar pertama
     * @return void
     */
    private function tulisSheetPetunjuk(Spreadsheet $spreadsheet, Request $request, int $jumlahBaris): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Petunjuk');

        $sheet->mergeCells('A1:C1');
        $sheet->setCellValue('A1', 'PETUNJUK & DOKUMENTASI BERKAS EXPORT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $info = [
            2 => 'Sistem Manajemen Pemeliharaan - Oleochemical Maintenance',
            3 => 'Berkas dibuat: ' . now()->format('d/m/Y H:i') . ' WIB'
                . (auth()->check() ? ' oleh ' . auth()->user()->name : ''),
            4 => 'Periode: ' . $this->periodeExport($request),
            5 => 'Filter: ' . $this->ringkasanFilterExport($request),
            6 => 'Jumlah baris data: ' . number_format($jumlahBaris) . ' laporan (lembar "Daftar Laporan" baris 7 ke bawah)',
        ];

        foreach ($info as $nomor => $teks) {
            $sheet->mergeCells('A' . $nomor . ':C' . $nomor);
            $sheet->setCellValue('A' . $nomor, $teks);
            $sheet->getStyle('A' . $nomor)->getFont()->setSize(10)->getColor()->setARGB('FF475569');
            $sheet->getStyle('A' . $nomor)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(105);

        $catatan = [
            'Satu laporan = satu baris. Salinan kolaborasi dan salinan multi alat TIDAK menghasilkan baris tersendiri.',
            'Nama rekan yang ikut mengerjakan dicantumkan pada kolom "Kolaborator", dipisah tanda koma.',
            'Alat/lokasi lain yang dikerjakan bersamaan dicantumkan pada kolom "Alat / Lokasi Tambahan".',
            'Durasi memakai durasi pengerjaan yang dicatat teknisi (menit). Tanda "-" berarti data belum diisi.',
            'Kolom lokasi diambil dari Section (lokasi pekerjaan) dan alat yang diperbaiki; bila keduanya kosong dipakai Area laporan.',
            'Semua nilai ditulis sebagai teks supaya kode alat dan nomor equipment tidak berubah saat dibuka di Excel.',
            'Foto ditulis sebagai tautan pada kolom "' . self::KOLOM_TAUTAN_DOKUMENTASI . '" dan "' . self::KOLOM_TAUTAN_HYGIENE . '" (satu tautan per baris dalam sel).',
            'Bila export diminta menyertakan foto, kolom gambar memuat foto PERTAMA tiap jenis ditempel langsung ke berkas (maksimal '
                . number_format(self::BATAS_GAMBAR_EXPORT) . ' gambar); foto selebihnya dibuka lewat tautan.',
        ];

        if ($request->boolean('sertakan_foto')) {
            array_unshift(
                $catatan,
                'Berkas ini MENYERTAKAN GAMBAR FOTO pada kolom "... (gambar)"; foto yang berkasnya tidak ada di server ini hanya diberi keterangan teks.'
            );
        }

        $baris = 8;
        $sheet->mergeCells('A' . $baris . ':C' . $baris);
        $sheet->setCellValue('A' . $baris, 'ATURAN BARIS DATA');
        $sheet->getStyle('A' . $baris)->getFont()->setBold(true)->getColor()->setARGB('FF0B7A6E');
        $sheet->getStyle('A' . $baris)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F3F0');
        $baris++;

        foreach ($catatan as $teks) {
            $sheet->mergeCells('A' . $baris . ':C' . $baris);
            $sheet->setCellValue('A' . $baris, '- ' . $teks);
            $sheet->getStyle('A' . $baris)->getFont()->setSize(10)->getColor()->setARGB('FF334155');
            $sheet->getStyle('A' . $baris)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $baris++;
        }

        $baris++;

        $sheet->mergeCells('A' . $baris . ':C' . $baris);
        $sheet->setCellValue('A' . $baris, 'KETERANGAN KOLOM');
        $sheet->getStyle('A' . $baris)->getFont()->setBold(true)->getColor()->setARGB('FF0B7A6E');
        $sheet->getStyle('A' . $baris)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F3F0');
        $baris++;

        foreach (['Kolom', 'Nama Kolom', 'Keterangan'] as $i => $teks) {
            $huruf = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($huruf . $baris, $teks);

            $style = $sheet->getStyle($huruf . $baris);
            $style->getFont()->setBold(true)->getColor()->setARGB('FF334155');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        }

        $baris++;

        $keterangan = $this->keteranganKolomExport();

        foreach ($this->kolomExportLaporan() as $i => $definisi) {
            $nilai = [
                Coordinate::stringFromColumnIndex($i + 1),
                $definisi['judul'],
                $keterangan[$definisi['judul']] ?? '-',
            ];

            foreach ($nilai as $k => $teks) {
                $huruf = Coordinate::stringFromColumnIndex($k + 1);
                $sheet->setCellValueExplicit($huruf . $baris, (string) $teks, DataType::TYPE_STRING);
                $sheet->getStyle($huruf . $baris)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            }

            $sheet->getStyle('A' . $baris . ':C' . $baris)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');

            $baris++;
        }
    }

    /**
     * Keterangan arti setiap kolom berkas export, dipakai lembar "Petunjuk".
     *
     * @return array<string, string>  Kunci = nama kolom (judul di baris 7)
     */
    private function keteranganKolomExport(): array
    {
        return [
            'No'                     => 'Nomor urut baris pada berkas ini.',
            'Kode Laporan'           => 'Kode laporan sistem, mis. RPT-20261008-0001.',
            'Tanggal'                => 'Tanggal pekerjaan (report_date).',
            'Hari'                   => 'Nama hari dari tanggal pekerjaan.',
            'Jam Mulai'              => 'Jam mulai pekerjaan (diisi teknisi, boleh kosong).',
            'Jam Selesai'            => 'Jam selesai pekerjaan (diisi teknisi, boleh kosong).',
            'Durasi (menit)'         => 'Durasi pengerjaan yang dicatat teknisi dalam menit; angka inilah yang dipakai perhitungan man hour.',
            'Durasi (jam mnt)'       => 'Durasi yang sama dalam format jam dan menit.',
            'Teknisi'                => 'Teknisi pemilik laporan (pelaksana).',
            'NIK'                    => 'NIK teknisi pelaksana.',
            'Bagian / Group'         => 'Bagian (section) dan Group kerja teknisi, mis. "Mekanik - Reguler Staff".',
            'Kolaborator'            => 'Nama rekan yang ikut mengerjakan laporan ini (dipisah koma). Kolaborasi tetap satu baris, tidak dibuat baris tambahan.',
            'Jenis Pekerjaan'        => 'Klasifikasi pekerjaan: CM, dCM, PM, CM/PM, EI, Mekanik, Fabrikasi, Sipil, IT, atau Project.',
            'Klasifikasi (ZPM)'      => 'Klasifikasi wizard ZPM (ZPM 1 Corrective, ZPM 3 Preventive, ZPM 5 Fabrikasi, ZPM 6 Planned, ZPM 7 Sipil, ZPM 8 Project).',
            'Tipe Laporan'           => 'Perbaikan Equipment, Pekerjaan Area, atau Umum.',
            'Status'                 => 'Done (selesai), Continue (belum selesai), atau Perlu Diperiksa.',
            'Lanjutan'               => 'Ya bila laporan ini lanjutan dari laporan sebelumnya (continued_from_id).',
            'PT'                     => 'Perusahaan/PT lokasi pekerjaan (kode - nama).',
            'Departemen'             => 'Departemen lokasi pekerjaan (kode - nama).',
            'Area'                   => 'Area lokasi pekerjaan (kode - nama).',
            'Sub Area'               => 'Sub area lokasi pekerjaan (kode - nama), bila ada.',
            'Section (Lokasi)'       => 'Functional Location tempat pekerjaan dilakukan (kode - nama section).',
            'Kode Alat'              => 'Kode alat (tech ident no) yang dikerjakan; bila alat belum terdaftar dipakai Equipment Tag laporan.',
            'Deskripsi Alat'         => 'Nama/deskripsi alat dari master asset.',
            'Equipment No'           => 'Nomor equipment aset di master data.',
            'Alat / Lokasi Tambahan' => 'Alat/lokasi lain yang dikerjakan bersamaan pada laporan ini, dengan jumlah itemnya.',
            'Deskripsi Pekerjaan'    => 'Uraian pekerjaan yang ditulis teknisi.',
            'Temuan'                 => 'Temuan/kejadian di lapangan yang dicatat teknisi (opsional).',
            'Penyebab (Root Cause)'  => 'Penyebab pekerjaan/kerusakan menurut teknisi (opsional).',
            'Sesuai Rencana'         => 'Khusus pekerjaan dCM: Ya bila sesuai rencana, Tidak bila di luar rencana.',
            'Tanggal Kejadian'       => 'Tanggal kejadian/kerusakan, bila dicatat terpisah dari tanggal pekerjaan.',
            'Foto Dokumentasi'       => 'Jumlah foto dokumentasi pekerjaan yang tersimpan.',
            'Foto Hygiene'           => 'Jumlah foto hygiene clearance yang tersimpan.',
            'Kecocokan AI'           => 'Nilai kecocokan analisa AI dalam persen, atau "belum dianalisa".',
            'Dicatat Pada'           => 'Waktu laporan dibuat/diinput ke sistem.',
            self::KOLOM_TAUTAN_DOKUMENTASI => 'Alamat (tautan) foto dokumentasi pekerjaan; ditulis satu tautan per baris bila fotonya lebih dari satu.',
            self::KOLOM_TAUTAN_HYGIENE     => 'Alamat (tautan) foto hygiene clearance; ditulis satu tautan per baris bila fotonya lebih dari satu.',
            self::KOLOM_GAMBAR_DOKUMENTASI => 'Gambar foto dokumentasi; kolom ini hanya ada bila export diminta menyertakan foto, isinya menyebut jumlah fotonya.',
            self::KOLOM_GAMBAR_HYGIENE     => 'Gambar foto hygiene clearance; kolom ini hanya ada bila export diminta menyertakan foto, isinya menyebut jumlah fotonya.',
        ];
    }

}
