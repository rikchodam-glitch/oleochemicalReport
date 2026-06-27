<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->text('work_description');
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('report_type', ['equipment_repair', 'area_work', 'general'])->default('general');
            $table->boolean('ai_analyzed')->default(false);
            $table->float('ai_confidence')->nullable();
            $table->json('ai_suggestion_json')->nullable();
            $table->enum('status', ['draft', 'needs_review', 'completed'])->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->string('telegram_message_id', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('report_ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->enum('suggestion_type', ['area', 'equipment']);
            $table->foreignId('suggested_area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('suggested_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->float('confidence')->nullable();
            $table->text('reasoning')->nullable();
            $table->boolean('accepted')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_ai_suggestions');
        Schema::dropIfExists('reports');
    }
};
