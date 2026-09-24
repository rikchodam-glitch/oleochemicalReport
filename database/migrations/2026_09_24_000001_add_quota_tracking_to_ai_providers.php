<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah pelacakan kuota provider AI langsung dari API.
 *
 * Groq tidak menyediakan endpoint "sisa kuota" (GET /usage, /limits,
 * /rate_limits semuanya 404). Satu-satunya sumber nyata adalah header
 * x-ratelimit-* pada setiap respons chat dan HTTP 429 saat batas terlampaui,
 * jadi nilai header itu disimpan per provider di sini:
 *   - batas & sisa token/permintaan per menit menurut API,
 *   - waktu pemeriksaan terakhir,
 *   - masa tunggu (cooldown) hasil HTTP 429,
 *   - tanggal reset penghitung harian agar reset berjalan otomatis.
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
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('quota_limit_tokens_per_minute')->nullable()->after('status');
            $table->unsignedBigInteger('quota_remaining_tokens_per_minute')->nullable()->after('quota_limit_tokens_per_minute');
            $table->unsignedInteger('quota_limit_requests_per_minute')->nullable()->after('quota_remaining_tokens_per_minute');
            $table->unsignedInteger('quota_remaining_requests_per_minute')->nullable()->after('quota_limit_requests_per_minute');
            $table->timestamp('quota_checked_at')->nullable()->after('quota_remaining_requests_per_minute');
            $table->timestamp('cooldown_until')->nullable()->after('quota_checked_at');
            $table->date('quota_day')->nullable()->after('cooldown_until');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn([
                'quota_limit_tokens_per_minute',
                'quota_remaining_tokens_per_minute',
                'quota_limit_requests_per_minute',
                'quota_remaining_requests_per_minute',
                'quota_checked_at',
                'cooldown_until',
                'quota_day',
            ]);
        });
    }
};
