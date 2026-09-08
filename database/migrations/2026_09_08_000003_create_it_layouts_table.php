<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Membuat tabel it_layouts sebagai penyimpanan denah/layout perangkat IT.
     * Setiap layout punya background berupa file PDF (denah) yang di-upload
     * admin, lalu perangkat IT ditandai posisinya di atasnya. File background
     * bisa berupa PDF (dirender lewat PDF.js di browser) atau gambar.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('it_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_type', 20)->default('pdf');
            $table->unsignedInteger('page')->default(1);
            $table->float('width')->nullable();
            $table->float('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('it_layouts');
    }
};
