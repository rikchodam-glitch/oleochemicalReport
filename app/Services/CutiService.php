<?php

namespace App\Services;

use App\Models\CutiTeknisi;
use App\Models\CutiToken;
use App\Models\Technician;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alur cuti teknisi: token tautan Telegram, pengajuan, dan persetujuan.
 *
 * Token hanya disimpan sebagai hash. Form cuti memakai session khusus
 * (cuti_token_id/cuti_technician_id), bukan login penuh ke panel admin.
 */
class CutiService
{
    /** Masa berlaku tautan form cuti (menit). */
    public const MASA_BERLAKU_MENIT = 30;

    /** Batas maksimum satu pengajuan (hari kalender). */
    public const MAKS_HARI = 31;

    /**
     * Membuat tautan form cuti sekali pakai untuk satu teknisi.
     *
     * Tautan lama yang BELUM PERNAH DIBUKA otomatis dibatalkan supaya tidak
     * menumpuk. Tautan yang sudah dibuka TIDAK dibatalkan: teknisi mungkin
     * sedang mengisi form, dan permintaan tautan baru tidak boleh membuat form
     * yang sudah terbuka menjadi "tautan tidak berlaku".
     *
     * @param  Technician   $teknisi Teknisi pemilik tautan
     * @param  string|null  $alasan  Alasan awal dari perintah /cuti (opsional)
     * @return array{token: CutiToken, plain: string, url: string, expires_at: \Carbon\CarbonInterface}
     */
    public function buatTautan(Technician $teknisi, ?string $alasan = null): array
    {
        CutiToken::query()
            ->where('technician_id', $teknisi->id)
            ->whereNull('used_at')
            ->whereNull('opened_at')
            ->update(['used_at' => now()]);

        $plain = Str::random(64);
        $token = CutiToken::create([
            'technician_id' => $teknisi->id,
            'token_hash'    => hash('sha256', $plain),
            'telegram_id'   => $teknisi->telegram_id,
            'alasan'        => $this->rapikanAlasan($alasan),
            'expires_at'    => now()->addMinutes(self::MASA_BERLAKU_MENIT),
        ]);

        return [
            'token'      => $token,
            'plain'      => $plain,
            'url'        => route('cuti.form', ['token' => $plain]),
            'expires_at' => $token->expires_at,
        ];
    }

    /**
     * Mencari token yang masih berlaku dari nilai mentah di URL.
     *
     * @param  string $plain Token mentah
     * @return CutiToken|null
     */
    public function tokenValid(string $plain): ?CutiToken
    {
        $token = CutiToken::with('technician')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if ($token === null || ! $token->masihBerlaku()) {
            return null;
        }

        return $token;
    }

    /**
     * Menandai tautan sudah dibuka pertama kali.
     *
     * @param  CutiToken $token Token yang dibuka
     * @return void
     */
    public function tandaiDibuka(CutiToken $token): void
    {
        if ($token->opened_at === null) {
            $token->update(['opened_at' => now()]);
        }
    }

