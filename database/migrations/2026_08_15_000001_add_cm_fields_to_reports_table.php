<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan field untuk klasifikasi CM/dCM/PM ke tabel reports.
     *
     * Field baru:
     * - jenis_pekerjaan    : enum CM|dCM|PM, wajib diisi (nullable dulu untuk data lama)
     * - linked_finding_id  : FK ke cm_findings, opsional
     * - sesuai_rencana     : enum ya|tidak, nullable (wajib HANYA jika dCM)
     * - tanggal_kejadian   : date, nullable (kapan gejala terdeteksi)
     * - equipment_tag      : string, nullable (FK ke cm_equipment atau asset tag)
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->enum('jenis_pekerjaan', ['CM', 'dCM', 'PM'])
                ->nullable()
                ->after('report_type')
                ->comment('Klasifikasi pekerjaan: CM (Corrective), dCM (Deferred), PM (Preventive)');

            $table->foreignId('linked_finding_id')
                ->nullable()
                ->after('jenis_pekerjaan')
                ->constrained('cm_findings')
                ->nullOnDelete()
                ->comment('FK ke finding CM jika pekerjaan ini tindak lanjut dari temuan tertentu');

            $table->enum('sesuai_rencana', ['ya', 'tidak'])
                ->nullable()
                ->after('linked_finding_id')
                ->comment('Wajib diisi hanya jika jenis_pekerjaan = dCM');

            $table->date('tanggal_kejadian')
                ->nullable()
                ->after('sesuai_rencana')
                ->comment('Tanggal gejala/failure mulai terdeteksi (bisa berbeda dengan tanggal laporan)');

            $table->string('equipment_tag', 100)
                ->nullable()
                ->after('tanggal_kejadian')
                ->comment('Tag equipment dari CM master (equipment_tag di cm_equipment)');

            // Index untuk pencarian/filter
            $table->index('jenis_pekerjaan');
            $table->index('tanggal_kejadian');
            $table->index(['jenis_pekerjaan', 'sesuai_rencana']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['linked_finding_id']);
            $table->dropIndex(['jenis_pekerjaan']);
            $table->dropIndex(['tanggal_kejadian']);
            $table->dropIndex(['jenis_pekerjaan', 'sesuai_rencana']);
            $table->dropColumn([
                'jenis_pekerjaan',
                'linked_finding_id',
                'sesuai_rencana',
                'tanggal_kejadian',
                'equipment_tag',
            ]);
        });
    }
};
