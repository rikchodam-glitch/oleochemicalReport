<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel kasus Service IT.
 *
 * Satu baris mewakili satu kasus service untuk satu perangkat IT:
 *   - kasus dari laporan dibuka oleh laporan berstatus 'continue'
 *     (report_id) dan ditutup oleh laporan berstatus 'completed'
 *     (closed_by_report_id) pada tanggal laporan masing-masing;
 *   - kasus manual dibuat langsung dari halaman Service IT dan tanggalnya
 *     diisi pengguna.
 *
 * Status yang dipakai: proses (belum selesai), selesai (tuntas), dan rusak
 * (perangkat dinyatakan rusak, wajib punya tanggal selesai).
 */
return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('it_service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('it_asset_id')
                ->constrained('it_assets')
                ->cascadeOnDelete();
            $table->foreignId('report_id')
                ->nullable()
                ->constrained('reports')
                ->nullOnDelete();
            $table->foreignId('closed_by_report_id')
                ->nullable()
                ->constrained('reports')
                ->nullOnDelete();
            $table->enum('source', ['report', 'manual'])->default('manual');
            $table->date('entry_date');
            $table->date('finish_date')->nullable();
            $table->enum('status', ['proses', 'selesai', 'rusak'])->default('proses');
            $table->text('note')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['it_asset_id', 'finish_date']);
            $table->index('status');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('it_service_records');
    }
};
