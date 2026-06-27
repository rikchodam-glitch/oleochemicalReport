<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('report_code', 20)->nullable()->unique()->after('id');
            $table->integer('work_duration_minutes')->nullable()->after('work_description');
            $table->text('root_cause')->nullable()->after('work_duration_minutes');
            $table->json('photo_documentation')->nullable()->after('root_cause');
            $table->json('photo_hygiene_clearance')->nullable()->after('photo_documentation');
            $table->dateTime('wizard_started_at')->nullable()->after('photo_hygiene_clearance');
            $table->dateTime('submitted_at')->nullable()->after('wizard_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'report_code',
                'work_duration_minutes',
                'root_cause',
                'photo_documentation',
                'photo_hygiene_clearance',
                'wizard_started_at',
                'submitted_at',
            ]);
        });
    }
};
