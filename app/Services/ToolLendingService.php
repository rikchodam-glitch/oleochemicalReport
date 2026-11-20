<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan transaksi peminjaman dan pengembalian alat.
 *
 * Semua perubahan status alat dilakukan di dalam transaksi dengan kunci baris
 * supaya dua permintaan bersamaan tidak bisa meminjam unit yang sama.
 */
class ToolLendingService
{
    public function __construct(private ToolLoanNotifier $notifier)
    {
    }

    /**
     * Catat peminjaman satu unit alat.
     *
     * @param  Tool                $alat               Unit yang akan dipinjam
     * @param  array<string,mixed> $peminjam           Data peminjam yang sudah divalidasi
     * @param  string              $jenisPenangan      ToolLoan::HANDLED_*
     * @param  Technician|null     $penanganTeknisi    Keeper yang menyerahkan (bila ada)
     * @param  User|null           $penanganUser       Admin web yang menyerahkan (bila ada)
     * @param  string|null         $catatan            Catatan tambahan
     * @return ToolLoan
     *
     * @throws ValidationException Bila alat tidak tersedia atau sudah dipinjam
     */
    public function pinjamkan(
        Tool $alat,
        array $peminjam,
        string $jenisPenangan,
        ?Technician $penanganTeknisi = null,
        ?User $penanganUser = null,
        ?string $catatan = null
    ): ToolLoan {
        $pinjaman = DB::transaction(function () use (
            $alat,
            $peminjam,
            $jenisPenangan,
            $penanganTeknisi,
            $penanganUser,
            $catatan
        ): ToolLoan {
            $terkunci = Tool::query()->lockForUpdate()->findOrFail($alat->id);

            if ($terkunci->status === Tool::STATUS_RUSAK) {
                throw ValidationException::withMessages([
                    'tool_id' => 'Alat ' . $terkunci->name . ' (' . $terkunci->code . ') sedang rusak.',
                ]);
            }

            if ($terkunci->status !== Tool::STATUS_TERSEDIA
                || $terkunci->loans()->whereNull('returned_at')->exists()) {
                throw ValidationException::withMessages([
                    'tool_id' => 'Alat ' . $terkunci->name . ' (' . $terkunci->code . ') sedang dipakai.',
                ]);
            }

            $pinjaman = ToolLoan::create([
                'kode'                      => ToolLoan::generateKode(),
                'tool_id'                   => $terkunci->id,
                'tool_name'                 => $terkunci->name,
                'tool_code'                 => $terkunci->code,
                'borrower_type'             => $peminjam['borrower_type'],
                'technician_id'             => $peminjam['technician_id'] ?? null,
                'borrower_name'             => $peminjam['borrower_name'],
                'borrower_department_id'    => $peminjam['borrower_department_id'] ?? null,
                'borrower_department_nama'  => $peminjam['borrower_department_nama'] ?? null,
                'borrowed_at'               => now(),
                'handled_by_type'           => $jenisPenangan,
                'handled_by_technician_id'  => $penanganTeknisi?->id,
                'handled_by_user_id'        => $penanganUser?->id,
                'catatan'                   => $catatan,
            ]);

            $terkunci->update(['status' => Tool::STATUS_DIPAKAI]);

            return $pinjaman->load(['technician', 'tool', 'borrowerDepartment']);
        });

        $this->notifier->kirimDipinjam($pinjaman);

        return $pinjaman;
    }

    /**
     * Catat pengembalian satu pinjaman.
     *
     * @param  ToolLoan        $pinjaman       Pinjaman yang dikembalikan
     * @param  string          $jenisPengembali ToolLoan::HANDLED_*
     * @param  Technician|null $teknisi         Penerima/pengembali teknisi
     * @param  User|null       $user            Penerima admin web
     * @return ToolLoan
     */
    public function kembalikan(
        ToolLoan $pinjaman,
        string $jenisPengembali,
        ?Technician $teknisi = null,
        ?User $user = null
    ): ToolLoan {
        $baruDikembalikan = false;

        $hasil = DB::transaction(function () use (
            $pinjaman,
            $jenisPengembali,
            $teknisi,
            $user,
            &$baruDikembalikan
        ): ToolLoan {
            $terkunci = ToolLoan::query()->lockForUpdate()->findOrFail($pinjaman->id);

            if ($terkunci->returned_at !== null) {
                return $terkunci;
            }

            $terkunci->update([
                'returned_at'                => now(),
                'returned_by_type'           => $jenisPengembali,
                'returned_by_technician_id'  => $teknisi?->id,
                'returned_by_user_id'        => $user?->id,
            ]);

            if ($terkunci->tool_id !== null) {
                Tool::query()
                    ->whereKey($terkunci->tool_id)
                    ->where('status', Tool::STATUS_DIPAKAI)
                    ->update(['status' => Tool::STATUS_TERSEDIA]);
            }

            $baruDikembalikan = true;

            return $terkunci->load(['technician', 'tool', 'borrowerDepartment']);
        });

        if ($baruDikembalikan) {
            $this->notifier->kirimDikembalikan($hasil);
        }

        return $hasil;
    }

    /**
     * Kembalikan semua pinjaman aktif satu teknisi.
     *
     * @return int Jumlah pinjaman yang benar-benar dikembalikan
     */
    public function kembalikanSemua(Technician $teknisi, string $jenisPengembali): int
    {
        $daftar = ToolLoan::query()
            ->where('technician_id', $teknisi->id)
            ->aktif()
            ->orderBy('id')
            ->get();

        $jumlah = 0;

        foreach ($daftar as $pinjaman) {
            $hasil = $this->kembalikan($pinjaman, $jenisPengembali, $teknisi);
            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * Susun data peminjam teknisi untuk disimpan pada pinjaman.
     *
     * @return array<string,mixed>
     */
    public function dataPeminjamTeknisi(Technician $teknisi): array
    {
        $teknisi->loadMissing('department');

        return [
            'borrower_type'            => ToolLoan::BORROWER_TEKNISI,
            'technician_id'            => $teknisi->id,
            'borrower_name'            => $teknisi->name,
            'borrower_department_id'   => $teknisi->department_id,
            'borrower_department_nama' => $teknisi->department?->name,
        ];
    }

    /**
     * Susun data peminjam non-teknisi (nama bebas + departemen pilihan).
     *
     * @return array<string,mixed>
     */
    public function dataPeminjamNonTeknisi(string $nama, ?int $departmentId, ?string $departmentNama): array
    {
        return [
            'borrower_type'            => ToolLoan::BORROWER_NON_TEKNISI,
            'technician_id'            => null,
            'borrower_name'            => $nama,
            'borrower_department_id'   => $departmentId,
            'borrower_department_nama' => $departmentNama,
        ];
    }
}
