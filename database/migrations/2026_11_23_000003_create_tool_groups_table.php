<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat master kelompok alat (mis. Bor Batrai).
 *
 * Satu kelompok menaungi beberapa unit fisik dengan kode berbeda
 * (BRBT001, BRBT002). Kelompok juga menentukan jenis alat.
 */
return new class extends Migration
{
    /**
     * Membuat tabel tool_groups.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tool_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_type_id')->nullable()->constrained('tool_types')->nullOnDelete();
            $table->string('name', 150)->unique();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('tool_type_id');
        });
    }

    /**
     * Menghapus tabel tool_groups.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_groups');
    }
};
