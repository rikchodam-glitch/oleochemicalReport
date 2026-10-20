<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\ReportCollaborationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast kolaborasi dijalankan lewat queue supaya penyimpanan laporan di
 * wizard/web tidak tertahan oleh pengiriman Telegram ke banyak teknisi.
 */
class KirimBroadcastKolaborasi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Jumlah percobaan bila Telegram sedang gagal. */
    public int $tries = 3;

    /** Jeda antarpercobaan (detik). */
    public int $backoff = 30;

    /**
     * @param int             $reportId    ID laporan induk
     * @param array<int, int>|null $penerimaIds Batasi penerima (null = semua kolaborator)
     */
    public function __construct(
        public int $reportId,
        public ?array $penerimaIds = null
    ) {
    }

    /**
     * Kirim broadcast ke kolaborator laporan.
     *
     * @param  ReportCollaborationNotifier $notifier
     * @return void
     */
    public function handle(ReportCollaborationNotifier $notifier): void
    {
        $report = Report::query()->find($this->reportId);

        if ($report === null) {
            return;
        }

        $notifier->kirim($report, $this->penerimaIds);
    }
}
