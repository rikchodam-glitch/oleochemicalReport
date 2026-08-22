<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambahkan jenis pekerjaan EI, Mekanik, CM/PM, Fabrikasi, dan Sipil.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE reports MODIFY jenis_pekerjaan ENUM('CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil') NULL");
    }

    /**
     * Kembalikan enum ke nilai sebelum perluasan jenis pekerjaan.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE reports MODIFY jenis_pekerjaan ENUM('CM', 'dCM', 'PM') NULL");
    }
};
