<?php

namespace App\Http\Controllers\Traits;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 * Export Excel lengkap Assets Management.
 *
 * Berbeda dari export Excel format impor:
 *   - mengikuti SELURUH filter halaman index, termasuk baris cermin IT Assets
 *     (CCTV, switch, PC, dan lainnya);
 *   - menambahkan kolom identitas IT, spesifikasi, status ping, laporan, dan
 *     foto/snapshot;
 *   - menyematkan gambar utama (snapshot CCTV atau foto equipment) ke workbook;
 *   - kolom tambahan mengikuti kategori filter, mis. CCTV memiliki NVR/Channel/
 *     Pointing, sedangkan switch memiliki Connect To/VLAN/Port.
 *
 * File ini hanya untuk dibaca/diunduh; tidak dipakai sebagai format import.
 */
trait AssetExportLengkapTrait
{
    /**
     * Ekspor Excel lengkap sesuai filter aktif.
     *
     * @param  Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportExcelLengkap(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $assets = $this->buildExportQuery($request, true)
            ->with(['itAsset', 'technicians'])
            ->withCount('reports')
            ->withMax('reports as laporan_terakhir', 'report_date')
            ->get();

        $jenis  = $this->deteksiJenisExport($request, $assets);
        $label  = $this->labelJenisExport($jenis);
        $kolom  = $this->kolomExportLengkap($jenis);
        $headers = array_merge([
            'No', 'Equipment No', 'Description', 'Tech Ident No', 'Equipment Type',
            'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
            'Manufacturer', 'Model Number', 'Construct Year', 'Status',
            'Data Source',
        ], array_column($kolom, 'header'));

        $jumlahKolom   = count($headers);
        $kolomTerakhir = Coordinate::stringFromColumnIndex($jumlahKolom);
        $barisHeader   = 7;
        $sementara     = [];

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Assets Lengkap');

        $judul = 'DAFTAR ASSET' . ($label !== '' ? ' — ' . strtoupper($label) : ' — ASSET MANAGEMENT');
        $sheet->mergeCells('A1:' . $kolomTerakhir . '1');
        $sheet->setCellValue('A1', $judul);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $barisInfo = [
            2 => 'Sistem Manajemen Pemeliharaan — Oleochemical Maintenance',
            3 => 'Diekspor: ' . now()->format('d/m/Y H:i') . ' WIB'
                . (auth()->check() ? ' oleh ' . auth()->user()->name : ''),
            4 => 'Filter: ' . $this->ringkasanFilterExport($request),
            5 => 'Jumlah: ' . number_format($assets->count()) . ' asset',
            6 => 'Tampilan: ' . ($label !== '' ? $label . ' — ' : '') . 'data lengkap termasuk foto/snapshot',
        ];

        foreach ($barisInfo as $nomor => $teks) {
            $sheet->mergeCells('A' . $nomor . ':' . $kolomTerakhir . $nomor);
            $sheet->setCellValue('A' . $nomor, $teks);
            $sheet->getStyle('A' . $nomor)->getFont()->setSize(10)->getColor()->setARGB('FF475569');
            $sheet->getStyle('A' . $nomor)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($nomor)->setRowHeight(18);
        }

        foreach ($headers as $indeks => $header) {
            $kolomHuruf = Coordinate::stringFromColumnIndex($indeks + 1);
            $sheet->setCellValue($kolomHuruf . $barisHeader, $header);
            $style = $sheet->getStyle($kolomHuruf . $barisHeader);
            $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
            $style->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF0B7A6E');
        }

        $sheet->getRowDimension($barisHeader)->setRowHeight(30);

        $indeksGambar = null;

        foreach ($kolom as $indeks => $definisi) {
            if (! empty($definisi['gambar'])) {
                $indeksGambar = 15 + $indeks + 1;
                break;
            }
        }

        $kolomGambar = $indeksGambar !== null ? Coordinate::stringFromColumnIndex($indeksGambar) : null;
        $rowNum      = $barisHeader + 1;
        $no          = 1;

        foreach ($assets as $asset) {
            $nilai = [
                $no,
                $asset->equipment_no,
                $asset->description,
                $asset->tech_ident_no,
                $asset->equipment_type,
                $asset->functional_loc,
                $asset->company?->code ?? '',
                $asset->department?->code ?? '',
                $asset->area?->code ?? '',
                $asset->subArea?->code ?? '',
                $asset->manufacturer,
                $asset->model_number,
                $asset->construct_year,
                $asset->status,
                $asset->data_source,
            ];

            foreach ($kolom as $definisi) {
                $nilai[] = ($definisi['nilai'])($asset);
            }

            foreach ($nilai as $indeks => $isi) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($indeks + 1) . $rowNum,
                    (string) ($isi ?? ''),
                    DataType::TYPE_STRING
                );
            }

            $sheet->getStyle('A' . $rowNum . ':' . $kolomTerakhir . $rowNum)
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');
            $sheet->getStyle('A' . $rowNum . ':' . $kolomTerakhir . $rowNum)
                ->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

            if ($no % 2 === 0) {
                $sheet->getStyle('A' . $rowNum . ':' . $kolomTerakhir . $rowNum)
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }

            if ($kolomGambar !== null) {
                $gambarAsli = $this->pathGambarUtama($asset);

                if ($gambarAsli !== null) {
                    $gambar = $this->thumbnailGambar($gambarAsli, $sementara);

                    if ($gambar !== null) {
                        $drawing = new Drawing();
                        $drawing->setName('Foto ' . ($asset->tech_ident_no ?? $asset->id));
                        $drawing->setDescription('Foto atau snapshot equipment');
                        $drawing->setPath($gambar);
                        $drawing->setHeight(72);
                        $drawing->setCoordinates($kolomGambar . $rowNum);
                        $drawing->setOffsetX(6);
                        $drawing->setOffsetY(4);
                        $drawing->setWorksheet($sheet);
                        $sheet->getRowDimension($rowNum)->setRowHeight(82);
                    } else {
                        $sheet->setCellValueExplicit(
                            $kolomGambar . $rowNum,
                            'Gambar tidak dapat dibaca',
                            DataType::TYPE_STRING
                        );
                    }
                } else {
                    $sheet->setCellValueExplicit(
                        $kolomGambar . $rowNum,
                        'Tidak ada foto/snapshot',
                        DataType::TYPE_STRING
                    );
                }
            }

            $rowNum++;
            $no++;
        }

        foreach ($headers as $indeks => $header) {
            $kolomHuruf = Coordinate::stringFromColumnIndex($indeks + 1);
            $sheet->getColumnDimension($kolomHuruf)->setAutoSize(true);
        }

        if ($kolomGambar !== null) {
            $sheet->getColumnDimension($kolomGambar)->setAutoSize(false);
            $sheet->getColumnDimension($kolomGambar)->setWidth(27);
        }

        $linkHeaders = ['Link Snapshot', 'Link Foto Equipment', 'Spesifikasi'];
        foreach ($headers as $indeks => $header) {
            if (in_array($header, $linkHeaders, true)) {
                $huruf = Coordinate::stringFromColumnIndex($indeks + 1);
                $sheet->getColumnDimension($huruf)->setAutoSize(false);
                $sheet->getColumnDimension($huruf)->setWidth($header === 'Spesifikasi' ? 45 : 42);
            }
        }

        if ($assets->isNotEmpty()) {
            $sheet->freezePane('A' . ($barisHeader + 1));
            $sheet->setAutoFilter('A' . $barisHeader . ':' . $kolomTerakhir . $barisHeader);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $barisHeader);

        $writer   = new Xlsx($spreadsheet);
        $namaJenis = $jenis !== '' ? strtolower($jenis) . '-' : '';
        $filename  = 'assets-lengkap-' . $namaJenis . now()->format('Y-m-d-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer, $sementara) {
            try {
                $writer->save('php://output');
            } finally {
                foreach ($sementara as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Tentukan kategori tampilan export dari filter atau isi hasil.
     *
     * @param  Request               $request
     * @param  Collection<int, Asset> $assets
     * @return string CCTV, SWITCH, NVR, PC, LAPTOP, PRINTER, ACCESS, IT, atau ''
     */
    private function deteksiJenisExport(Request $request, Collection $assets): string
    {
        $filter = strtoupper(trim((string) $request->input('equipment_type', '')));

        if ($filter !== '') {
            if (str_contains($filter, 'CCTV')) {
                return 'CCTV';
            }

            if (str_contains($filter, 'NVR')) {
                return 'NVR';
            }

            if (str_contains($filter, 'SWITCH') || str_contains($filter, 'POE') || str_contains($filter, 'UNMANAGED')) {
                return 'SWITCH';
            }

            if (str_contains($filter, 'LAPTOP')) {
                return 'LAPTOP';
            }

            if ($filter === 'PC' || str_starts_with($filter, 'PC ')) {
                return 'PC';
            }

            if (str_contains($filter, 'PRINTER')) {
                return 'PRINTER';
            }

            if (str_contains($filter, 'ACCESS')) {
                return 'ACCESS';
            }

            if (str_contains($filter, 'WIFI') || str_contains($filter, 'NETWORK')
                || str_contains($filter, 'HUB') || str_contains($filter, 'UPS')) {
                return 'IT';
            }
        }

        $tipeIt = $assets
            ->map(fn (Asset $asset): ?string => $asset->itAsset?->device_type)
            ->filter()
            ->unique();

        if ($tipeIt->count() === 1 && $assets->every(fn (Asset $asset): bool => $asset->itAsset !== null)) {
            return match ($tipeIt->first()) {
                'CCTV'                 => 'CCTV',
                'NVR'                  => 'NVR',
                'SWITCH_MANAGED'       => 'SWITCH',
                'SWITCH_POE_UNMANAGED' => 'SWITCH',
                'PC'                   => 'PC',
                'LAPTOP'               => 'LAPTOP',
                'PRINTER'              => 'PRINTER',
                'ACCESS_DOOR'          => 'ACCESS',
                default                => 'IT',
            };
        }

        return $tipeIt->isNotEmpty() ? 'IT' : '';
    }

