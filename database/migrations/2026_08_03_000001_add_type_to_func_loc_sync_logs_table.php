<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom `type` ke func_loc_sync_logs agar tabel log yang sama
     * bisa dipakai mencatat aksi auto-sync maupun penautan manual/bulk dari
     * halaman rekonsiliasi. Default 'auto_sync' agar seluruh baris lama
     * (dibuat sebelum kolom ini ada) tetap valid tanpa perlu backfill.
     */
    public function up(): void
    {
        Schema::table('func_loc_sync_logs', function (Blueprint $table) {
            $table->string('type')->default('auto_sync')->after('executed_by');
        });
    }

    public function down(): void
    {
        Schema::table('func_loc_sync_logs', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
