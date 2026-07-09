<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom kolaborasi dan flag edit manual ke tabel reports.
 *
 * Kolom baru:
 * - creator_id      : ID teknisi yang menginput laporan (bisa berbeda dari pemilik laporan)
 * - collaborator_of : ID laporan induk jika laporan ini adalah salinan kolaborasi
 * - is_manually_edited : flag bahwa laporan telah diedit manual oleh admin
 */
return new class extends Migration
{
    /**
     * Jalankan migration.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // ID teknisi yang membuat laporan ini (pengirim asli di wizard bot).
            // Null berarti teknisi membuat laporan untuk dirinya sendiri.
            $table->foreignId('creator_id')
                ->nullable()
                ->after('technician_id')
                ->constrained('technicians')
                ->nullOnDelete();

            // Menunjuk ke laporan induk jika laporan ini adalah salinan untuk kolaborator.
            // Null berarti laporan ini bukan salinan kolaborasi.
            $table->foreignId('collaborator_of')
                ->nullable()
                ->after('creator_id')
                ->constrained('reports')
                ->cascadeOnDelete();

            // Flag bahwa laporan telah diubah secara manual oleh admin melalui web.
            // Jika true, kolom AI confidence tidak ditampilkan di UI.
            $table->boolean('is_manually_edited')
                ->default(false)
                ->after('collaborator_of');
        });
    }

    /**
     * Batalkan migration.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['creator_id']);
            $table->dropForeign(['collaborator_of']);
            $table->dropColumn(['creator_id', 'collaborator_of', 'is_manually_edited']);
        });
    }
};
