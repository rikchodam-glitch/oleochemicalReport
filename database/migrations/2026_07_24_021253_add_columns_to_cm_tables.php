<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tambah kolom ke cm_readings
        Schema::table('cm_readings', function (Blueprint $table) {
            $table->decimal('ndev_screw', 10, 2)->nullable()->after('ndev_pompa');
            $table->decimal('ndeh_screw', 10, 2)->nullable()->after('ndev_screw');
            $table->decimal('ndea_screw', 10, 2)->nullable()->after('ndeh_screw');
            $table->decimal('temp_nde_screw', 10, 2)->nullable()->after('ndea_screw');
            $table->decimal('dev_screw', 10, 2)->nullable()->after('temp_nde_screw');
            $table->decimal('deh_screw', 10, 2)->nullable()->after('dev_screw');
            $table->decimal('dea_screw', 10, 2)->nullable()->after('deh_screw');
            $table->decimal('temp_de_screw', 10, 2)->nullable()->after('dea_screw');
            $table->decimal('temp_nde_motor', 10, 2)->nullable()->after('temp_de_motor');
            $table->decimal('temp_nde_pompa', 10, 2)->nullable()->after('temp_de_pompa');
            $table->decimal('ampere', 10, 2)->nullable()->after('kondisi');
            $table->decimal('discharge_pressure', 10, 2)->nullable()->after('ampere');
            $table->string('oil_level', 50)->nullable()->after('discharge_pressure');
            $table->string('mech_seal', 20)->nullable()->after('oil_level');
            $table->string('noise', 20)->nullable()->after('mech_seal');
            $table->string('coupling', 20)->nullable()->after('noise');
            $table->string('safety', 20)->nullable()->after('coupling');
            $table->text('remark')->nullable()->after('safety');
            $table->decimal('max_vibration', 10, 2)->nullable()->after('remark');
            $table->decimal('max_temp', 10, 2)->nullable()->after('max_vibration');
            $table->text('analysis')->nullable()->after('max_temp');
            $table->integer('data_no')->nullable()->after('analysis');
        });

        Schema::table('cm_findings', function (Blueprint $table) {
            $table->string('kode_finding', 50)->unique()->nullable()->after('id');
            $table->text('analysis')->nullable()->after('deskripsi');
            $table->text('action')->nullable()->after('analysis');
            $table->json('foto_urls')->nullable()->after('foto_url');
            $table->date('date_action')->nullable()->after('foto_urls');
            $table->foreignId('cm_reading_id')->nullable()->after('date_action')->constrained('cm_readings')->nullOnDelete();
        });

        Schema::table('cm_findings', function (Blueprint $table) {
            $table->string('kategori', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('cm_readings', function (Blueprint $table) {
            $table->dropColumn([
                'ndev_screw', 'ndeh_screw', 'ndea_screw', 'temp_nde_screw',
                'dev_screw', 'deh_screw', 'dea_screw', 'temp_de_screw',
                'temp_nde_motor', 'temp_nde_pompa',
                'ampere', 'discharge_pressure', 'oil_level',
                'mech_seal', 'noise', 'coupling', 'safety', 'remark',
                'max_vibration', 'max_temp', 'analysis', 'data_no',
            ]);
        });

        Schema::table('cm_findings', function (Blueprint $table) {
            $table->dropForeign(['cm_reading_id']);
            $table->dropColumn([
                'kode_finding', 'analysis', 'action',
                'foto_urls', 'date_action', 'cm_reading_id',
            ]);
            $table->string('kategori', 50)->change();
        });
    }
};