    /**
     * Label kategori untuk judul export.
     *
     * @param  string $jenis
     * @return string
     */
    private function labelJenisExport(string $jenis): string
    {
        return match ($jenis) {
            'CCTV'    => 'CCTV',
            'SWITCH'  => 'SWITCH',
            'NVR'     => 'NVR',
            'PC'      => 'PC',
            'LAPTOP'  => 'LAPTOP',
            'PRINTER' => 'PRINTER',
            'ACCESS'  => 'ACCESS',
            'IT'      => 'PERANGKAT IT',
            default   => '',
        };
    }

    /**
     * Susun kolom tambahan sesuai kategori export.
     *
     * @param  string $jenis
     * @return array<int, array{header: string, nilai: \Closure, gambar?: bool}>
     */
    private function kolomExportLengkap(string $jenis): array
    {
        $kolom = [
            ['header' => 'Object Type', 'nilai' => fn (Asset $a) => $a->object_type],
            ['header' => 'Company Name', 'nilai' => fn (Asset $a) => $a->company?->name],
            ['header' => 'Department Name', 'nilai' => fn (Asset $a) => $a->department?->name],
            ['header' => 'Area Name', 'nilai' => fn (Asset $a) => $a->area?->name],
            ['header' => 'Sub Area Name', 'nilai' => fn (Asset $a) => $a->subArea?->name],
            ['header' => 'Device Type (IT)', 'nilai' => fn (Asset $a) => $a->itAsset?->deviceLabel()],
            ['header' => 'Nama Perangkat', 'nilai' => fn (Asset $a) => $a->itAsset?->name],
            ['header' => 'Brand', 'nilai' => fn (Asset $a) => $a->itAsset?->brand],
            ['header' => 'Model', 'nilai' => fn (Asset $a) => $a->itAsset?->model],
            ['header' => 'Serial Number', 'nilai' => fn (Asset $a) => $a->itAsset?->serial_number],
            ['header' => 'IP Address', 'nilai' => fn (Asset $a) => $a->itAsset?->ip_address],
            ['header' => 'Plant', 'nilai' => fn (Asset $a) => $a->itAsset?->plant],
            ['header' => 'Departemen (IT)', 'nilai' => fn (Asset $a) => $a->itAsset?->department],
            ['header' => 'Lokasi (IT)', 'nilai' => fn (Asset $a) => $a->itAsset?->location],
            ['header' => 'Application', 'nilai' => fn (Asset $a) => $a->itAsset?->application],
        ];

        if ($jenis === 'CCTV' || $jenis === 'NVR') {
            $kolom = array_merge($kolom, [
                ['header' => 'NVR (Show)', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'show')],
                ['header' => 'Channel', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'channel')],
                ['header' => 'Pointing', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'pointing')],
                ['header' => 'Header', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'header')],
                ['header' => 'PIC', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'pic')],
                ['header' => 'Connect To', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'connect_to')],
            ]);
        }

        if ($jenis === 'SWITCH') {
            $kolom = array_merge($kolom, [
                ['header' => 'Connect To', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'connect_to')],
                ['header' => 'VLAN', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'vlan')],
                ['header' => 'Port', 'nilai' => fn (Asset $a) => $this->nilaiSpec($a, 'port')],
            ]);
        }

        if ($jenis === 'PC' || $jenis === 'LAPTOP') {
            $kolom = array_merge($kolom, [
                ['header' => 'Monitor Model', 'nilai' => fn (Asset $a) => $this->nilaiPcSpec($a, 'Monitor Model')],
                ['header' => 'Monitor Serial', 'nilai' => fn (Asset $a) => $this->nilaiPcSpec($a, 'Monitor Serial')],
                ['header' => 'CPU Model', 'nilai' => fn (Asset $a) => $this->nilaiPcSpec($a, 'CPU Model')],
                ['header' => 'CPU Serial', 'nilai' => fn (Asset $a) => $this->nilaiPcSpec($a, 'CPU Serial')],
                ['header' => 'Processor', 'nilai' => fn (Asset $a) => $this->nilaiPcSpec($a, 'Processor')],
                ['header' => 'RAM', 'nilai' => fn (Asset $a) => $this->nilaiPcSpec($a, 'RAM Kapasitas')],
                ['header' => 'Penyimpanan', 'nilai' => fn (Asset $a) => $this->nilaiPcSpec($a, 'Penyimpanan')],
            ]);
        }

        $kolom = array_merge($kolom, [
            ['header' => 'Spesifikasi', 'nilai' => fn (Asset $a) => $this->ringkasSpesifikasi($a)],
            ['header' => 'Status Ping', 'nilai' => fn (Asset $a) => $a->itAsset?->last_ping_status],
            ['header' => 'Latency (ms)', 'nilai' => fn (Asset $a) => $a->itAsset?->last_ping_latency],
            ['header' => 'Terakhir Dicek', 'nilai' => fn (Asset $a) => $a->itAsset?->last_ping_at?->format('d/m/Y H:i')],
            ['header' => 'Snapshot Diambil', 'nilai' => fn (Asset $a) => $a->itAsset?->last_snapshot_at?->format('d/m/Y H:i')],
            ['header' => 'Jumlah Foto/Snapshot', 'nilai' => fn (Asset $a) => $this->jumlahGambar($a)],
            ['header' => 'Link Snapshot', 'nilai' => fn (Asset $a) => $this->linkSnapshot($a)],
            ['header' => 'Link Foto Equipment', 'nilai' => fn (Asset $a) => $this->linkFoto($a)],
            ['header' => 'Jumlah Laporan', 'nilai' => fn (Asset $a) => $a->reports_count ?? 0],
            ['header' => 'Laporan Terakhir', 'nilai' => fn (Asset $a) => $this->formatTanggal($a->laporan_terakhir)],
            ['header' => 'Teknisi Ditugaskan', 'nilai' => fn (Asset $a) => $a->technicians->pluck('name')->implode(', ')],
            ['header' => 'Diimport', 'nilai' => fn (Asset $a) => $a->imported_at?->format('d/m/Y H:i')],
            ['header' => 'Terakhir Diubah', 'nilai' => fn (Asset $a) => $a->updated_at?->format('d/m/Y H:i')],
            ['header' => 'Latitude', 'nilai' => fn (Asset $a) => $a->itAsset?->latitude],
            ['header' => 'Longitude', 'nilai' => fn (Asset $a) => $a->itAsset?->longitude],
            [
                'header' => $jenis === 'CCTV' ? 'Snapshot CCTV' : 'Foto / Snapshot Equipment',
                'nilai'  => fn () => '',
                'gambar' => true,
            ],
        ]);

        return $kolom;
    }

    /**
     * Nilai satu kunci spec IT.
     *
     * @param  Asset  $asset
     * @param  string $kunci
     * @return mixed
     */
    private function nilaiSpec(Asset $asset, string $kunci)
    {
        return data_get($asset->itAsset?->spec, $kunci);
    }

    /**
     * Nilai satu label spesifikasi PC/Laptop.
     *
     * @param  Asset  $asset
     * @param  string $label
     * @return mixed
     */
    private function nilaiPcSpec(Asset $asset, string $label)
    {
        $items = $asset->itAsset?->pcSpecItems() ?? [];

        return $items[$label] ?? null;
    }

    /**
     * Ringkas seluruh spec IT menjadi satu teks.
     *
     * @param  Asset $asset
     * @return string
     */
    private function ringkasSpesifikasi(Asset $asset): string
    {
        $itAsset = $asset->itAsset;

        if ($itAsset === null) {
            return '';
        }

        $items = $itAsset->specDisplayItems();

        return collect($items)
            ->map(fn ($nilai, $kunci): string => ucwords(str_replace('_', ' ', (string) $kunci)) . ': ' . $nilai)
            ->implode('; ');
    }

    /**
     * Jumlah foto equipment + snapshot CCTV.
     *
     * @param  Asset $asset
     * @return int
     */
    private function jumlahGambar(Asset $asset): int
    {
        return count($asset->photos ?? []) + ($asset->itAsset?->last_snapshot_path ? 1 : 0);
    }

    /**
     * URL snapshot CCTV (bila ada).
     *
     * @param  Asset $asset
     * @return string
     */
    private function linkSnapshot(Asset $asset): string
    {
        $path = $asset->itAsset?->last_snapshot_path;

        if (! $path) {
            return '';
        }

        return url(Storage::disk(config('cctv.snapshot_disk', 'cctv_snapshots'))->url($path));
    }

    /**
     * URL seluruh foto equipment, satu per baris.
     *
     * @param  Asset $asset
     * @return string
     */
    private function linkFoto(Asset $asset): string
    {
        return collect($asset->photos ?? [])
            ->map(fn (string $path): string => url(Storage::disk('public')->url($path)))
            ->implode("\n");
    }

    /**
     * Path gambar utama: snapshot CCTV lebih dulu, lalu foto equipment.
     *
     * @param  Asset $asset
     * @return string|null
     */
    private function pathGambarUtama(Asset $asset): ?string
    {
        $snapshot = $asset->itAsset?->last_snapshot_path;

        if ($snapshot) {
            $path = $this->pathFileStorage(config('cctv.snapshot_disk', 'cctv_snapshots'), $snapshot);

            if ($path !== null) {
                return $path;
            }
        }

        foreach ($asset->photos ?? [] as $foto) {
            $path = $this->pathFileStorage('public', $foto);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Cari file di disk; bila tidak ada, coba salinan PROD di D:.
     *
     * Snapshot PROD disimpan di D:\storage\app\public (storage/app/public
     * adalah junction di PROD). Fallback ini hanya untuk membaca file, tidak
     * pernah menulis, supaya export di DEV tetap bisa menampilkan snapshot
     * yang datanya berasal dari database PROD/DEV yang sama.
     *
     * @param  string $disk
     * @param  string $relativePath
     * @return string|null
     */
    private function pathFileStorage(string $disk, string $relativePath): ?string
    {
        $path = Storage::disk($disk)->path($relativePath);

        if (is_file($path)) {
            return $path;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $cadangan = 'D:/storage/app/public/' . ltrim(str_replace('\\', '/', $relativePath), '/');

            if (is_file($cadangan)) {
                return $cadangan;
            }
        }

        return null;
    }

    /**
     * Buat thumbnail JPEG kecil agar workbook tidak membengkak.
     *
     * @param  string        $path
     * @param  array<int, string> $sementara
     * @return string|null
     */
    private function thumbnailGambar(string $path, array &$sementara): ?string
    {
        if (! extension_loaded('gd')) {
            return $path;
        }

        $data = @file_get_contents($path);

        if ($data === false) {
            return null;
        }

        $sumber = @imagecreatefromstring($data);

        if ($sumber === false) {
            return $path;
        }

        $lebarAsli = imagesx($sumber);
        $tinggiAsli = imagesy($sumber);

        if ($lebarAsli < 1 || $tinggiAsli < 1) {
            imagedestroy($sumber);

            return null;
        }

        $skala = min(320 / $lebarAsli, 200 / $tinggiAsli, 1);
        $lebar = max(1, (int) round($lebarAsli * $skala));
        $tinggi = max(1, (int) round($tinggiAsli * $skala));
        $tujuan = imagecreatetruecolor($lebar, $tinggi);

        imagefill($tujuan, 0, 0, imagecolorallocate($tujuan, 255, 255, 255));
        imagecopyresampled($tujuan, $sumber, 0, 0, 0, 0, $lebar, $tinggi, $lebarAsli, $tinggiAsli);

        $temp = tempnam(sys_get_temp_dir(), 'asset_snap_');
        imagejpeg($tujuan, $temp, 70);

        imagedestroy($sumber);
        imagedestroy($tujuan);

        $sementara[] = $temp;

        return $temp;
    }

    /**
     * Format tanggal Carbon/string untuk sel export.
     *
     * @param  mixed $tanggal
     * @return string
     */
    private function formatTanggal($tanggal): string
    {
        if ($tanggal === null || $tanggal === '') {
            return '';
        }

        if ($tanggal instanceof \DateTimeInterface) {
            return $tanggal->format('d/m/Y');
        }

        try {
            return \Illuminate\Support\Carbon::parse($tanggal)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $tanggal;
        }
    }
}
