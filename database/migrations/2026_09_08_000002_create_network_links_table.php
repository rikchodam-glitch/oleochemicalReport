<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Membuat tabel network_links untuk menyimpan jalur fisik (kabel/wireless)
     * yang menghubungkan dua perangkat IT pada tabel it_assets. Digunakan
     * oleh fitur Network Map untuk menggambar jalur di atas peta satelit.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('network_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_asset_id')->constrained('it_assets')->cascadeOnDelete();
            $table->foreignId('to_asset_id')->constrained('it_assets')->cascadeOnDelete();
            $table->string('cable_type', 30)->default('utp');
            $table->json('path_points')->nullable();
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_asset_id', 'to_asset_id']);
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('network_links');
    }
};
