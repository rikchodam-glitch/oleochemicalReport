<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('provider_type', ['groq', 'ollama', 'openai']);
            $table->text('api_key_encrypted')->nullable();
            $table->string('model');
            $table->string('endpoint_url')->nullable();
            $table->integer('priority')->default(1);
            $table->bigInteger('monthly_token_limit')->default(10000000);
            $table->bigInteger('daily_token_limit')->default(500000);
            $table->bigInteger('tokens_used_today')->default(0);
            $table->bigInteger('tokens_used_month')->default(0);
            $table->integer('request_count_24h')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_health_check')->nullable();
            $table->enum('status', ['healthy', 'exhausted', 'error', 'disabled'])->default('healthy');
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('tokens_used')->default(0);
            $table->string('request_type', 50)->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->enum('status', ['success', 'error'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias_text');
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('source', ['user', 'ai_learned'])->default('ai_learned');
            $table->float('confidence')->nullable();
            $table->integer('usage_count')->default(0);
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_aliases');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_providers');
    }
};
