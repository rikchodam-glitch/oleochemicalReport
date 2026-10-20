<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pengajuan cuti teknisi dan token tautan form cuti dari Telegram.
 *
 * Alur: teknisi mengirim /cuti (opsional dengan alasan) ke bot, bot membuat
 * token sekali pakai, teknisi membuka form tanpa login, lalu pengajuan
 * disetujui/ditolak admin. Cuti yang disetujui mengurangi target man hour.
 */
return new class extends Migration
{
    /**
     * Membuat tabel cuti dan token.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('cuti_teknisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('jenis', 30)->default('tahunan');
            $table->text('alasan')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('diajukan_via', 20)->default('telegram');
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_at')->nullable();
            $table->text('catatan_approver')->nullable();
            $table->timestamps();

            $table->index(['technician_id', 'tanggal_mulai', 'tanggal_selesai'], 'cuti_teknisi_rentang_idx');
            $table->index(['status', 'tanggal_mulai'], 'cuti_status_tanggal_idx');
        });

        Schema::create('cuti_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('telegram_id', 30)->nullable();
            $table->text('alasan')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    /**
     * Membuang tabel token dan cuti.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('cuti_tokens');
        Schema::dropIfExists('cuti_teknisis');
    }
};
