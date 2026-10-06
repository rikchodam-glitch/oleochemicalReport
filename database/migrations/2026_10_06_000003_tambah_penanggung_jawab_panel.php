<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Menambahkan penanggung jawab (PIC) ruang panel dan catatan pengiriman
     * pengingat hariannya:
     *
     * 1. panel_penanggung_jawab — daftar teknisi yang bertanggung jawab atas
     *    satu ruangan panel. Boleh lebih dari satu teknisi per ruangan dan
     *    satu teknisi boleh memegang beberapa ruangan. Kunci unik pasangan
     *    mencegah teknisi yang sama terdaftar dua kali pada ruangan yang sama.
     * 2. panel_notifikasi_terkirim — satu baris per teknisi per tanggal,
     *    dipakai penanda "sudah dikirimi pengingat hari ini" supaya pengingat
     *    harian tidak terkirim berulang saat perintah penjadwal berjalan tiap
     *    menit.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('panel_penanggung_jawab', function (Blueprint $table) {
            $table->id();

            $table->foreignId('panel_ruangan_id')
                ->constrained('panel_ruangan')
                ->cascadeOnDelete();

            $table->foreignId('technician_id')
                ->constrained('technicians')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['panel_ruangan_id', 'technician_id'], 'panel_penanggung_jawab_unik');
        });

        Schema::create('panel_notifikasi_terkirim', function (Blueprint $table) {
            $table->id();

            $table->foreignId('technician_id')
                ->constrained('technicians')
                ->cascadeOnDelete();

            // Tanggal pengiriman (satu pengingat per teknisi per hari).
            $table->date('tanggal');

            // Jam mulai kerja yang memicu pengiriman, format HH:MM.
            $table->string('jam_kerja', 5)->nullable();

            // Jumlah ruangan yang dilaporkan pada pesan itu.
            $table->unsignedSmallInteger('jumlah_ruangan')->default(0);

            $table->timestamps();

            $table->unique(['technician_id', 'tanggal'], 'panel_notifikasi_terkirim_unik');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('panel_notifikasi_terkirim');
        Schema::dropIfExists('panel_penanggung_jawab');
    }
};
