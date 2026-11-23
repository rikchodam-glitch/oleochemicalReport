<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat master jenis alat (kategori bebas, mis. Bor, Kunci, Alat Ukur).
 */
return new class extends Migration
{
    /**
     * Membuat tabel tool_types.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tool_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    /**
     * Menghapus tabel tool_types.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_types');
    }
};
