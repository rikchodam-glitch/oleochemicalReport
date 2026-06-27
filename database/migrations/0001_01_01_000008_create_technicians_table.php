<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id', 50)->nullable()->unique();
            $table->string('telegram_username')->nullable();
            $table->string('name');
            $table->string('nik', 30)->nullable()->unique();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->json('area_ids')->nullable();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technicians');
    }
};
