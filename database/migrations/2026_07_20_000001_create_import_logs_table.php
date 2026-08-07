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
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('nama_file');
            $table->string('tipe_import', 50)->comment('cm_excel');
            $table->unsignedInteger('total_baris')->default(0);
            $table->unsignedInteger('insert_baru')->default(0);
            $table->unsignedInteger('update_existing')->default(0);
            $table->unsignedInteger('gagal')->default(0);
            $table->unsignedInteger('unregistered')->default(0);
            $table->json('detail_unregistered')->nullable();
            $table->json('detail_error')->nullable();
            $table->string('status', 20)->default('processing')->comment('processing, completed, failed');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
