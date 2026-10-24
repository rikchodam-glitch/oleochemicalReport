<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel request spare part dari teknisi.
 *
 * Satu baris mewakili satu request yang diajukan lewat bot Telegram. Nomor PR,
 * PO, dan penerimaan barang dicatat pada baris yang sama mengikuti alur:
 * menunggu -> disetujui -> kejar_po -> kejar_delivery -> selesai.
 */
return new class extends Migration
{
    /**
     * Membuat tabel sp_requests beserta relasi pelakunya.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('sp_requests', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->text('keterangan');
            $table->text('deskripsi_ukuran')->nullable();
            $table->decimal('jumlah', 12, 2);
            $table->string('satuan', 30)->nullable();
            $table->string('foto_path', 255)->nullable();
            $table->string('status', 20)->default('menunggu');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('alasan_tolak')->nullable();

            $table->string('pr_no', 50)->nullable();
            $table->foreignId('pr_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pr_at')->nullable();

            $table->string('po_no', 50)->nullable();
            $table->foreignId('po_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('po_at')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('pr_no');
            $table->index('po_no');
            $table->index('created_at');
        });
    }

    /**
     * Menghapus tabel sp_requests.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('sp_requests');
    }
};
