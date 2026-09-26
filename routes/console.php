<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset penghitung kuota provider AI. Tanpa ini, tokens_used_today hanya bisa
// direset manual dari panel sehingga provider berhenti dipakai begitu kuota
// harian terlampaui (getBestProvider() melewatinya). Reset bulanan otomatis
// ikut berjalan saat tanggal 1.
Schedule::command('ai:reset-quota')
    ->dailyAt('00:05')
    ->withoutOverlapping();

// Ping otomatis perangkat IT aktif (interval menit diatur di config/it.php).
if ((bool) config('it.ping.enabled', true)) {
    $pingInterval = min(59, max(1, (int) config('it.ping.interval_minutes', 5)));

    Schedule::command('it:ping')
        ->cron('*/' . $pingInterval . ' * * * *')
        ->withoutOverlapping();
}

// Snapshot berkala CCTV/NVR (interval menit diatur di config/it.php).
if ((bool) config('it.snapshot.enabled', true)) {
    $snapshotInterval = (int) config('it.snapshot.interval_minutes', 60);
    $snapshotCron = $snapshotInterval >= 60
        ? '0 * * * *'
        : '*/' . min(59, max(1, $snapshotInterval)) . ' * * * *';

    Schedule::command('it:snapshot')
        ->cron($snapshotCron)
        ->withoutOverlapping();
}

// Jadwal shift teknisi selalu tersedia otomatis dari pola rotasi 20 hari
// (config cm.shift_pattern), sehingga jadwal bulanan tidak perlu diupload lagi.
// Command ini hanya menulis tanggal yang belum ada (idempoten).
Schedule::command('shifts:generate --execute')
    ->dailyAt('00:20')
    ->withoutOverlapping();

// Pembersih otomatis snapshot CCTV lama supaya pemakaian disk tidak tumbuh
// terus (retensi hari diatur di config/storage.php, default 30 hari).
if ((int) config('storage.snapshot_retention_days', 30) > 0) {
    Schedule::command('storage:clean --force')
        ->dailyAt('02:30')
        ->withoutOverlapping();
}

// Penarik data sensor ruang panel (suhu/kelembapan) tiap menit. Server yang
// menarik data dari perangkat, sehingga tidak ada port masuk yang perlu
// dibuka di firewall. Interval tarik saat halaman sedang dibuka diatur di
// config/panel.php bagian pull.cache_detik.
if ((bool) config('panel.pull.enabled', true)) {
    Schedule::command('panel:tarik')
        ->everyMinute()
        ->withoutOverlapping();
}


