<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Tambahkan kolom layout_id pada tabel network_links. Jalur kabel pada
     * fitur Denah digambar di atas sebuah denah, sehingga setiap link milik
     * satu layout (turunan dari posisi kedua endpointnya yang berada di
     * layout yang sama). Nullable agar data lama yang belum punya layout tetap
     * aman, dan nullOnDelete agar link ikut kehapus jika layout dihapus.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('network_links', function (Blueprint $table) {
            $table->foreignId('layout_id')->nullable()->after('id')->constrained('it_layouts')->nullOnDelete();
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('network_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('layout_id');
        });
    }
};
