<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Tambahkan kolom koordinat lokasi (latitude/longitude) dan waktu terakhir
     * koordinat diatur pada tabel it_assets. Kolom ini nullable karena data
     * GPS belum tersedia; koordinat diisi manual oleh admin lewat peta
     * (fitur Network Map). Tidak mengubah perilaku fitur IT Assets existing.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('it_assets', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('location_set_at')->nullable()->after('longitude');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('it_assets', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_set_at']);
        });
    }
};
