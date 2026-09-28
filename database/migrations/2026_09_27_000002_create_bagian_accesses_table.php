<?php

use App\Models\Technician;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses halaman per Bagian: satu baris per Bagian, kolom permissions
 * berisi daftar kunci modul (lihat config/akses.php) yang boleh diakses.
 *
 * Isi awal: seluruh modul diizinkan, sesuai kondisi aplikasi saat ini yang
 * membuka semua halaman untuk semua bagian. Administrator bisa mengurangi
 * lewat halaman "Hak Akses Bagian".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bagian_accesses', function (Blueprint $table) {
            $table->id();
            $table->string('bagian', 50)->unique();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        $this->isiHakAksesDefault();
    }

    public function down(): void
    {
        Schema::dropIfExists('bagian_accesses');
    }

    /**
     * Isi hak akses awal (semua modul dicentang) untuk setiap Bagian yang
     * dikenal pada daftar section teknisi.
     *
     * @return void
     */
    private function isiHakAksesDefault(): void
    {
        $semuaModul = array_keys(config('akses.modul', []));
        $sekarang   = now();

        foreach (array_keys(Technician::SECTIONS) as $bagian) {
            DB::table('bagian_accesses')->updateOrInsert(
                ['bagian' => $bagian],
                [
                    'permissions' => json_encode($semuaModul),
                    'created_at'  => $sekarang,
                    'updated_at'  => $sekarang,
                ]
            );
        }
    }
};
