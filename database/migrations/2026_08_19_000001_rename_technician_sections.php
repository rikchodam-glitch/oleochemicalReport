<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sesuaikan nilai kolom section di tabel technicians dengan daftar section baru.
 *
 * Pemetaan:
 * - electrical    -> ei        (Electrical + Instrumentasi digabung menjadi EI)
 * - instrumentasi -> ei        (digabung ke EI)
 * - welding       -> fabrikasi (Welding diganti Fabrikasi)
 */
return new class extends Migration
{
    /**
     * Jalankan migrasi data.
     */
    public function up(): void
    {
        DB::table('technicians')
            ->whereIn('section', ['electrical', 'instrumentasi'])
            ->update(['section' => 'ei']);

        DB::table('technicians')
            ->where('section', 'welding')
            ->update(['section' => 'fabrikasi']);
    }

    /**
     * Batalkan migrasi data.
     *
     * Catatan: 'electrical' dan 'instrumentasi' sudah digabung ke 'ei',
     * sehingga rollback tidak bisa mengembalikan nilai asli masing-masing.
     * Seluruh 'ei' dikembalikan ke 'electrical'.
     */
    public function down(): void
    {
        DB::table('technicians')
            ->where('section', 'ei')
            ->update(['section' => 'electrical']);

        DB::table('technicians')
            ->where('section', 'fabrikasi')
            ->update(['section' => 'welding']);
    }
};
