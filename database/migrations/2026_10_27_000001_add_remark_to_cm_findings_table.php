<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remark finding dipisahkan dari deskripsi.
 *
 * Sebelumnya kolom Remark dari sheet Finding CM digabung ke kolom deskripsi
 * sebagai teks "Remark: ...", sehingga tidak bisa dibaca sebagai catatan
 * tersendiri dan ikut tertimpa saat sinkronisasi. Kolom baru ini menyimpan
 * catatan manual/sinkronisasi secara terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cm_findings', function (Blueprint $table) {
            $table->text('remark')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('cm_findings', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
