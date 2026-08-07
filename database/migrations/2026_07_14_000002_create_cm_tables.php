<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cm_equipment', function (Blueprint $table) {
            $table->id();
            $table->string('equipment_tag', 50)->unique();
            $table->string('pt_location', 100);
            $table->string('plant', 50);
            $table->string('tipe_lubrikasi', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('cm_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cm_equipment_id')->constrained('cm_equipment')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('status', 30)->default('start')->comment('start, no_vib, dsb');
            // Motor
            $table->decimal('ndev_motor', 10, 2)->nullable();
            $table->decimal('ndeh_motor', 10, 2)->nullable();
            $table->decimal('ndea_motor', 10, 2)->nullable();
            $table->decimal('temp_de_motor', 10, 2)->nullable();
            $table->decimal('dev_motor', 10, 2)->nullable();
            $table->decimal('deh_motor', 10, 2)->nullable();
            $table->decimal('dea_motor', 10, 2)->nullable();
            // Pompa
            $table->decimal('ndev_pompa', 10, 2)->nullable();
            $table->decimal('ndeh_pompa', 10, 2)->nullable();
            $table->decimal('ndea_pompa', 10, 2)->nullable();
            $table->decimal('temp_de_pompa', 10, 2)->nullable();
            $table->decimal('dev_pompa', 10, 2)->nullable();
            $table->decimal('deh_pompa', 10, 2)->nullable();
            $table->decimal('dea_pompa', 10, 2)->nullable();
            // Kondisi
            $table->enum('kondisi', ['good', 'alarm', 'danger', 'visual_bad'])->default('good');
            $table->timestamps();

            $table->index(['cm_equipment_id', 'tanggal']);
        });

        Schema::create('cm_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cm_equipment_id')->constrained('cm_equipment')->cascadeOnDelete();
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium');
            $table->string('kategori', 50);
            $table->text('deskripsi');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->string('pic', 100)->nullable();
            $table->date('tanggal_temuan');
            $table->string('foto_url')->nullable();
            $table->integer('hari_open')->default(0)->comment('cached computed days open');
            $table->timestamps();

            $table->index(['cm_equipment_id', 'status']);
            $table->index('tanggal_temuan');
        });

        Schema::create('cm_monthly_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cm_equipment_id')->constrained('cm_equipment')->cascadeOnDelete();
            $table->year('tahun');
            $table->tinyInteger('bulan')->comment('1-12');
            $table->enum('status', ['sudah', 'belum'])->default('belum');
            $table->timestamps();

            $table->unique(['cm_equipment_id', 'tahun', 'bulan']);
            $table->index(['tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cm_monthly_tracking');
        Schema::dropIfExists('cm_findings');
        Schema::dropIfExists('cm_readings');
        Schema::dropIfExists('cm_equipment');
    }
};
