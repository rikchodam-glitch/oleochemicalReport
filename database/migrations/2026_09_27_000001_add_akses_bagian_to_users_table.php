<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahan kolom untuk manajemen user berbasis Bagian:
 * - username      : nama login akun (diisi NIK teknisi). Kolom email tetap ada
 *                   dan tetap bisa dipakai untuk login akun lama.
 * - technician_id : kaitan akun ke data teknisi (satu teknisi satu akun).
 * - role          : diubah dari enum('admin','supervisor') menjadi varchar(50)
 *                   supaya bisa berisi kode Bagian (mekanik, ei, it, sipil,
 *                   fabrikasi, general, cm_pm, lainnya) selain admin/supervisor.
 *
 * Nilai bawaan role tetap 'admin' agar perilaku akun yang sudah ada (termasuk
 * akun yang dibuat factory pada pengujian) tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('admin')->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->unique()->after('email');
            $table->foreignId('technician_id')->nullable()->unique()->after('username')
                ->constrained('technicians')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technician_id');
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'supervisor'])->default('admin')->change();
        });
    }
};
