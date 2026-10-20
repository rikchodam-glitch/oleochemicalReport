<?php

namespace App\Services;

use App\Models\Report;
use Illuminate\Support\Str;

/**
 * Menyusun dan mengirim broadcast kolaborasi ke teknisi rekan kerja.
 *
 * Satu laporan induk dapat punya banyak baris salinan kolaborator. Penerima
 * broadcast adalah teknisi pemilik baris salinan tersebut, bukan pelaksana
 * utama. Pesan memuat detail pekerjaan dan seluruh nama kolaborator.
 */
class ReportCollaborationNotifier
{
    public function __construct(private readonly TelegramService $telegram)
    {
    }

    /**
     * Kirim broadcast ke kolaborator laporan.
     *
     * @param  Report       $induk       Laporan induk
     * @param  array<int, int>|null $penerimaIds Batasi ke teknisi tertentu (null = semua)
     * @return array<int, bool>          Hasil per technician_id
     */
    public function kirim(Report $induk, ?array $penerimaIds = null): array
    {
        // Hanya laporan induk yang boleh memicu broadcast. Salinan kolaborasi
        // dan salinan multi alat tidak boleh mengirim broadcast berulang.
        if ($induk->collaborator_of !== null || $induk->multi_of !== null) {
            return [];
        }

        $induk->loadMissing([
            'technician',
            'asset',
            'functionalLocation',
            'collaboratorReports.technician',
        ]);

        $kolaborator = $induk->collaboratorReports
            ->map(fn ($baris) => $baris->technician)
            ->filter()
            ->unique('id')
            ->values();

        $penerima = $kolaborator;

        if ($penerimaIds !== null) {
            $penerima = $penerima->whereIn('id', $penerimaIds)->values();
        }

        $penerima = $penerima
            ->filter(fn ($teknisi) => (int) $teknisi->id !== (int) $induk->technician_id)
            ->filter(fn ($teknisi) => trim((string) $teknisi->telegram_id) !== '')
            ->values();

        if ($penerima->isEmpty()) {
            return [];
        }

        $pesan = $this->pesan($induk, $kolaborator);
        $hasil = [];

        foreach ($penerima as $teknisi) {
            $hasil[(int) $teknisi->id] = $this->telegram->sendMessage(
                $teknisi->telegram_id,
                $pesan,
                ['disable_web_page_preview' => true]
            );
        }

        return $hasil;
    }

    /**
     * Susun isi pesan broadcast (HTML untuk Telegram).
     *
     * @param  Report $induk       Laporan induk
     * @param  \Illuminate\Support\Collection $kolaborator Daftar teknisi kolaborator
     * @return string
     */
    public function pesan(Report $induk, $kolaborator): string
    {
        $pelaksana = $induk->technician?->name ?? '-';
        $namaKolaborator = $kolaborator->pluck('name')->filter()->implode(', ');

        if ($namaKolaborator === '') {
            $namaKolaborator = '-';
        }

        $lokasi = '-';

        if ($induk->functionalLocation !== null) {
            $lokasi = trim(($induk->functionalLocation->code ?? '') . ' — ' . ($induk->functionalLocation->name ?? ''), ' —');
        } elseif ($induk->asset !== null) {
            $lokasi = trim(($induk->asset->tech_ident_no ?? '') . ' — ' . ($induk->asset->description ?? ''), ' —');
        }

        if ($lokasi === '') {
            $lokasi = '-';
        }

        $klasifikasi = trim(($induk->work_classification ?? '') . ' / ' . ($induk->jenis_pekerjaan ?? ''), ' /');
        $klasifikasi = $klasifikasi !== '' ? $klasifikasi : '-';

        $menit = (int) ($induk->work_duration_minutes ?? 0);
        $durasi = $menit > 0
            ? intdiv($menit, 60) . ' jam ' . ($menit % 60) . ' menit'
            : '-';

        $deskripsi = Str::limit(trim((string) ($induk->work_description ?? '')), 700) ?: '-';
        $rootCause = Str::limit(trim((string) ($induk->root_cause ?? '')), 300) ?: '-';
        $temuan    = Str::limit(trim((string) ($induk->finding ?? '')), 300) ?: '-';

        return "<b>BROADCAST KOLABORASI</b>\n\n"
            . '<b>Kode</b>: ' . e($induk->report_code ?? '-') . "\n"
            . '<b>Pelaksana</b>: ' . e($pelaksana) . "\n"
            . '<b>Tanggal</b>: ' . e($induk->report_date?->format('d/m/Y') ?? '-') . "\n"
            . '<b>Klasifikasi</b>: ' . e($klasifikasi) . "\n"
            . '<b>Lokasi / Equipment</b>: ' . e($lokasi) . "\n"
            . '<b>Deskripsi</b>: ' . e($deskripsi) . "\n"
            . '<b>Durasi</b>: ' . e($durasi) . "\n"
            . '<b>Root Cause</b>: ' . e($rootCause) . "\n"
            . '<b>Temuan</b>: ' . e($temuan) . "\n"
            . '<b>Kolaborator</b>: ' . e($namaKolaborator) . "\n\n"
            . "Anda tercatat sebagai kolaborator pada pekerjaan ini.\n"
            . 'Detail laporan: ' . route('reports.show', $induk);
    }
}
