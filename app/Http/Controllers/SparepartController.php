<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\SparepartExportTrait;
use App\Models\SpBatch;
use App\Models\SpIntegrasi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman modul Sparepart (Integrasi PR/PO).
 *
 * Halaman ini HANYA MEMBACA hasil import. Proses import sendiri tetap
 * dijalankan lewat perintah artisan sparepart:import supaya pekerjaan berat
 * (baca lima berkas sumber) tidak berjalan di dalam request HTTP.
 *
 * Terdapat dua layar:
 * 1. daftar periode (index) beserta ringkasan periode terbaru,
 * 2. rincian per material satu periode (show) beserta ekspor Excel-nya.
 *
 * Aturan barisnya sama dengan tabel sp_integrasi: satu baris mewakili satu
 * kode material pada satu batch (satu kali import).
 */
class SparepartController extends Controller
{
    use SparepartExportTrait;

    /** Jumlah baris rincian yang ditampilkan per halaman. */
    private const BARIS_PER_HALAMAN = 50;

    /** Nilai saringan kolom reorder yang dikenal halaman rincian. */
    private const SARINGAN_REORDER = ['ya', 'tidak'];

    /**
     * Tampilkan daftar periode yang pernah diimport beserta ringkasan periode
     * terbaru.
     *
     * Urutan memakai id menurun, bukan kolom periode, karena kolom periode
     * berisi teks bebas (mis. "07 Okt 26") sehingga tidak bisa diurutkan
     * secara kronologis.
     *
     * @return View
     */
    public function index(): View
    {
        $terbaru   = SpBatch::query()->orderByDesc('id')->first();
        $ringkasan = $terbaru?->ringkasan ?? [];

        return view('sparepart.index', [
            'batches'   => SpBatch::query()->with('pengguna')->orderByDesc('id')->paginate(15)->withQueryString(),
            'terbaru'   => $terbaru,
            'ringkasan' => $ringkasan,
            'kartuAksi' => $this->kartuAksi($ringkasan),
        ]);
    }

    /**
     * Tampilkan rincian hasil integrasi satu periode.
     *
     * @param  SpBatch $batch   Batch yang dibuka (route model binding)
     * @param  Request $request Permintaan HTTP (saringan dan nomor halaman)
     * @return View
     */
    public function show(SpBatch $batch, Request $request): View
    {
        $saringan = $this->saringanRincian($request);

        return view('sparepart.show', [
            'batch'         => $batch,
            'saringan'      => $saringan,
            'rincian'       => $this->queryRincian($batch, $saringan)
                ->orderByDesc('reorder')
                ->orderBy('kode_material')
                ->paginate(self::BARIS_PER_HALAMAN)
                ->withQueryString(),
            'rekapAksi'     => $this->rekapAksi($batch),
            'jumlahReorder' => (int) SpIntegrasi::query()
                ->where('batch_id', $batch->id)
                ->where('reorder', true)
                ->count(),
        ]);
    }

    /**
     * Unduh rincian hasil integrasi satu periode sebagai berkas Excel.
     *
     * Saringan yang dipakai sama dengan layar rincian, jadi berkas yang diunduh
     * selalu berisi baris yang sedang dilihat pengguna.
     *
     * @param  SpBatch $batch   Batch yang diekspor (route model binding)
     * @param  Request $request Permintaan HTTP (saringan)
     * @return StreamedResponse
     */
    public function export(SpBatch $batch, Request $request): StreamedResponse
    {
        $saringan = $this->saringanRincian($request);

        $baris = $this->queryRincian($batch, $saringan)
            ->orderByDesc('reorder')
            ->orderBy('kode_material')
            ->get();

        return $this->exportRincianExcel($batch, $baris, $this->labelSaringan($saringan));
    }

