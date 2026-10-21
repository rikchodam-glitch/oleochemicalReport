<?php

namespace App\Services;

use App\Models\CutiTeknisi;
use App\Models\Technician;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pencatatan cuti teknisi oleh Admin Reguler.
 *
 * Cuti yang dicatat langsung berstatus disetujui dan langsung memengaruhi
 * target man hour. Teknisi hanya dapat melihat cuti miliknya (Cuti Saya).
 */
class CutiService
{
    /** Batas maksimum satu pencatatan cuti (hari kalender). */
    public const MAKS_HARI = 31;

    /**
     * Mencatat cuti baru untuk satu teknisi.
     *
     * @param  Technician          $teknisi Teknisi pemilik cuti
     * @param  array<string, mixed> $data   Data tervalidasi dari form admin
     * @return CutiTeknisi
     *
     * @throws ValidationException Bila tanggal/jenis/rentang tidak valid atau bentrok
     */
    public function catatOlehAdmin(Technician $teknisi, array $data): CutiTeknisi
    {
        [$mulai, $selesai, $jenis] = $this->validasiData($data, $teknisi->id);

        return CutiTeknisi::create([
            'technician_id'   => $teknisi->id,
            'tanggal_mulai'   => $mulai->toDateString(),
            'tanggal_selesai' => $selesai->toDateString(),
            'jenis'           => $jenis,
            'alasan'          => $this->rapikanAlasan($data['alasan'] ?? null),
            'status'          => CutiTeknisi::STATUS_DISETUJUI,
            'disetujui_at'    => now(),
            'diajukan_via'    => 'admin',
        ]);
    }

    /**
     * Memperbarui cuti yang sudah ada.
     *
     * @param  CutiTeknisi          $cuti Cuti yang diubah
     * @param  array<string, mixed> $data Data tervalidasi dari form
     * @return CutiTeknisi
     *
     * @throws ValidationException Bila tanggal/jenis/rentang tidak valid atau bentrok
     */
    public function perbarui(CutiTeknisi $cuti, array $data): CutiTeknisi
    {
        [$mulai, $selesai, $jenis] = $this->validasiData($data, $cuti->technician_id, $cuti->id);

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
     * Validasi tanggal, jenis, dan bentrok cuti.
     *
     * @param  array<string, mixed> $data
     * @param  int                  $technicianId
     * @param  int|null             $kecualiId
     * @return array{0: Carbon, 1: Carbon, 2: string}
     *
     * @throws ValidationException
     */
    private function validasiData(array $data, int $technicianId, ?int $kecualiId = null): array
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
            ->where('technician_id', $technicianId)
            ->when($kecualiId !== null, fn ($query) => $query->where('id', '!=', $kecualiId))
            ->whereIn('status', [CutiTeknisi::STATUS_PENDING, CutiTeknisi::STATUS_DISETUJUI])
            ->whereDate('tanggal_mulai', '<=', $selesai->toDateString())
            ->whereDate('tanggal_selesai', '>=', $mulai->toDateString())
            ->exists();

        if ($bentrok) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Sudah ada cuti lain yang beririsan pada tanggal tersebut.',
            ]);
        }

        return [$mulai, $selesai, $jenis];
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
