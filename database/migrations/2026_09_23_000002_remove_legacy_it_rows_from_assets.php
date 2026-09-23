<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hapus baris legacy perangkat IT dari tabel assets.
 *
 * Latar belakang: perangkat komputer (PC-EPE-*) dan CCTV (CCTV-EPE-*) pernah
 * diimport terpisah ke tabel assets dari data SAP, padahal sekarang sudah
 * dikelola di modul IT Assets dan disinkronkan sebagai baris cermin
 * (data_source = 'it_sync', kolom it_asset_id terisi).
 *
 * Urutan kerja migration ini:
 *   1. Laporan yang masih menunjuk baris legacy dipindahkan ke baris cermin
 *      dengan tag yang sama bila ada (agar tautan laporan tidak hilang).
 *   2. Baris legacy tanpa padanan dihapus. Laporan yang tidak punya padanan
 *      kehilangan tautan asset_id (foreign key ON DELETE SET NULL) — ini sudah
 *      disetujui karena laporan lama tersebut tetap menyimpan funcloc dan
 *      deskripsi pekerjaannya.
 */
return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * @return void
     */
    public function up(): void
    {
        $legacy = DB::table('assets')
            ->where(function ($query) {
                $query->where('tech_ident_no', 'like', 'PC-EPE-%')
                    ->orWhere('tech_ident_no', 'like', 'CCTV-EPE-%');
            })
            ->where('data_source', '!=', 'it_sync')
            ->get(['id', 'tech_ident_no']);

        foreach ($legacy as $row) {
            $pengganti = DB::table('assets')
                ->where('data_source', 'it_sync')
                ->where('tech_ident_no', $row->tech_ident_no)
                ->value('id');

            if ($pengganti === null) {
                continue;
            }

            DB::table('reports')->where('asset_id', $row->id)->update(['asset_id' => $pengganti]);
            DB::table('report_ai_suggestions')
                ->where('suggested_asset_id', $row->id)
                ->update(['suggested_asset_id' => $pengganti]);
            DB::table('ai_aliases')->where('asset_id', $row->id)->update(['asset_id' => $pengganti]);
        }

        $ids = $legacy->pluck('id')->all();

        if ($ids !== []) {
            DB::table('assets')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Batalkan migration.
     *
     * Tidak ada rollback data yang aman: baris yang dihapus berasal dari import
     * SAP dan tidak bisa direkonstruksi dari database. Jalankan import ulang
     * bila baris tersebut memang dibutuhkan kembali.
     *
     * @return void
     */
    public function down(): void
    {
        // Sengaja kosong.
    }
};
