<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status 'draft' sudah tidak dipakai lagi di alur laporan: laporan kini
 * disimpan sebagai 'completed' (Done) atau 'continue' (belum selesai).
 *
 * Migration ini memindahkan seluruh laporan lama yang masih 'draft' menjadi
 * 'completed' beserta completed_at-nya, lalu mengubah nilai bawaan kolom status
 * menjadi 'completed' supaya tidak ada draft baru yang muncul tanpa sengaja.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Pindahkan laporan draft menjadi completed, per potongan 200 baris.
        DB::table('reports')
            ->where('status', 'draft')
            ->orderBy('id')
            ->chunkById(200, function ($laporan): void {
                foreach ($laporan as $baris) {
                    // Waktu selesai diambil dari submitted_at; bila kosong pakai
                    // tanggal laporan + jam selesai, lalu updated_at/created_at.
                    $waktu = $baris->submitted_at
                        ?? ($baris->report_date !== null && $baris->end_time !== null
                            ? $baris->report_date . ' ' . $baris->end_time . ':00'
                            : null)
                        ?? $baris->updated_at
                        ?? $baris->created_at;

                    DB::table('reports')->where('id', $baris->id)->update([
                        'status'       => 'completed',
                        'completed_at' => $waktu,
                        'updated_at'   => now(),
                    ]);
                }
            });

        // 2. Ubah nilai bawaan kolom status (khusus MySQL). Driver lain
        //    dilewati karena perubahan enum tidak portabel di test SQLite.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->enum('status', ['needs_review', 'completed', 'continue'])
                ->default('completed')
                ->change();
        });
    }

    public function down(): void
    {
        // Tidak dikembalikan: status draft sudah tidak dipakai lagi.
    }
};
