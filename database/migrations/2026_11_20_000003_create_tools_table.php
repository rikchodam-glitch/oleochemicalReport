<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel unit alat.
 *
 * Satu baris adalah SATU unit fisik, bukan satu jenis alat. Contoh: Bor Batrai
 * dua unit disimpan sebagai dua baris dengan kode BRBT001 dan BRBT002.
 */
return new class extends Migration
{
    /**
     * Membuat tabel tools.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_rack_id')->constrained('tool_racks')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('photo_path', 255)->nullable();
            $table->string('status', 20)->default('tersedia');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('status');
        });
    }

    /**
     * Menghapus tabel tools.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
