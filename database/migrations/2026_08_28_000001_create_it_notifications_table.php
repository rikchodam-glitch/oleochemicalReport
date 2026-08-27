<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Membuat tabel it_notifications untuk notifikasi dalam aplikasi
     * terhadap peristiwa pada perangkat IT: offline, latency tinggi,
     * kembali online, dan error snapshot. Notifikasi dibaca oleh halaman
     * IT Assets lewat badge lonceng dan daftar notifikasi.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('it_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('it_asset_id')->constrained('it_assets')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->text('message');
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('it_notifications');
    }
};
