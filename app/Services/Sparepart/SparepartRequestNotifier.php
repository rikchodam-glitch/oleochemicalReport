<?php

namespace App\Services\Sparepart;

use App\Models\SpRequest;
use App\Models\Technician;
use App\Services\TelegramService;
use Illuminate\Support\Collection;

/**
 * Notifikasi Telegram untuk request spare part.
 *
 * Pemohon menerima kabar pada setiap tahap. Staff CM/PM menerima kabar saat
 * request baru masuk supaya langsung bisa menindaklanjuti.
 */
class SparepartRequestNotifier
{
    public function __construct(private readonly TelegramService $telegram)
    {
    }

    /**
     * Kirim notifikasi request baru ke pemohon dan seluruh Staff CM/PM.
     *
     * @param  SpRequest $request Request yang baru dibuat
     * @return void
     */
    public function kirimDiajukan(SpRequest $request): void
    {
        $request->loadMissing('technician');

        $this->kirimKeTeknisi(
            $request->technician,
            "Request spare part <b>{$request->kode}</b> sudah diterima.\n"
            . $this->ringkasan($request)
            . "\n\nStatus: menunggu persetujuan Staff CM/PM."
        );

        foreach ($this->staffCmPm($request) as $staff) {
            $this->kirimKeTeknisi(
                $staff,
                "Request spare part baru <b>{$request->kode}</b>.\n"
                . "Pemohon: " . e($request->technician?->name ?? 'Teknisi tidak dikenal')
                . ($request->technician?->nik ? ' (' . e($request->technician->nik) . ')' : '') . "\n"
                . $this->ringkasan($request)
                . "\n\nBuka halaman Sparepart > Request Sparepart untuk memproses."
            );
        }
    }

    /**
     * Kirim notifikasi perubahan status ke teknisi pemohon.
     *
     * @param  SpRequest $request Request yang berubah
     * @param  string    $tahap   Status baru (gunakan konstanta SpRequest)
     * @return void
     */
    public function kirimStatus(SpRequest $request, string $tahap): void
    {
        $request->loadMissing('technician');

        $pesan = match ($tahap) {
            SpRequest::STATUS_DISETUJUI => "Request spare part <b>{$request->kode}</b> disetujui.\n"
                . "Menunggu pengisian nomor PR oleh Staff CM/PM.",
            SpRequest::STATUS_DITOLAK => "Request spare part <b>{$request->kode}</b> ditolak.\n"
                . "Alasan: " . e($request->alasan_tolak ?: '-'),
            SpRequest::STATUS_KEJAR_PO => "Nomor PR untuk request <b>{$request->kode}</b> sudah dicatat: "
                . e((string) $request->pr_no) . ".\nStatus: Kejar PO.",
            SpRequest::STATUS_KEJAR_DELIVERY => "Nomor PO untuk request <b>{$request->kode}</b> sudah terbit: "
                . e((string) $request->po_no) . ".\nStatus: Kejar Delivery.",
            SpRequest::STATUS_SELESAI => "Barang untuk request <b>{$request->kode}</b> sudah diterima.\n"
                . "Request selesai.",
            default => "Status request <b>{$request->kode}</b> berubah menjadi " . $request->labelStatus() . '.',
        };

        $this->kirimKeTeknisi($request->technician, $pesan);
    }

    /**
     * Ringkasan isi request untuk badan pesan.
     *
     * @param  SpRequest $request
     * @return string
     */
    private function ringkasan(SpRequest $request): string
    {
        $baris = [
            'Keterangan: ' . e($request->keterangan),
        ];

        if ($request->deskripsi_ukuran) {
            $baris[] = 'Ukuran: ' . e($request->deskripsi_ukuran);
        }

        $baris[] = 'Jumlah: ' . $this->angka($request->jumlah)
            . ($request->satuan ? ' ' . e($request->satuan) : '');
        $baris[] = 'Foto: ' . ($request->foto_path ? 'ada' : 'tidak ada');

        return implode("\n", $baris);
    }

    /**
     * Daftar Staff CM/PM yang menerima notifikasi request baru.
     *
     * @param  SpRequest $request
     * @return Collection<int, Technician>
     */
    private function staffCmPm(SpRequest $request): Collection
    {
        return Technician::query()
            ->where('section', 'cm_pm')
            ->where('group', 'reguler_staff')
            ->where('status', 'active')
            ->whereNotNull('telegram_id')
            ->when($request->technician_id !== null, fn ($query) => $query->whereKeyNot($request->technician_id))
            ->get();
    }

    /**
     * Kirim pesan Telegram bila teknisi punya chat ID.
     *
     * @param  Technician|null $teknisi
     * @param  string          $pesan
     * @return void
     */
    private function kirimKeTeknisi(?Technician $teknisi, string $pesan): void
    {
        if ($teknisi === null || $teknisi->telegram_id === null || $teknisi->telegram_id === '') {
            return;
        }

        $this->telegram->sendMessage($teknisi->telegram_id, $pesan);
    }

    /**
     * Format angka tanpa nol desimal yang tidak perlu.
     *
     * @param  float|int|string $nilai
     * @return string
     */
    private function angka(float|int|string $nilai): string
    {
        $teks = number_format((float) $nilai, 2, ',', '.');

        return rtrim(rtrim($teks, '0'), ',') ?: '0';
    }
}
