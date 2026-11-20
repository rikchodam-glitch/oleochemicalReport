<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\ToolToken;
use Illuminate\Support\Str;

/**
 * Membuat dan memeriksa token form Management Tools.
 *
 * Pola keamanan mengikuti ReportFormService: token asli hanya ada di tautan,
 * database menyimpan hash SHA-256, dan token kedaluwarsa setelah 30 menit.
 */
class ToolTokenService
{
    public const MASA_BERLAKU_MENIT = 30;

    /**
     * Buat token baru untuk satu teknisi dan satu tujuan form.
     *
     * Token lama yang belum pernah dibuka dan belum terpakai dibatalkan agar
     * tautan lama tidak menumpuk. Token yang sudah terlanjur dibuka tetap
     * berlaku sampai kedaluwarsa (sama seperti form laporan).
     *
     * @param  Technician   $teknisi      Pemilik token
     * @param  string       $purpose      Salah satu konstanta ToolToken::PURPOSE_*
     * @param  int|null     $prefillToolId Alat yang sudah dipilih lewat /pakai
     * @return array{token: ToolToken, url: string}
     */
    public function buatToken(Technician $teknisi, string $purpose, ?int $prefillToolId = null): array
    {
        ToolToken::query()
            ->where('technician_id', $teknisi->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->whereNull('opened_at')
            ->update(['used_at' => now()]);

        $asli = Str::random(64);

        $token = ToolToken::create([
            'purpose'         => $purpose,
            'technician_id'   => $teknisi->id,
            'token_hash'      => hash('sha256', $asli),
            'prefill_tool_id' => $prefillToolId,
            'expires_at'      => now()->addMinutes(self::MASA_BERLAKU_MENIT),
        ]);

        return [
            'token' => $token,
            'url'   => route('tools.form', ['token' => $asli]),
        ];
    }

    /**
     * Cari token yang masih berlaku dari token asli di URL.
     *
     * @param  string      $asli    Token asli dari tautan
     * @param  string|null $purpose Batasi ke purpose tertentu
     * @return ToolToken|null       Null bila tidak valid/kedaluwarsa/sudah dipakai
     */
    public function tokenValid(string $asli, ?string $purpose = null): ?ToolToken
    {
        $token = ToolToken::query()
            ->with(['technician.department', 'prefillTool'])
            ->where('token_hash', hash('sha256', $asli))
            ->first();

        if ($token === null) {
            return null;
        }

        if ($purpose !== null && $token->purpose !== $purpose) {
            return null;
        }

        return $token->masihBerlaku() ? $token : null;
    }

    /**
     * Tandai form sudah pernah dibuka (hanya diisi sekali).
     */
    public function tandaiDibuka(ToolToken $token): void
    {
        if ($token->opened_at === null) {
            $token->forceFill(['opened_at' => now()])->save();
        }
    }

    /**
     * Tandai token sekali pakai sudah terpakai.
     */
    public function tandaiTerpakai(ToolToken $token): void
    {
        if ($token->sekaliPakai() && $token->used_at === null) {
            $token->forceFill(['used_at' => now()])->save();
        }
    }

    /**
     * Pastikan pemilik token masih teknisi aktif (dan masih keeper bila token
     * keeper). Dipakai ulang setiap aksi supaya pencabutan akses langsung
     * berlaku walau tautan sudah terbuka.
     *
     * @return Technician|null Null bila akses tidak lagi sah
     */
    public function pemilikSah(ToolToken $token): ?Technician
    {
        $teknisi = $token->technician;

        if ($teknisi === null || $teknisi->status !== 'active') {
            return null;
        }

        if ($token->milikKeeper() && ! $teknisi->is_tool_keeper) {
            return null;
        }

        return $teknisi;
    }
}
