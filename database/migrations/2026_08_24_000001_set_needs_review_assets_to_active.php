<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kembalikan status asset yang terlanjur needs_review menjadi active.
 *
 * Akar masalah: import ZPM Line 1 dijalankan ulang dengan aksi duplicate
 * "keep_flag", sehingga seluruh asset (2.639 baris) ditandai needs_review.
 * Asset tersebut sebenarnya valid, hanya statusnya yang salah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assets')
            ->where('status', 'needs_review')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        // Tidak ada rollback data yang aman karena tidak diketahui
        // asset mana yang sebelumnya benar-benar berstatus needs_review.
    }
};