    /**
     * Menyimpan pengajuan cuti dari form.
     *
     * @param  CutiToken            $token Token form
     * @param  array<string, mixed> $data  Data tervalidasi dari form
     * @return CutiTeknisi
     *
     * @throws ValidationException Bila tanggal/jenis/rentang tidak valid atau bentrok
     */
    public function ajukan(CutiToken $token, array $data): CutiTeknisi
    {
        $token->refresh();

        if (! $token->masihBerlaku()) {
            throw ValidationException::withMessages([
                'token' => 'Tautan form cuti sudah kedaluwarsa atau sudah dipakai. Kirim /cuti lagi di Telegram.',
            ]);
        }

        $mulai   = Carbon::parse((string) $data['tanggal_mulai'])->startOfDay();
        $selesai = Carbon::parse((string) $data['tanggal_selesai'])->startOfDay();

        if ($selesai->lt($mulai)) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ]);
        }

        if ($selesai->lt(today())) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Pengajuan cuti tidak boleh berakhir di masa lalu.',
            ]);
        }

        if ($mulai->diffInDays($selesai) + 1 > self::MAKS_HARI) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Satu pengajuan maksimal ' . self::MAKS_HARI . ' hari.',
            ]);
        }

        $jenis = (string) ($data['jenis'] ?? 'tahunan');

        if (! array_key_exists($jenis, CutiTeknisi::JENIS)) {
            throw ValidationException::withMessages([
                'jenis' => 'Jenis cuti tidak dikenal.',
            ]);
        }

        $bentrok = CutiTeknisi::query()
            ->where('technician_id', $token->technician_id)
            ->whereIn('status', [CutiTeknisi::STATUS_PENDING, CutiTeknisi::STATUS_DISETUJUI])
            ->whereDate('tanggal_mulai', '<=', $selesai->toDateString())
            ->whereDate('tanggal_selesai', '>=', $mulai->toDateString())
            ->exists();

        if ($bentrok) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Sudah ada pengajuan cuti yang beririsan pada tanggal tersebut.',
            ]);
        }

        return DB::transaction(function () use ($token, $data, $mulai, $selesai, $jenis): CutiTeknisi {
            $cuti = CutiTeknisi::create([
                'technician_id'    => $token->technician_id,
                'tanggal_mulai'    => $mulai->toDateString(),
                'tanggal_selesai'  => $selesai->toDateString(),
                'jenis'            => $jenis,
                'alasan'           => $this->rapikanAlasan($data['alasan'] ?? $token->alasan),
                // Tanpa alur persetujuan admin: cuti langsung berlaku dan
                // langsung mengurangi target man hour.
                'status'           => CutiTeknisi::STATUS_DISETUJUI,
                'disetujui_at'     => now(),
                'diajukan_via'     => $token->telegram_id !== null ? 'telegram' : 'web',
            ]);

            $token->update(['used_at' => now()]);

            return $cuti;
        });
    }

    /**
     * Memperbarui cuti milik teknisi dari halaman Cuti Saya.
     *
     * Perubahan langsung berlaku (status tetap disetujui) dan memengaruhi
     * target man hour. Tanggal lampau boleh diedit untuk koreksi; validasi
     * bentrok tetap dijalankan.
     *
     * @param  CutiTeknisi          $cuti Cuti yang diubah
     * @param  array<string, mixed> $data Data tervalidasi dari form
     * @return CutiTeknisi
     *
     * @throws ValidationException Bila tanggal/jenis/rentang tidak valid atau bentrok
     */
    public function perbarui(CutiTeknisi $cuti, array $data): CutiTeknisi
    {
        $mulai   = Carbon::parse((string) $data['tanggal_mulai'])->startOfDay();
        $selesai = Carbon::parse((string) $data['tanggal_selesai'])->startOfDay();

        if ($selesai->lt($mulai)) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ]);
        }

        if ($mulai->diffInDays($selesai) + 1 > self::MAKS_HARI) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Satu cuti maksimal ' . self::MAKS_HARI . ' hari.',
            ]);
        }

        $jenis = (string) ($data['jenis'] ?? 'tahunan');

        if (! array_key_exists($jenis, CutiTeknisi::JENIS)) {
            throw ValidationException::withMessages([
                'jenis' => 'Jenis cuti tidak dikenal.',
            ]);
        }

        $bentrok = CutiTeknisi::query()
            ->where('technician_id', $cuti->technician_id)
            ->where('id', '!=', $cuti->id)
            ->whereIn('status', [CutiTeknisi::STATUS_PENDING, CutiTeknisi::STATUS_DISETUJUI])
            ->whereDate('tanggal_mulai', '<=', $selesai->toDateString())
            ->whereDate('tanggal_selesai', '>=', $mulai->toDateString())
            ->exists();

        if ($bentrok) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Perubahan ini beririsan dengan cuti lain milik teknisi.',
            ]);
        }

        $cuti->update([
            'tanggal_mulai'   => $mulai->toDateString(),
            'tanggal_selesai' => $selesai->toDateString(),
            'jenis'           => $jenis,
            'alasan'          => $this->rapikanAlasan($data['alasan'] ?? null),
            'status'          => CutiTeknisi::STATUS_DISETUJUI,
            'disetujui_at'    => $cuti->disetujui_at ?? now(),
        ]);

        return $cuti->refresh();
    }

    /**
     * Merapikan alasan agar tidak menyimpan teks kosong/spasi berlebih.
     *
     * @param  string|null $teks Alasan mentah
     * @return string|null
     */
    private function rapikanAlasan(?string $teks): ?string
    {
        if ($teks === null) {
            return null;
        }

        $bersih = trim(preg_replace('/\s+/u', ' ', $teks) ?? '');

        return $bersih === '' ? null : mb_substr($bersih, 0, 1000);
    }
}
