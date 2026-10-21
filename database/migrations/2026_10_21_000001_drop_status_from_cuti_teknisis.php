<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuti tidak lagi memakai status: semua cuti yang dicatat Admin Reguler
 * langsung berlaku dan langsung memengaruhi man hour.
 */
return new class extends Migration
{
    /**
     * Membuang kolom status dan index yang memakainya.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('cuti_teknisis', function (Blueprint $table) {
            $table->dropIndex('cuti_status_tanggal_idx');
            $table->dropColumn('status');
        });
    }

    /**
     * Mengembalikan kolom status untuk rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('cuti_teknisis', function (Blueprint $table) {
            $table->string('status', 20)->default('disetujui')->after('alasan');
            $table->index(['status', 'tanggal_mulai'], 'cuti_status_tanggal_idx');
        });
    }
};
