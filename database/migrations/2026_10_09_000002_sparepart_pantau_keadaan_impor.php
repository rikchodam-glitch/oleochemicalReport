<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rancangan ulang modul Spare Part: daftar pantau, keadaan terkini, dan
 * riwayat import.
 *
 * Latar: rancangan lama memakai satu batch per periode (sp_batch + tabel
 * mentah per batch + sp_integrasi) sehingga halaman awal berupa daftar
 * periode yang harus dibuka satu-satu. Kebutuhan sebenarnya adalah SATU tabel
 * yang selalu mutakhir per kode material (update tiap import dengan
 * mencocokkan kode material), sedangkan riwayat import hanya dipakai untuk
 * analisa.
 *
 * Karena itu tiga tabel baru dibuat:
 * 1. sp_pantau   - daftar material yang dipantau (sumber filter halaman),
 * 2. sp_material - keadaan terkini per kode material (hasil upsert),
 * 3. sp_impor    - riwayat import + analisa format berkasnya.
 *
 * Tabel rancangan lama dibuang di akhir. Aman karena modul ini BELUM pernah
 * dijalankan pada database produksi (diperiksa 09/10/2026: `show tables like
 * 'sp%'` pada database oleochemical kosong).
 */
return new class extends Migration
{
    /**
     * Membuat tabel baru dan membuang tabel rancangan lama.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('sp_pantau', function (Blueprint $table) {
            $table->id();
            $table->string('kode_material', 40)->unique();
            $table->string('deskripsi', 255)->nullable();
            $table->string('plant', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('tag_no', 100)->nullable();
            $table->string('uom', 20)->nullable();

            // Stok minimum: angka pembanding stok tersedia untuk menentukan
            // apakah material perlu dipesan ulang.
            $table->decimal('stok_min', 18, 3)->nullable();

            // Sumber baris: 'master' (dari sheet MASTER DATA) atau 'manual'.
            $table->string('sumber', 20)->default('master');
            $table->boolean('aktif')->default(true);
            $table->string('catatan', 255)->nullable();
            $table->timestamps();

            $table->index('aktif');
            $table->index('sumber');
        });

        Schema::create('sp_material', function (Blueprint $table) {
            $table->id();
            $table->string('kode_material', 40)->unique();
            $table->string('deskripsi', 255)->nullable();

            // Stok: nilai pembanding diambil dari daftar pantau saat hitung.
            $table->decimal('stok_min', 18, 3)->nullable();
            $table->decimal('stok_tersedia', 18, 3)->nullable();
            $table->boolean('reorder')->default(false);
            $table->string('penanda', 120)->nullable();

            // Hasil pemilihan PR/PO dan kolom aksi tindak lanjut.
            $table->string('aksi', 40)->nullable();
            $table->string('pr_no', 40)->nullable();
            $table->string('pr_status_aksi', 60)->nullable();
            $table->string('pr_status', 120)->nullable();
            $table->decimal('pr_qty', 18, 3)->nullable();
            $table->unsignedInteger('pr_line')->default(0);
            $table->unsignedInteger('pr_terbuka')->default(0);
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

            // Ringkasan stok gudang G001 per material.
            $table->unsignedInteger('g_baris')->default(0);
            $table->decimal('g_awal', 18, 3)->nullable();
            $table->decimal('g_masuk', 18, 3)->nullable();
            $table->decimal('g_keluar', 18, 3)->nullable();
            $table->decimal('g_sisa', 18, 3)->nullable();
            $table->string('g_satuan', 40)->nullable();
            $table->string('g_lokasi', 255)->nullable();

            $table->string('ket', 120)->nullable();

            // Cap waktu per sumber, supaya terlihat bagian mana yang paling
            // akhir diperbarui (import boleh hanya memuat sebagian berkas).
            $table->timestamp('stok_diupdate_at')->nullable();
            $table->timestamp('pr_diupdate_at')->nullable();
            $table->timestamp('po_diupdate_at')->nullable();
            $table->timestamps();

            $table->index('aksi');
            $table->index('reorder');
        });

        Schema::create('sp_impor', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120)->nullable();

            // Rincian berkas yang dipakai: nama, jumlah baris, judul kolom.
            $table->json('berkas')->nullable();

            // Analisa hasil import: material baru/diubah, perubahan status,
            // material di luar daftar pantau, dan catatan format.
            $table->json('analisa')->nullable();

            $table->string('status', 20)->default('proses');
            $table->unsignedInteger('jumlah_pantau')->default(0);
            $table->unsignedInteger('jumlah_stok')->default(0);
            $table->unsignedInteger('jumlah_pr')->default(0);
            $table->unsignedInteger('jumlah_po')->default(0);
            $table->unsignedInteger('jumlah_keadaan')->default(0);
            $table->text('pesan')->nullable();
            $table->unsignedBigInteger('oleh')->nullable();
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // Rancangan lama: satu batch per periode + tabel mentah per batch.
        // Dibuang supaya tidak ada tabel tanpa penulis.
        Schema::dropIfExists('sp_integrasi');
        Schema::dropIfExists('sp_monitoring');
        Schema::dropIfExists('sp_mrp');
        Schema::dropIfExists('sp_g001');
        Schema::dropIfExists('sp_pr');
        Schema::dropIfExists('sp_po');
        Schema::dropIfExists('sp_batch');
    }

    /**
     * Membuang tabel baru saat migrasi dibatalkan.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('sp_impor');
        Schema::dropIfExists('sp_material');
        Schema::dropIfExists('sp_pantau');
    }
};
