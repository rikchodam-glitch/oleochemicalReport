<?php

namespace App\Console\Commands;

use App\Models\AiProvider;
use Illuminate\Console\Command;

class AiResetQuotaCommand extends Command
{
    protected $signature = 'ai:reset-quota
                            {--monthly : Sekaligus reset pemakaian token bulanan}
                            {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Reset pemakaian token harian provider AI (dan bulanan bila diminta) serta pulihkan status provider yang exhausted';

    /**
     * Reset penghitung kuota provider AI.
     *
     * Sebelumnya penghitung harian (tokens_used_today) hanya bisa direset manual
     * lewat tombol panel, sehingga begitu melewati daily_token_limit provider
     * tidak pernah dipakai lagi (getBestProvider() melewatinya) - AI berhenti
     * bekerja sampai ada yang mengklik reset. Perintah ini dijadwalkan harian
     * lewat routes/console.php, dan reset bulanan dijalankan otomatis pada
     * tanggal 1 (atau kapan pun dengan opsi --monthly).
     *
     * @return int
     */
    public function handle(): int
    {
        $resetBulanan = $this->option('monthly') || (int) now()->day === 1;
        $dryRun       = (bool) $this->option('dry-run');

        $providers = AiProvider::orderBy('priority')->get();

        if ($providers->isEmpty()) {
            $this->info('Tidak ada provider AI yang perlu direset.');

            return self::SUCCESS;
        }

        foreach ($providers as $provider) {
            $this->line(sprintf(
                'Provider #%d %s: harian %s -> 0%s%s',
                $provider->id,
                $provider->name,
                number_format((int) $provider->tokens_used_today),
                $resetBulanan ? ', bulanan ' . number_format((int) $provider->tokens_used_month) . ' -> 0' : '',
                $provider->status === 'exhausted' ? ', status exhausted -> healthy' : ''
            ));

            if ($dryRun) {
                continue;
            }

            $data = [
                'tokens_used_today' => 0,
                'request_count_24h' => 0,
            ];

            if ($resetBulanan) {
                $data['tokens_used_month'] = 0;
            }

            // Provider yang ditandai exhausted karena kuota habis dipulihkan,
            // sedangkan status error (mis. kredensial/model bermasalah) dibiarkan
            // agar tetap terlihat dan diperbaiki.
            if ($provider->status === 'exhausted') {
                $data['status'] = 'healthy';
            }

            $provider->update($data);
        }

        $this->info($dryRun
            ? 'Mode dry-run: tidak ada perubahan yang disimpan.'
            : 'Reset kuota provider AI selesai' . ($resetBulanan ? ' (harian + bulanan).' : ' (harian).'));

        return self::SUCCESS;
    }
}
