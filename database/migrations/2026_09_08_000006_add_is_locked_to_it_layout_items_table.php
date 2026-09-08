<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Tambahkan kolom is_locked pada tabel it_layout_items. Saat terkunci,
     * posisi perangkat tidak bisa digeser maupun dihapus dari denah sampai
     * admin membuka kunci (unlock) terlebih dahulu.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('it_layout_items', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('y');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('it_layout_items', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
