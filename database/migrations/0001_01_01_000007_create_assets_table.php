<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('equipment_no', 50)->nullable()->unique();
            $table->string('description')->nullable();
            $table->string('tech_ident_no', 100)->nullable();
            $table->string('object_type', 30)->nullable();
            $table->string('functional_loc', 100)->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manufacturer')->nullable();
            $table->string('model_number')->nullable();
            $table->string('construct_year', 10)->nullable();
            $table->enum('status', ['active', 'inactive', 'needs_review'])->default('active');
            $table->boolean('has_equipment_no')->default(true);
            $table->enum('data_source', ['import_excel', 'manual'])->default('manual');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('total_rows')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('no_equip_no_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->enum('action_taken', ['replace', 'keep_flag', 'cancel', 'skip'])->nullable();
            $table->json('detail_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_import_logs');
        Schema::dropIfExists('assets');
    }
};
