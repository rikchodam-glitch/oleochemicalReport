<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan pengajuan pengembalian yang harus diterima tool keeper.
 *
 * returned_at tetap menjadi waktu alat benar-benar diterima kembali.
 * return_requested_at diisi saat peminjam mengajukan pengembalian.
 */
return new class extends Migration
{
    /**
     * Tambah kolom pengajuan pengembalian.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('tool_loans', function (Blueprint $table) {
            $table->timestamp('return_requested_at')->nullable()->after('returned_at');
            $table->string('return_requested_by_type', 20)->nullable()->after('return_requested_at');
            $table->foreignId('return_requested_by_technician_id')
                ->nullable()
                ->after('return_requested_by_type')
                ->constrained('technicians')
                ->nullOnDelete();
            $table->foreignId('return_requested_by_user_id')
                ->nullable()
                ->after('return_requested_by_technician_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('return_requested_at');
        });
    }

    /**
     * Hapus kolom pengajuan pengembalian.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('tool_loans', function (Blueprint $table) {
            $table->dropIndex(['return_requested_at']);
            $table->dropConstrainedForeignId('return_requested_by_technician_id');
            $table->dropConstrainedForeignId('return_requested_by_user_id');
            $table->dropColumn(['return_requested_at', 'return_requested_by_type']);
        });
    }
};
