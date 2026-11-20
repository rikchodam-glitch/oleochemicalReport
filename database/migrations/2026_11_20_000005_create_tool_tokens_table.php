<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel token form Management Tools.
 *
 * Token dipakai untuk membuka form pinjam/kembali tanpa login. Yang disimpan
 * hanya hash SHA-256; token asli hanya ada di tautan Telegram. Token keeper
 * boleh dipakai beberapa transaksi sampai kedaluwarsa, token teknisi sekali
 * pakai (used_at diisi setelah transaksi berhasil).
 */
return new class extends Migration
{
    /**
     * Membuat tabel tool_tokens.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tool_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 30);
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('prefill_tool_id')->nullable()->constrained('tools')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('purpose');
            $table->index('expires_at');
        });
    }

    /**
     * Menghapus tabel tool_tokens.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_tokens');
    }
};
