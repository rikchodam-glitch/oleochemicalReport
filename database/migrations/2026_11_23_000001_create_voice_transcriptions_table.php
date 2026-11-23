<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel log transkripsi pesan suara Telegram.
 *
 * Audio asli tidak disimpan permanen; tabel ini menyimpan metadata dan hasil
 * transkrip untuk audit serta pengukuran akurasi.
 */
return new class extends Migration
{
    /**
     * Membuat tabel voice_transcriptions.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('voice_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->string('chat_id', 50);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('model', 50);
            $table->string('language', 10)->nullable();
            $table->string('confidence', 20)->nullable();
            $table->text('transcript')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 20)->default('sukses');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('chat_id');
            $table->index('technician_id');
            $table->index('created_at');
        });
    }

    /**
     * Menghapus tabel voice_transcriptions.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_transcriptions');
    }
};
