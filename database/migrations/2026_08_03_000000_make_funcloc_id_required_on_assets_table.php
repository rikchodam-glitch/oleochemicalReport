<?php

use App\Models\Asset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kunci kolom assets.funcloc_id menjadi wajib (NOT NULL) di level
     * database. Migration ini sengaja diverifikasi dulu: jika masih ada
     * asset dengan funcloc_id kosong, migration gagal dengan pesan jelas
     * alih-alih memaksa mengunci constraint di atas data yang belum bersih.
     *
     * Foreign key lama (assets_funcloc_id_foreign) dibuat dengan
     * ON DELETE SET NULL, yang tidak kompatibel dengan kolom NOT NULL.
     * Karena itu foreign key lama di-drop dulu, kolom diubah menjadi
     * NOT NULL, lalu foreign key dipasang ulang dengan ON DELETE RESTRICT
     * agar FunctionalLocation yang masih dipakai asset tidak bisa dihapus.
     *
     * @return void
     */
    public function up(): void
    {
        $unlinkedCount = Asset::whereNull('funcloc_id')->count();

        if ($unlinkedCount > 0) {
            throw new \RuntimeException(
                "Migration dibatalkan: masih ada {$unlinkedCount} asset dengan funcloc_id kosong. ".
                'Tautkan seluruh asset ke Functional Location lewat halaman rekonsiliasi '.
                '(menu "Belum Punya Lokasi") sebelum menjalankan migration ini.'
            );
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['funcloc_id']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('funcloc_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('funcloc_id')
                ->references('id')->on('functional_locations')
                ->restrictOnDelete();
        });
    }

    /**
     * Kembalikan kolom funcloc_id menjadi nullable dengan foreign key
     * ON DELETE SET NULL seperti kondisi semula (lihat migration
     * 0001_01_01_000013_add_funcloc_id_to_assets_table).
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['funcloc_id']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('funcloc_id')
                ->nullable()
                ->change();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('funcloc_id')
                ->references('id')->on('functional_locations')
                ->nullOnDelete();
        });
    }
};
