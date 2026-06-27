<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id', 50);
            $table->string('telegram_username')->nullable();
            $table->string('name');
            $table->string('nik', 30)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bot_unknown_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->string('keyword_mentioned');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_unknown_assets');
        Schema::dropIfExists('bot_registrations');
    }
};
