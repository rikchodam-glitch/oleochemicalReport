<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Jalankan migrasi: tabel alamat IP hasil generate dari subnet, satu baris
     * per IP. Status available/assigned/reserved menentukan apakah IP bisa
     * dipilih saat mengisi form perangkat IT.
     */
    public function up(): void
    {
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_network_id')->constrained()->cascadeOnDelete();
            $table->string('address', 45);
            $table->string('status', 20)->default('available');
            $table->foreignId('it_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique('address');
            $table->index(['ip_network_id', 'status']);
            $table->index('status');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
