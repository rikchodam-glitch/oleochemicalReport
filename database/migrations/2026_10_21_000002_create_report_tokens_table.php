<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token tautan form laporan dari perintah Telegram /laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('telegram_id', 30)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_tokens');
    }
};
