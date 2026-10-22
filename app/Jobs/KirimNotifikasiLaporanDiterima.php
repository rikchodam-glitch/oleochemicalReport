<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Kirim notifikasi "laporan diterima" ke teknisi pelapor.
 *
 * Dijalankan lewat queue setelah laporan web tersimpan agar request form tidak
 * tertahan oleh pengiriman Telegram.
 */
class KirimNotifikasiLaporanDiterima implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    /**
     * @param array<int, int> $reportIds ID laporan induk
     */
    public function __construct(public array $reportIds)
    {
    }

    public function handle(TelegramService $telegram): void
    {
        $laporan = Report::with(['technician', 'asset'])
            ->whereIn('id', $this->reportIds)
            ->get();

        foreach ($laporan as $report) {
            $telegramId = $report->technician?->telegram_id;

            if ($telegramId === null || $telegramId === '') {
                continue;
            }

            $status = $report->status === 'completed' ? 'Selesai' : 'Lanjut';
            $lokasi = $report->asset?->tech_ident_no
                ?: ($report->equipment_tag ?: '-');

            $pesan = "<b>Laporan diterima</b>\n"
                . '<b>Kode</b>: ' . e($report->report_code ?? '-') . "\n"
                . '<b>Tanggal</b>: ' . e($report->report_date?->format('d/m/Y') ?? '-') . "\n"
                . '<b>Lokasi</b>: ' . e($lokasi) . "\n"
                . '<b>Deskripsi</b>: ' . e(Str::limit((string) $report->work_description, 400)) . "\n"
                . '<b>Durasi</b>: ' . (int) $report->work_duration_minutes . " menit\n"
                . '<b>Status</b>: ' . e($status) . "\n\n"
                . 'Detail: ' . route('reports.show', $report);

            $telegram->sendMessage($telegramId, $pesan, [
                'disable_web_page_preview' => true,
            ]);
        }
    }
}
