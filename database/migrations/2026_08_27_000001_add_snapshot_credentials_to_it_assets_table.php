<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Menambahkan kolom untuk fitur CCTV Snapshot (Sesi 7):
     *
     * - snapshot_url      : URL stream sumber gambar. Mendukung RTSP
     *                       (rtsp://host:554/path — diambil satu frame
     *                       lewat ffmpeg) maupun HTTP snapshot endpoint
     *                       langsung (http://host/snapshot.jpg — diambil
     *                       langsung tanpa ffmpeg). Lihat CctvSnapshotService.
     * - snapshot_username : Username auth kamera/NVR (opsional, tergantung
     *                       konfigurasi perangkat).
     * - snapshot_password : Password auth kamera/NVR, disimpan terenkripsi
     *                       lewat cast 'encrypted' di model ItAsset —
     *                       TIDAK PERNAH disimpan sebagai plaintext di DB.
     * - last_snapshot_error : Pesan error percobaan snapshot terakhir bila
     *                       gagal, ditampilkan di halaman detail agar admin
     *                       tahu kenapa snapshot tidak berhasil (mis. host
     *                       unreachable, auth ditolak, ffmpeg timeout).
     *
     * Kolom last_snapshot_path dan last_snapshot_at SUDAH ada dari migration
     * awal it_assets (2026_08_25_000001) — tidak diulang di sini.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('it_assets', function (Blueprint $table) {
            $table->string('snapshot_url')->nullable()->after('last_snapshot_at');
            $table->string('snapshot_username')->nullable()->after('snapshot_url');
            $table->text('snapshot_password')->nullable()->after('snapshot_username');
            $table->text('last_snapshot_error')->nullable()->after('snapshot_password');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('it_assets', function (Blueprint $table) {
            $table->dropColumn([
                'snapshot_url',
                'snapshot_username',
                'snapshot_password',
                'last_snapshot_error',
            ]);
        });
    }
};
