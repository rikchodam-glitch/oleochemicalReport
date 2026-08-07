<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan status "free" (equipment tidak running / Stop) pada
     * cm_monthly_tracking, agar bulan yang equipment-nya stop tidak dianggap
     * "belum" (keterlambatan) melainkan "free"/dimaafkan.
     */
    public function up(): void
    {
        Schema::table('cm_monthly_tracking', function (Blueprint $table) {
            $table->enum('status', ['sudah', 'belum', 'free'])->default('belum')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_monthly_tracking', function (Blueprint $table) {
            $table->enum('status', ['sudah', 'belum'])->default('belum')->change();
        });
    }
};
