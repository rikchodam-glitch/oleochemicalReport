<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel kelompok kolaborasi (otomasi "ikut andil" pekerjaan).
 *
 * Kelompok kolaborasi berbeda dari kolom technicians.group: kolom itu adalah
 * penanda grup kerja (reguler/grub), sedangkan tabel ini adalah daftar anggota
 * yang BENAR-BENAR ikut andil ketika salah satu anggotanya membuat laporan.
 * Dengan begitu susunan anggota bisa disesuaikan tanpa mengubah data teknisi.
 *
 *   - collaboration_groups        : nama grup, kode unik, aktif/tidak, otomatis/tidak
 *   - collaboration_group_members : daftar anggota (teknisi) per grup
 */
return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('collaboration_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_collaborate')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('collaboration_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collaboration_group_id')
                ->constrained('collaboration_groups')
                ->cascadeOnDelete();
            $table->foreignId('technician_id')
                ->constrained('technicians')
                ->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['collaboration_group_id', 'technician_id'], 'collab_group_member_unique');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('collaboration_group_members');
        Schema::dropIfExists('collaboration_groups');
    }
};
