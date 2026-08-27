<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Membuat tabel it_assets sebagai penyimpanan perangkat IT (PC, Laptop,
     * Printer, CCTV, NVR, Access Door, WiFi, Network, UPS, dll), terpisah
     * dari tabel assets (equipment industri).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('it_assets', function (Blueprint $table) {
            $table->id();
            $table->string('device_type', 30)->index();
            $table->string('tag_name')->nullable();
            $table->string('name')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('plant')->nullable();
            $table->string('department')->nullable();
            $table->string('location')->nullable();
            $table->text('application')->nullable();
            $table->enum('status', ['active', 'inactive', 'needs_review'])->default('active');
            $table->json('spec')->nullable();
            $table->string('last_ping_status')->nullable();
            $table->integer('last_ping_latency')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->string('last_snapshot_path')->nullable();
            $table->timestamp('last_snapshot_at')->nullable();
            $table->enum('data_source', ['import_excel', 'manual'])->default('import_excel');
            $table->timestamp('imported_at')->nullable();
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
        Schema::dropIfExists('it_assets');
    }
};
