<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

// Pembersih otomatis snapshot CCTV lama supaya pemakaian disk tidak tumbuh
// terus (retensi hari diatur di config/storage.php, default 30 hari).
if ((int) config('storage.snapshot_retention_days', 30) > 0) {
    Schedule::command('storage:clean --force')
        ->dailyAt('02:30')
        ->withoutOverlapping();
}


