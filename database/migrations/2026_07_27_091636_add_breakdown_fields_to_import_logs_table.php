<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("import_logs", function (Blueprint $table) {
            $table->unsignedInteger("total_dibaca")->default(0)->after("total_baris");
            $table->unsignedInteger("total_skipped_duplikat")->default(0)->after("unregistered");
            $table->unsignedInteger("total_skipped_kosong")->default(0)->after("total_skipped_duplikat");
            $table->json("detail_skipped")->nullable()->after("detail_error");
            $table->json("created_reading_ids")->nullable()->after("detail_skipped");
            $table->json("created_equipment_ids")->nullable()->after("created_reading_ids");
        });
    }

    public function down(): void
    {
        Schema::table("import_logs", function (Blueprint $table) {
            $table->dropColumn([
                "total_dibaca",
                "total_skipped_duplikat",
                "total_skipped_kosong",
                "detail_skipped",
                "created_reading_ids",
                "created_equipment_ids",
            ]);
        });
    }
};
