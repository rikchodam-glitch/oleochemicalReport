<?php

namespace App\Services\Sparepart;

use App\Models\SpRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Alur penyimpanan dan perubahan status request spare part.
 *
 * Semua transisi memakai syarat status asal supaya request tidak bisa
 * melompat tahap atau diproses dua kali. Notifikasi dikirim setelah data
 * benar-benar berubah.
 */
class SparepartRequestService
{
    public function __construct(private readonly SparepartRequestNotifier $notifier)
    {
    }

    /**
     * Simpan request baru yang dikirim lewat wizard Telegram.
     *
     * Kode request dapat bentrok bila dua teknisi menyimpan pada waktu yang
     * hampir bersamaan; pembuatan diulang beberapa kali sebelum galat
     * diteruskan.
     *
     * @param  Technician            $teknisi Teknisi pemohon
     * @param  array<string, mixed>  $data    Isian wizard
     * @return SpRequest
     */
    public function simpanDariTelegram(Technician $teknisi, array $data): SpRequest
    {
        $percobaan = 0;

        while (true) {
            $percobaan++;

            try {
                $request = DB::transaction(function () use ($teknisi, $data): SpRequest {
                    return SpRequest::query()->create([
                        'kode'              => SpRequest::generateKode(),
                        'technician_id'     => $teknisi->id,
                        'keterangan'        => trim((string) ($data['keterangan'] ?? '')),
                        'deskripsi_ukuran'  => $this->teksOpsional($data['deskripsi_ukuran'] ?? null),
                        'jumlah'            => (float) ($data['jumlah'] ?? 0),
                        'satuan'            => $this->teksOpsional($data['satuan'] ?? null),
                        'foto_path'         => $this->teksOpsional($data['foto_path'] ?? null),
                        'status'            => SpRequest::STATUS_MENUNGGU,
                    ]);
                });
            } catch (QueryException $galat) {
                if ($percobaan < 3 && $this->galatKodeDuplikat($galat)) {
                    continue;
                }

                throw $galat;
            }

            $request->load('technician');
            $this->notifier->kirimDiajukan($request);

            return $request;
        }
    }

    /**
     * Setujui request yang masih menunggu.
     *
     * @param  SpRequest $request
     * @param  User      $aktor
     * @return bool
     */
    public function setujui(SpRequest $request, User $aktor): bool
    {
        $berhasil = SpRequest::query()
            ->whereKey($request->getKey())
            ->where('status', SpRequest::STATUS_MENUNGGU)
            ->update([
                'status'      => SpRequest::STATUS_DISETUJUI,
                'approved_by' => $aktor->id,
                'approved_at' => now(),
            ]);

        return $this->selesaikan($request, $berhasil, SpRequest::STATUS_DISETUJUI);
    }

    /**
     * Tolak request yang masih menunggu.
     *
     * @param  SpRequest $request
     * @param  User      $aktor
     * @param  string    $alasan
     * @return bool
     */
    public function tolak(SpRequest $request, User $aktor, string $alasan): bool
    {
        $berhasil = SpRequest::query()
            ->whereKey($request->getKey())
            ->where('status', SpRequest::STATUS_MENUNGGU)
            ->update([
                'status'        => SpRequest::STATUS_DITOLAK,
                'rejected_by'   => $aktor->id,
                'rejected_at'   => now(),
                'alasan_tolak'  => trim($alasan),
            ]);

        return $this->selesaikan($request, $berhasil, SpRequest::STATUS_DITOLAK);
    }

    /**
     * Isi nomor PR pada request yang sudah disetujui.
     *
     * @param  SpRequest $request
     * @param  User      $aktor
     * @param  string    $nomorPr
     * @return bool
     */
    public function isiPr(SpRequest $request, User $aktor, string $nomorPr): bool
    {
        $berhasil = SpRequest::query()
            ->whereKey($request->getKey())
            ->where('status', SpRequest::STATUS_DISETUJUI)
            ->update([
                'status' => SpRequest::STATUS_KEJAR_PO,
                'pr_no'  => trim($nomorPr),
                'pr_by'  => $aktor->id,
                'pr_at'  => now(),
            ]);

        return $this->selesaikan($request, $berhasil, SpRequest::STATUS_KEJAR_PO);
    }

    /**
     * Isi nomor PO pada request yang sedang menunggu PO.
     *
     * @param  SpRequest $request
     * @param  User      $aktor
     * @param  string    $nomorPo
     * @return bool
     */
    public function isiPo(SpRequest $request, User $aktor, string $nomorPo): bool
    {
        $berhasil = SpRequest::query()
            ->whereKey($request->getKey())
            ->where('status', SpRequest::STATUS_KEJAR_PO)
            ->update([
                'status' => SpRequest::STATUS_KEJAR_DELIVERY,
                'po_no'  => trim($nomorPo),
                'po_by'  => $aktor->id,
                'po_at'  => now(),
            ]);

        return $this->selesaikan($request, $berhasil, SpRequest::STATUS_KEJAR_DELIVERY);
    }

    /**
     * Tandai barang sudah diterima dan tutup request.
     *
     * @param  SpRequest $request
     * @param  User      $aktor
     * @return bool
     */
    public function terima(SpRequest $request, User $aktor): bool
    {
        $berhasil = SpRequest::query()
            ->whereKey($request->getKey())
            ->where('status', SpRequest::STATUS_KEJAR_DELIVERY)
            ->update([
                'status'      => SpRequest::STATUS_SELESAI,
                'received_by' => $aktor->id,
                'received_at' => now(),
            ]);

        return $this->selesaikan($request, $berhasil, SpRequest::STATUS_SELESAI);
    }

    /**
     * Hapus request yang belum punya nomor PR.
     *
     * Request yang sudah masuk Kejar PO, Kejar Delivery, atau Selesai tidak
     * boleh dihapus karena sudah menjadi riwayat pengadaan.
     *
     * @param  SpRequest $request
     * @return bool
     */
    public function hapus(SpRequest $request): bool
    {
        if (! $request->bisaDihapus()) {
            return false;
        }

        $request->delete();

        return true;
    }

    /**
     * Segarkan model dan kirim notifikasi bila perubahan status berhasil.
     *
     * @param  SpRequest $request
     * @param  int       $berhasil Jumlah baris yang berubah
     * @param  string    $tahap    Status baru
     * @return bool
     */
    private function selesaikan(SpRequest $request, int $berhasil, string $tahap): bool
    {
        if ($berhasil < 1) {
            return false;
        }

        $request->refresh()->load('technician');
        $this->notifier->kirimStatus($request, $tahap);

        return true;
    }

    /**
     * Rapikan teks opsional: teks kosong atau tanda hubung dianggap null.
     *
     * @param  mixed $nilai
     * @return string|null
     */
    private function teksOpsional(mixed $nilai): ?string
    {
        $teks = trim((string) $nilai);

        return ($teks === '' || $teks === '-') ? null : $teks;
    }

    /**
     * Apakah galat database disebabkan kode request yang sudah dipakai.
     *
     * @param  QueryException $galat
     * @return bool
     */
    private function galatKodeDuplikat(QueryException $galat): bool
    {
        $pesan = strtolower($galat->getMessage());

        return str_contains($pesan, 'unique')
            || str_contains($pesan, 'duplicate')
            || (string) $galat->getCode() === '23000';
    }
}
