<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel riwayat peminjaman alat.
 *
 * Nama dan kode alat disalin ke baris pinjaman supaya riwayat tetap terbaca
 * bila unit alatnya kelak dihapus. Peminjam teknisi memakai relasi technician;
 * peminjam non-teknisi menyimpan nama bebas dan departemen pilihan.
 */
return new class extends Migration
{
    /**
     * Membuat tabel tool_loans.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tool_loans', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();

            $table->foreignId('tool_id')->nullable()->constrained('tools')->nullOnDelete();
            $table->string('tool_name', 150);
            $table->string('tool_code', 50);

            $table->string('borrower_type', 20)->default('teknisi');
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->string('borrower_name', 150);
            $table->foreignId('borrower_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('borrower_department_nama', 150)->nullable();

            $table->timestamp('borrowed_at');
            $table->timestamp('returned_at')->nullable();

            $table->string('handled_by_type', 20)->default('keeper');
            $table->foreignId('handled_by_technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('returned_by_type', 20)->nullable();
            $table->foreignId('returned_by_technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['tool_id', 'returned_at']);
            $table->index(['technician_id', 'returned_at']);
            $table->index('borrowed_at');
        });
    }

    /**
     * Menghapus tabel tool_loans.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_loans');
    }
};
