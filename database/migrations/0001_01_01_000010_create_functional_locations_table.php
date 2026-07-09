<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('functional_locations', function (Blueprint $table) {
            $table->id();

            // Kode penuh hierarki SAP PM, mis. "EPE-PROD-BD01-6153" — tidak boleh diubah setelah dibuat
            $table->string('code', 100)->unique();

            // Segmen terakhir saja, mis. "BD01" atau "6153"
            $table->string('segment', 50);

            $table->string('name');

            // 0=site | 1=dept | 2=area | 3=section
            $table->unsignedTinyInteger('level');

            // Referensi ke node induk (self-referential)
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('functional_locations')
                ->nullOnDelete();

            // FK opsional ke master data lama — hanya diisi sesuai level
            $table->foreignId('company_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('area_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('sub_area_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Level harus antara 0–3
            $table->index('level');
            $table->index('parent_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('functional_locations');
    }
};
