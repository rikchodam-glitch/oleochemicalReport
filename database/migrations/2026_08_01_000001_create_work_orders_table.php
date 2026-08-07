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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('equipment_tag', 50);
            $table->date('tanggal_kejadian');
            $table->date('tanggal_eksekusi_selesai');
            $table->enum('jenis_pekerjaan', ['CM', 'dCM', 'PM']);
            $table->foreignId('linked_finding_id')
                ->nullable()
                ->constrained('cm_findings')
                ->nullOnDelete();
            $table->text('deskripsi')->nullable();
            $table->string('root_cause')->nullable();
            $table->enum('sesuai_rencana', ['ya', 'tidak'])->nullable()
                ->comment('Wajib diisi hanya jika jenis_pekerjaan = dCM');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('equipment_tag');
            $table->index('jenis_pekerjaan');
            $table->index('tanggal_kejadian');
            $table->index('tanggal_eksekusi_selesai');
            $table->index(['equipment_tag', 'tanggal_kejadian']);
            $table->index(['jenis_pekerjaan', 'sesuai_rencana']);
            $table->index(['jenis_pekerjaan', 'linked_finding_id']);
            $table->index('created_by');

            // Constraint: tanggal_eksekusi_selesai >= tanggal_kejadian
            // (tidak bisa di enforce di MySQL, validasi di level app)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
