<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Jalankan migrasi: tabel daftar ruangan panel beserta perangkat sensor
     * suhu/kelembapan yang terpasang di ruangan tersebut.
     */
    public function up(): void
    {
        Schema::create('panel_ruangan', function (Blueprint $table) {
            $table->id();

            // Identitas ruangan. Mengikuti struktur data proyek ini:
            // PT Location lalu Plant (tanpa Area / Functional Location).
            $table->string('nama_ruangan', 100)->unique();
            $table->string('pt_location', 100)->nullable();
            $table->string('plant', 50)->nullable();
            $table->string('keterangan', 255)->nullable();

            // Identitas perangkat ESP32 pembaca sensor.
            $table->string('ip_address', 45);
            $table->unsignedSmallInteger('port')->default(80);
            $table->string('mac_address', 17)->nullable();

            // Aktif berarti perangkat ikut ditarik data oleh `panel:tarik`.
            $table->boolean('aktif')->default(true);

            // Ambang suhu (derajat Celsius) untuk penanda peringatan.
            $table->decimal('ambang_suhu_atas', 5, 2)->default(30);
            $table->decimal('ambang_suhu_bawah', 5, 2)->default(18);

            // Kondisi terakhir hasil penarikan data.
            $table->string('status_terakhir', 20)->default('belum-diketahui');
            $table->timestamp('terakhir_terlihat')->nullable();
            $table->string('sesi_boot', 12)->nullable();
            $table->unsignedInteger('id_pembacaan_terakhir')->nullable();
            $table->timestamp('terakhir_ditarik')->nullable();
            $table->string('catatan', 255)->nullable();

            $table->timestamps();

            $table->index('aktif');
            $table->index('pt_location');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('panel_ruangan');
    }
};
