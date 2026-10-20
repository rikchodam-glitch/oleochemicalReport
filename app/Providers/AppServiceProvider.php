<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\FunctionalLocation;
use App\Models\SubArea;
use App\Observers\AreaObserver;
use App\Observers\FunctionalLocationObserver;
use App\Observers\SubAreaObserver;
use App\Services\EquipmentMentionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Satu instance per request/command: daftar tag equipment (ribuan baris)
        // cukup dibaca sekali walau halaman CM memakai service ini beberapa kali.
        $this->app->singleton(EquipmentMentionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FunctionalLocation::observe(FunctionalLocationObserver::class);
        Area::observe(AreaObserver::class);
        SubArea::observe(SubAreaObserver::class);

        $this->registerSqliteHelpers();
    }

    /**
     * Daftarkan fungsi SQL portabel (YEAR/MONTH) untuk koneksi SQLite.
     *
     * Modul Condition Monitoring (CmController) memakai sintaks fungsi
     * MySQL `YEAR()` dan `MONTH()` di raw query. SQLite tidak punya fungsi
     * ini secara bawaan, sehingga halaman CM error (500) saat database
     * memakai SQLite. Fungsi di bawah mendaftarkan padanan berbasis
     * strftime agar query tetap jalan tanpa mengubah controller.
     *
     * @return void
     */
    private function registerSqliteHelpers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = DB::connection()->getPdo();

        $pdo->sqliteCreateFunction('YEAR', fn (?string $date) => $date === null ? null : (int) substr((string) $date, 0, 4));
        $pdo->sqliteCreateFunction('MONTH', fn (?string $date) => $date === null ? null : (int) substr((string) $date, 5, 2));

        // GREATEST: didaftarkan manual agar query MySQL (COALESCE+GREATEST)
        // yang dipakai CmController tetap berjalan di SQLite.
        $pdo->sqliteCreateFunction('GREATEST', function (...$values) {
            $numbers = array_map(fn ($v) => is_numeric($v) ? (float) $v : 0.0, $values);
            return empty($numbers) ? null : max($numbers);
        }, -1);

        // REGEXP: dipakai EquipmentMentionService untuk mencocokkan penyebutan
        // kode equipment pada deskripsi laporan. SQLite tidak punya operator
        // REGEXP bawaan; daftarkan padanan berbasis PCRE (dengan delimiter '#')
        // supaya query yang sama tetap jalan saat test memakai SQLite.
        $pdo->sqliteCreateFunction('regexp', function ($pattern, $value) {
            if ($value === null || $pattern === null) {
                return 0;
            }

            return preg_match('#' . str_replace('#', '\#', (string) $pattern) . '#i', (string) $value) ? 1 : 0;
        }, 2);
    }
}
