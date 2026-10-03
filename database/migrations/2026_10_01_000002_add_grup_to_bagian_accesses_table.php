<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses halaman kini bisa dibedakan per Bagian DAN per Group teknisi,
 * supaya akses "mekanik reguler" bisa berbeda dari "mekanik reguler staff".
 *
 * Kolom grup bernilai NULL berarti pengaturan bawaan Bagian: dipakai akun yang
 * tidak terhubung teknisi dan Group yang belum diatur sendiri. Baris lama
 * (yang seluruhnya bermakna "per Bagian") otomatis menjadi pengaturan bawaan
 * itu, jadi tidak ada akses pengguna yang berubah saat migrasi ini berjalan.
 */
return new class extends Migration
{
    /**
     * Tambah kolom grup dan ganti keunikan baris menjadi (bagian, grup).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('bagian_accesses', function (Blueprint $table) {
            $table->string('grup', 50)->nullable()->after('bagian');
        });

        Schema::table('bagian_accesses', function (Blueprint $table) {
            $table->dropUnique(['bagian']);
            $table->unique(['bagian', 'grup'], 'bagian_accesses_bagian_grup_unique');
        });
    }

    /**
     * Kembalikan ke keunikan per Bagian saja dan buang kolom grup.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('bagian_accesses', function (Blueprint $table) {
            $table->dropUnique('bagian_accesses_bagian_grup_unique');
            $table->unique(['bagian']);
        });

        Schema::table('bagian_accesses', function (Blueprint $table) {
            $table->dropColumn('grup');
        });
    }
};