    /**
     * Saringan halaman rincian yang sudah divalidasi.
     *
     * Nilai yang tidak dikenal (mis. aksi karangan) sengaja diabaikan supaya
     * tautan yang salah ketik tidak menghasilkan halaman kosong yang
     * menyesatkan.
     *
     * @param  Request $request Permintaan HTTP
     * @return array<string, string> Saringan: search, aksi, reorder
     */
    private function saringanRincian(Request $request): array
    {
        $search  = trim((string) $request->query('search', ''));
        $aksi    = (string) $request->query('aksi', '');
        $reorder = (string) $request->query('reorder', '');

        return [
            'search'  => mb_substr($search, 0, 100),
            'aksi'    => in_array($aksi, SpIntegrasi::AKSI, true) ? $aksi : '',
            'reorder' => in_array($reorder, self::SARINGAN_REORDER, true) ? $reorder : '',
        ];
    }

    /**
     * Query rincian satu batch sesuai saringan layar.
     *
     * @param  SpBatch               $batch    Batch yang dibaca
     * @param  array<string, string> $saringan Saringan hasil saringanRincian()
     * @return Builder<SpIntegrasi>
     */
    private function queryRincian(SpBatch $batch, array $saringan): Builder
    {
        $query = SpIntegrasi::query()->where('batch_id', $batch->id);

        if ($saringan['search'] !== '') {
            $kunci = $saringan['search'];

            $query->where(function (Builder $sub) use ($kunci): void {
                $sub->where('kode_material', 'like', '%' . $kunci . '%')
                    ->orWhere('deskripsi', 'like', '%' . $kunci . '%')
                    ->orWhere('pr_no', 'like', '%' . $kunci . '%')
                    ->orWhere('po_no', 'like', '%' . $kunci . '%')
                    ->orWhere('vendor', 'like', '%' . $kunci . '%');
            });
        }

        if ($saringan['aksi'] !== '') {
            $query->where('aksi', $saringan['aksi']);
        }

        if ($saringan['reorder'] === 'ya') {
            $query->where('reorder', true);
        } elseif ($saringan['reorder'] === 'tidak') {
            $query->where('reorder', false);
        }

        return $query;
    }

    /**
     * Jumlah material per nilai kolom aksi pada satu batch.
     *
     * @param  SpBatch $batch Batch yang dihitung
     * @return array<string, int> Peta nilai aksi => jumlah baris
     */
    private function rekapAksi(SpBatch $batch): array
    {
        $rekap = SpIntegrasi::query()
            ->where('batch_id', $batch->id)
            ->groupBy('aksi')
            ->selectRaw('aksi, COUNT(*) AS jumlah')
            ->pluck('jumlah', 'aksi');

        $hasil = [];

        foreach (SpIntegrasi::AKSI as $nilai) {
            $hasil[$nilai] = (int) ($rekap[$nilai] ?? 0);
        }

        return $hasil;
    }

    /**
     * Kartu ringkasan aksi untuk halaman daftar periode.
     *
     * @param  array<string, mixed> $ringkasan Ringkasan batch terbaru
     * @return array<int, array<string, mixed>> Daftar kartu (label, nilai, jumlah)
     */
    private function kartuAksi(array $ringkasan): array
    {
        $aksi  = (array) ($ringkasan['aksi'] ?? []);
        $kartu = [];

        foreach (SpIntegrasi::AKSI as $nilai) {
            $kartu[] = [
                'label'  => SpIntegrasi::labelAksi($nilai),
                'nilai'  => $nilai,
                'jumlah' => (int) ($aksi[$nilai] ?? 0),
            ];
        }

        return $kartu;
    }

    /**
     * Keterangan saringan untuk baris judul berkas Excel.
     *
     * @param  array<string, string> $saringan Saringan halaman rincian
     * @return string                          Contoh: "Aksi: BUAT PO | Hanya material REORDER"
     */
    private function labelSaringan(array $saringan): string
    {
        $bagian = [];

        if ($saringan['aksi'] !== '') {
            $bagian[] = 'Aksi: ' . $saringan['aksi'];
        }

        if ($saringan['reorder'] === 'ya') {
            $bagian[] = 'Hanya material REORDER';
        }

        if ($saringan['reorder'] === 'tidak') {
            $bagian[] = 'Hanya material stok aman';
        }

        if ($saringan['search'] !== '') {
            $bagian[] = 'Pencarian: ' . $saringan['search'];
        }

        return $bagian === [] ? '(tanpa saringan)' : implode(' | ', $bagian);
    }
}
