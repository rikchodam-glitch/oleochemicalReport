<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah riwayat PR per material pada modul Integrasi PR/PO Spare Part.
 *
 * Riwayat ini berasal dari baris EXPORT PR (SAP ME5A) yang sudah dibaca
 * SparepartReader, dikelompokkan per nomor PR. Halaman Sparepart memakainya
 * untuk menampilkan History PR pada panel rincian tiap material.
 */
return new class extends Migration
{
    /**
     * Menambah kolom JSON riwayat PR.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('sp_material', function (Blueprint $table) {
            $table->json('pr_history')->nullable()->after('req_list');
        });
    }

    /**
     * Membuang kolom riwayat PR.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('sp_material', function (Blueprint $table) {
            $table->dropColumn('pr_history');
        });
    }
};
