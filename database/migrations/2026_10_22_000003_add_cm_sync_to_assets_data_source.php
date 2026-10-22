<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambahkan nilai cm_sync pada kolom assets.data_source.
 *
 * MySQL memakai ENUM; SQLite menyimpan enum sebagai varchar sehingga tidak
 * memerlukan perubahan. Migrasi ini no-op di SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `assets` MODIFY `data_source` ENUM('import_excel','manual','it_sync','panel_sync','cm_sync') NOT NULL DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `assets` MODIFY `data_source` ENUM('import_excel','manual','it_sync','panel_sync') NOT NULL DEFAULT 'manual'");
        }
    }
};
