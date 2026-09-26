<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel jadwal shift teknisi:
 * - shift_schedules : riwayat import file jadwal (satu baris per upload/periode)
 * - technician_shifts : kode shift per teknisi per tanggal (x = OFF, 1/2/3 = shift,
 *                       R = reguler) yang dipakai untuk target man hour harian
 *                       dan deteksi laporan di luar jam shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('periode_mulai')->nullable();
            $table->date('periode_selesai')->nullable();
            $table->string('nama_file');
            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->unsignedInteger('jumlah_terpetakan')->default(0);
            $table->unsignedInteger('jumlah_tidak_cocok')->default(0);
            $table->json('detail_tidak_cocok')->nullable();
            $table->foreignId('diupload_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('technician_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_schedule_id')->nullable()->constrained('shift_schedules')->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->string('nik', 50);
            $table->string('nama');
            $table->string('group_code', 10)->nullable();
            $table->string('section_label', 50)->nullable();
            $table->date('shift_date');
            $table->string('shift_code', 5);
            $table->timestamps();

            // Satu teknisi hanya punya satu kode shift per tanggal.
            $table->unique(['nik', 'shift_date']);
            $table->index(['technician_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_shifts');
        Schema::dropIfExists('shift_schedules');
    }
};
