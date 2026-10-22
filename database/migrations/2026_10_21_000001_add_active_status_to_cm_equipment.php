<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tandai equipment CM yang masih dimonitoring.
     *
     * Latar (permintaan user 21/10/2026): sheet "Master Equipment" pada
     * spreadsheet Data CM dijadikan sumber kebenaran daftar equipment. Tag
     * yang dihapus dari sheet tersebut (mis. CT PUMP 1 & 2) harus berhenti
     * dimonitoring, tetapi histori reading/finding-nya TIDAK boleh ikut
     * terhapus. Karena itu equipment hanya dinonaktifkan:
     *   - is_active     : false = tidak tampil di daftar/hitungan CM
     *   - in_master     : apakah tag terakhir terlihat di sheet Master Equipment
     *   - deactivated_at: waktu equipment berhenti dimonitoring
     *
     * Nilai bawaan true/true membuat sync pertama langsung menonaktifkan tag
     * lama yang sudah tidak ada di master (perilaku yang diminta user).
     */
    public function up(): void
    {
        Schema::table('cm_equipment', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('tipe_lubrikasi')->index();
            $table->boolean('in_master')->default(true)->after('is_active');
            $table->timestamp('deactivated_at')->nullable()->after('in_master');
        });
    }

    public function down(): void
    {
        Schema::table('cm_equipment', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['is_active', 'in_master', 'deactivated_at']);
        });
    }
};
