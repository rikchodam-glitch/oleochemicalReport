<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Sinkronkan daftar command dan tombol menu bot Telegram.
 *
 * Jalankan sekali setelah deploy atau setiap daftar command berubah:
 *   php artisan telegram:set-commands
 */
class TelegramSetCommands extends Command
{
    protected $signature = 'telegram:set-commands';

    protected $description = 'Sinkronkan daftar command bot Telegram ke menu';

    public function handle(TelegramService $telegram): int
    {
        $hasil = $telegram->sinkronkanMenuBot();

        if (! $hasil['commands']) {
            $this->error('Gagal mendaftarkan command. Periksa TELEGRAM_BOT_TOKEN dan log.');

            return self::FAILURE;
        }

        $this->info('Command bot berhasil didaftarkan.');

        if (! $hasil['menu']) {
            $this->warn('Command terdaftar, tetapi menu button default gagal diaktifkan.');
        }

        return self::SUCCESS;
    }
}
