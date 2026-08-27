<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Membuat tabel it_ping_logs sebagai riwayat hasil ping per perangkat IT.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('it_ping_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('it_asset_id')->constrained('it_assets')->cascadeOnDelete();
            $table->string('status');
            $table->integer('latency_ms')->nullable();
            $table->timestamp('checked_at');
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
        Schema::dropIfExists('it_ping_logs');
    }
};
