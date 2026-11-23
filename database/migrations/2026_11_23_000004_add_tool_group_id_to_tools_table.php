<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan relasi kelompok ke unit alat dan mengisi data lama.
 *
 * Nama alat yang sudah ada (mis. "Bor Batrai") dijadikan kelompok awal
 * sehingga BRBT001 langsung masuk ke kelompok Bor Batrai tanpa kehilangan data.
 */
return new class extends Migration
{
    /**
     * Tambah kolom tool_group_id dan backfill dari nama alat lama.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->foreignId('tool_group_id')
                ->nullable()
                ->after('tool_rack_id')
                ->constrained('tool_groups')
                ->nullOnDelete();
        });

        $sekarang = now();

        DB::table('tool_types')->updateOrInsert(
            ['name' => 'Umum'],
            [
                'description' => 'Jenis bawaan untuk alat lama.',
                'is_active'   => true,
                'created_at'  => $sekarang,
                'updated_at'  => $sekarang,
            ]
        );

        $tipeId = (int) DB::table('tool_types')->where('name', 'Umum')->value('id');

        $namaLama = DB::table('tools')
            ->select('name')
            ->distinct()
            ->pluck('name');

        foreach ($namaLama as $nama) {
            $namaBersih = trim((string) $nama) ?: 'Tanpa Kelompok';

            DB::table('tool_groups')->updateOrInsert(
                ['name' => $namaBersih],
                [
                    'tool_type_id' => $tipeId,
                    'created_at'   => $sekarang,
                    'updated_at'   => $sekarang,
                ]
            );

            $groupId = (int) DB::table('tool_groups')->where('name', $namaBersih)->value('id');

            DB::table('tools')
                ->where('name', $nama)
                ->update(['tool_group_id' => $groupId]);
        }
    }

    /**
     * Hapus kolom tool_group_id.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tool_group_id');
        });
    }
};
