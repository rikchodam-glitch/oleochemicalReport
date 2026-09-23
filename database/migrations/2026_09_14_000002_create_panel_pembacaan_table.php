<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Jalankan migrasi: tabel pembacaan suhu/kelembapan tiap ruangan panel.
     *
     * Kunci unik (ruangan, sesi boot, id pembacaan perangkat) mencegah data
     * tersimpan dua kali walaupun perangkat yang sama ditarik berulang kali
     * atau kelak ada jalur kirim data dari perangkat (push).
     */
    public function up(): void
    {
        Schema::create('panel_pembacaan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('panel_ruangan_id')
                ->constrained('panel_ruangan')
                ->cascadeOnDelete();

            // Waktu pengukuran. Perangkat tidak punya jam sendiri, jadi waktu
            // dihitung dari selisih uptime perangkat saat data ditarik.
            $table->dateTime('waktu');

            $table->decimal('suhu', 5, 2)->nullable();
            $table->decimal('kelembapan', 5, 2)->nullable();
            $table->boolean('valid')->default(true);

            // Penanda sesi menyala perangkat dan nomor pembacaan di perangkat.
            $table->string('sesi_boot', 12);
            $table->unsignedInteger('id_perangkat');

            // Asal data: pull (ditarik server) atau push (dikirim perangkat).
            $table->string('sumber', 10)->default('pull');

            $table->timestamps();

            $table->unique(
                ['panel_ruangan_id', 'sesi_boot', 'id_perangkat'],
                'panel_pembacaan_unik'
            );
            $table->index(['panel_ruangan_id', 'waktu']);
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('panel_pembacaan');
    }
};
