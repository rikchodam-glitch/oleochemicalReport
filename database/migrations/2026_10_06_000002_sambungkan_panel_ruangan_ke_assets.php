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
     * Menyambungkan modul Monitoring Ruang Panel dengan tabel assets supaya
     * ruang panel menjadi aset penuh yang bisa dipilih pada laporan:
     *
     * 1. panel_ruangan.funcloc_id  — lokasi ruangan memakai Functional Location
     *    yang sama dengan aset industri (PT -> Departemen -> Area -> Section).
     * 2. panel_ruangan.kode_alat   — Kode Alat yang diisi manual pada form
     *    ruangan; dipakai sebagai tech_ident_no baris aset cermin.
     * 3. panel_ruangan.equipment_type — jenis alat yang tampil pada daftar
     *    equipment laporan (bawaan "Panel Monitoring" bila dikosongkan).
     * 4. panel_ruangan.port_tcp    — port layanan JSON TCP sketsa dashboard
     *    per ruangan (menggantikan satu pengaturan global).
     * 5. assets.panel_ruangan_id   — jembatan ke baris sumber di panel_ruangan;
     *    ON DELETE CASCADE, menghapus ruangan ikut menghapus cerminnya.
     * 6. ENUM assets.data_source ditambah nilai 'panel_sync'.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('panel_ruangan', function (Blueprint $table) {
            $table->foreignId('funcloc_id')
                ->nullable()
                ->after('plant')
                ->constrained('functional_locations')
                ->nullOnDelete();
            $table->string('kode_alat', 100)->nullable()->after('funcloc_id');
            $table->string('equipment_type', 100)->nullable()->after('kode_alat');
            $table->unsignedSmallInteger('port_tcp')->nullable()->after('port');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('panel_ruangan_id')
                ->nullable()
                ->after('it_asset_id')
                ->constrained('panel_ruangan')
                ->cascadeOnDelete();
        });

        // Ubah definisi ENUM hanya di MySQL; driver lain (SQLite saat test)
        // tidak mengenal sintaks MODIFY.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE assets MODIFY data_source ENUM('import_excel', 'manual', 'it_sync', 'panel_sync') NOT NULL DEFAULT 'manual'");
        }
    }

    /**
     * Batalkan migration.
     *
     * Baris cermin ruang panel (data_source = 'panel_sync') dihapus lebih dulu
     * supaya nilai ENUM data_source bisa dikembalikan tanpa sisa data yang
     * tidak dikenal definisi kolomnya.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('assets')->where('data_source', 'panel_sync')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE assets MODIFY data_source ENUM('import_excel', 'manual', 'it_sync') NOT NULL DEFAULT 'manual'");
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['panel_ruangan_id']);
            $table->dropColumn('panel_ruangan_id');
        });

        Schema::table('panel_ruangan', function (Blueprint $table) {
            $table->dropForeign(['funcloc_id']);
            $table->dropColumn(['funcloc_id', 'kode_alat', 'equipment_type', 'port_tcp']);
        });
    }
};
