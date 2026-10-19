<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah data MRP (MD07) dan daftar nomor PR/PO terbuka pada modul
 * Integrasi PR/PO Spare Part.
 *
 * Latar: dashboard lama (Integrasi_PR_PO_SparePart.xlsx) memuat kolom
 * Stock by SAP, Safety Stk (MRP), Stok Min (MRP), dan Min Monitoring vs MRP
 * dari berkas EXPORT MRP.XLSX. Modul sebelumnya belum membaca berkas itu.
 *
 * Kolom pr_open_nums dan po_open_nums menyimpan nomor dokumen yang masih
 * terbuka, sehingga halaman dapat menampilkan lebih dari satu PR/PO baru
 * untuk satu material tanpa harus membuka rincian.
 */
return new class extends Migration
{
    /**
     * Menambah kolom MRP dan nomor dokumen terbuka.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('sp_material', function (Blueprint $table) {
            $table->text('pr_open_nums')->nullable()->after('pr_terbuka');
            $table->text('po_open_nums')->nullable()->after('po_open');

            $table->decimal('mrp_stock', 18, 3)->nullable()->after('po_diupdate_at');
            $table->decimal('mrp_safety', 18, 3)->nullable()->after('mrp_stock');
            $table->decimal('mrp_reorder', 18, 3)->nullable()->after('mrp_safety');
            $table->string('mrp_unit', 20)->nullable()->after('mrp_reorder');
            $table->string('mrp_type', 20)->nullable()->after('mrp_unit');
            $table->string('mrp_cmp', 10)->nullable()->after('mrp_type');
            $table->timestamp('mrp_diupdate_at')->nullable()->after('mrp_cmp');
        });

        Schema::table('sp_impor', function (Blueprint $table) {
            $table->unsignedInteger('jumlah_mrp')->default(0)->after('jumlah_po');
        });
    }

    /**
     * Membuang kolom yang ditambahkan.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('sp_material', function (Blueprint $table) {
            $table->dropColumn([
                'pr_open_nums',
                'po_open_nums',
                'mrp_stock',
                'mrp_safety',
                'mrp_reorder',
                'mrp_unit',
                'mrp_type',
                'mrp_cmp',
                'mrp_diupdate_at',
            ]);
        });

        Schema::table('sp_impor', function (Blueprint $table) {
            $table->dropColumn('jumlah_mrp');
        });
    }
};
