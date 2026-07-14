<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('func_loc_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('total_scanned')->default(0);
            $table->integer('node_created_count')->default(0);
            $table->integer('asset_linked_count')->default(0);
            $table->integer('asset_skipped_empty_count')->default(0);
            $table->integer('asset_missing_node_count')->default(0);
            $table->json('detail_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('func_loc_sync_logs');
    }
};
