<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai teknisi yang ditunjuk administrator sebagai tool keeper.
 *
 * Keeper bertugas menginput peminjaman lewat /pinjam. Keeper tetap teknisi
 * biasa sehingga tetap boleh meminjam alat untuk dirinya sendiri lewat /pakai.
 */
return new class extends Migration
{
    /**
     * Menambah kolom is_tool_keeper.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->boolean('is_tool_keeper')->default(false)->after('status');
            $table->index('is_tool_keeper');
        });
    }

    /**
     * Menghapus kolom is_tool_keeper.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropIndex(['is_tool_keeper']);
            $table->dropColumn('is_tool_keeper');
        });
    }
};
