<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Menyambungkan modul IT Assets dengan tabel assets (asset industri):
     *
     * 1. it_assets.funcloc_id — lokasi perangkat IT memakai Functional Location
     *    yang sama dengan asset industri (PT -> Departemen -> Area -> Section).
     * 2. assets.it_asset_id  — jembatan ke baris sumber di it_assets. Baris
     *    assets yang berasal dari IT Assets ditandai data_source = 'it_sync'.
     *    ON DELETE CASCADE: menghapus perangkat IT ikut menghapus cerminnya.
     * 3. ENUM assets.data_source ditambah nilai 'it_sync'.
     * 4. ENUM assets.status ditambah nilai 'spare' supaya perangkat IT
     *    cadangan (status spare di it_assets) tidak kehilangan maknanya.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('it_assets', function (Blueprint $table) {
            $table->foreignId('funcloc_id')
                ->nullable()
                ->after('location')
                ->constrained('functional_locations')
                ->nullOnDelete();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('it_asset_id')
                ->nullable()
                ->after('funcloc_id')
                ->constrained('it_assets')
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE assets MODIFY data_source ENUM('import_excel', 'manual', 'it_sync') NOT NULL DEFAULT 'manual'");
        DB::statement("ALTER TABLE assets MODIFY status ENUM('active', 'inactive', 'needs_review', 'spare') NOT NULL DEFAULT 'active'");
    }

    /**
     * Batalkan migration.
     *
     * Baris cermin hasil sinkronisasi (data_source = 'it_sync') dihapus lebih
     * dulu supaya nilai ENUM data_source bisa dikembalikan tanpa sisa data yang
     * tidak dikenal definisi kolomnya.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('assets')->where('data_source', 'it_sync')->delete();

        DB::statement("ALTER TABLE assets MODIFY data_source ENUM('import_excel', 'manual') NOT NULL DEFAULT 'manual'");
        DB::statement("ALTER TABLE assets MODIFY status ENUM('active', 'inactive', 'needs_review') NOT NULL DEFAULT 'active'");

        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['it_asset_id']);
            $table->dropColumn('it_asset_id');
        });

        Schema::table('it_assets', function (Blueprint $table) {
            $table->dropForeign(['funcloc_id']);
            $table->dropColumn('funcloc_id');
        });
    }
};
