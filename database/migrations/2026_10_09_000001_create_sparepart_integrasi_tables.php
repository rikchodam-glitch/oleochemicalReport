<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel modul Integrasi PR / PO Spare Part.
 *
 * Satu kali import menghasilkan SATU batch (tabel sp_batch) yang memuat
 * salinan data mentah dari lima sumber (monitoring, stok gudang G001,
 * EXPORT PR, EXPORT PO, EXPORT MRP) beserta hasil hitungnya (sp_integrasi).
 *
 * Tabel mentah sengaja dipisah dari tabel hasil supaya data sumber tetap
 * bisa diaudit walau hasil hitungnya dihitung ulang.
 */
return new class extends Migration
{
    /**
     * Membuat seluruh tabel modul integrasi.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('sp_batch', function (Blueprint $table) {
            $table->id();
            $table->string('periode', 20);
            $table->string('folder', 500);
            $table->string('status', 20)->default('proses');
            $table->json('ringkasan')->nullable();
            $table->unsignedInteger('jumlah_monitoring')->default(0);
            $table->unsignedInteger('jumlah_g001')->default(0);
            $table->unsignedInteger('jumlah_pr')->default(0);
            $table->unsignedInteger('jumlah_po')->default(0);
            $table->unsignedInteger('jumlah_mrp')->default(0);
            $table->unsignedInteger('jumlah_hasil')->default(0);
            $table->text('pesan')->nullable();
            $table->unsignedBigInteger('oleh')->nullable();
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();

            $table->index('periode');
        });

        Schema::create('sp_monitoring', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sp_batch')->cascadeOnDelete();
            $table->string('kode_material', 40);
            $table->string('deskripsi', 255)->nullable();
            $table->decimal('stok_min', 18, 3)->nullable();
            $table->decimal('stok_tersedia', 18, 3)->nullable();
            $table->boolean('reorder')->default(false);
            $table->string('penanda', 100)->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'kode_material']);
        });

        Schema::create('sp_g001', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sp_batch')->cascadeOnDelete();
            $table->string('kode_material', 40);
            $table->string('sloc', 30)->nullable();
            $table->string('nama_item', 255)->nullable();
            $table->decimal('stok_awal', 18, 3)->default(0);
            $table->decimal('masuk', 18, 3)->default(0);
            $table->decimal('keluar', 18, 3)->default(0);
            $table->decimal('sisa', 18, 3)->default(0);
            $table->string('satuan', 20)->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'kode_material']);
        });

        Schema::create('sp_pr', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sp_batch')->cascadeOnDelete();
            $table->string('kode_material', 40);
            $table->string('no_pr', 40)->nullable();
            $table->string('processing_status', 10)->nullable();
            $table->string('deletion_indicator', 20)->nullable();
            $table->boolean('batal')->default(false);
            $table->decimal('qty_requested', 18, 3)->default(0);
            $table->date('tanggal_kirim')->nullable();
            $table->string('no_po', 40)->nullable();
            $table->string('requisitioner', 100)->nullable();
            $table->string('purchasing_group', 20)->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'kode_material']);
        });

        Schema::create('sp_po', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sp_batch')->cascadeOnDelete();
            $table->string('kode_material', 40);
            $table->string('no_po', 40);
            $table->date('tanggal_dokumen')->nullable();
            $table->decimal('sisa_kirim', 18, 3)->nullable();
            $table->string('status', 10)->default('CLOSED');
            $table->string('vendor', 255)->nullable();
            $table->string('our_reference', 100)->nullable();
            $table->string('plant', 20)->nullable();
            $table->decimal('order_qty', 18, 3)->default(0);
            $table->timestamps();

            $table->index(['batch_id', 'kode_material']);
            $table->index(['batch_id', 'no_po']);
        });

        Schema::create('sp_mrp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sp_batch')->cascadeOnDelete();
            $table->string('kode_material', 40);
            $table->decimal('plant_stock', 18, 3)->default(0);
            $table->decimal('safety_stock', 18, 3)->default(0);
            $table->decimal('reorder_point', 18, 3)->default(0);
            $table->timestamps();

            $table->index(['batch_id', 'kode_material']);
        });

        Schema::create('sp_integrasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sp_batch')->cascadeOnDelete();
            $table->string('kode_material', 40);
            $table->string('deskripsi', 255)->nullable();
            $table->decimal('stok_min', 18, 3)->nullable();
            $table->decimal('stok_tersedia', 18, 3)->nullable();
            $table->boolean('reorder')->default(false);
            $table->string('penanda', 100)->nullable();

            // Hasil pemilihan PR/PO dan aturan AKSI.
            $table->string('aksi', 40)->nullable();
            $table->string('pr_no', 40)->nullable();
            $table->string('pr_status_aksi', 60)->nullable();
            $table->string('pr_status', 120)->nullable();
            $table->decimal('pr_qty', 18, 3)->nullable();
            $table->unsignedInteger('pr_line')->default(0);
            $table->text('pr_nums')->nullable();
            $table->text('req_list')->nullable();
            $table->string('po_no', 40)->nullable();
            $table->decimal('po_sisa', 18, 3)->nullable();
            $table->unsignedInteger('po_line')->default(0);
            $table->unsignedInteger('po_uniq')->default(0);
            $table->unsignedInteger('po_closed')->default(0);
            $table->unsignedInteger('po_open')->default(0);
            $table->decimal('po_sisa_total', 18, 3)->nullable();
            $table->date('po_tanggal')->nullable();
            $table->string('po_tanggal_teks', 20)->nullable();
            $table->text('po_nums')->nullable();
            $table->string('purch', 60)->nullable();
            $table->string('vendor', 255)->nullable();
            $table->string('po_prev', 40)->nullable();
            $table->string('vendor_prev', 255)->nullable();
            $table->string('purch_last', 60)->nullable();
            $table->string('vendor_last', 255)->nullable();

            // Ringkasan stok gudang G001 (agregat per material).
            $table->unsignedInteger('g_baris')->default(0);
            $table->decimal('g_awal', 18, 3)->nullable();
            $table->decimal('g_masuk', 18, 3)->nullable();
            $table->decimal('g_keluar', 18, 3)->nullable();
            $table->decimal('g_sisa', 18, 3)->nullable();
            $table->string('g_satuan', 40)->nullable();
            $table->string('g_lokasi', 255)->nullable();

            // Nilai MRP (MD07) dan pembanding stok minimum.
            $table->decimal('mrp_plant', 18, 3)->nullable();
            $table->decimal('mrp_safety', 18, 3)->nullable();
            $table->decimal('mrp_reorder', 18, 3)->nullable();
            $table->string('min_cmp', 10)->nullable();
            $table->string('ket', 120)->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'kode_material']);
            $table->index(['batch_id', 'aksi']);
            $table->index(['batch_id', 'reorder']);
        });
    }

    /**
     * Menghapus seluruh tabel modul integrasi.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('sp_integrasi');
        Schema::dropIfExists('sp_mrp');
        Schema::dropIfExists('sp_po');
        Schema::dropIfExists('sp_pr');
        Schema::dropIfExists('sp_g001');
        Schema::dropIfExists('sp_monitoring');
        Schema::dropIfExists('sp_batch');
    }
};
