<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\SparepartExportTrait;
use App\Models\SpImpor;
use App\Models\SpMaterial;
use App\Models\SpPantau;
use App\Services\Sparepart\SparepartImportService;
use App\Services\Sparepart\SparepartPantauService;
use App\Services\Sparepart\SparepartUnggahan;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Halaman modul Spare Part (Integrasi PR/PO).
 *
 * Halaman awal LANGSUNG berupa tabel keadaan seluruh material daftar pantau
 * (satu baris per kode material, nilainya selalu mutakhir karena setiap import
 * memperbarui baris yang sama). Halaman lain: import berkas, daftar pantau
 * (sumber filter/populasi), dan riwayat import beserta analisanya.
 */
class SparepartController extends Controller
{
    use SparepartExportTrait;

    /** Jumlah baris tabel keadaan yang ditampilkan per halaman. */
    private const BARIS_PER_HALAMAN = 50;

    /**
     * Nilai saringan status stok yang dikenal halaman.
     *
     * @var array<string, string>
     */
    public const STATUS_SARING = [
        'reorder' => 'Perlu reorder (REORDER)',
        'aman'    => 'Stok aman',
        'belum'   => 'Belum MRP (tanpa data MRP)',
    ];

    /**
     * Nilai saringan "punya data" yang dikenal halaman.
     *
     * @var array<string, string>
     */
    public const PUNYA_SARING = [
        'stok' => 'Punya catatan stok',
        'pr'   => 'Punya PR',
        'po'   => 'Punya PO',
        'mrp'  => 'Punya data MRP',
    ];

    /**
     * Nilai saringan kolom PR pada header tabel.
     *
     * @var array<string, string>
     */
    public const PR_SARING = [
        'punya'         => 'Punya PR',
        'terbuka'       => 'Ada PR terbuka',
        'tanpa'         => 'Tanpa PR',
        'tanpa_terbuka' => 'Tanpa PR terbuka',
    ];

    /**
     * Nilai saringan kolom PO pada header tabel.
     *
     * @var array<string, string>
     */
    public const PO_SARING = [
        'punya'         => 'Punya PO',
        'terbuka'       => 'Ada PO terbuka',
        'tanpa'         => 'Tanpa PO',
        'tanpa_terbuka' => 'Tanpa PO terbuka',
    ];

    /**
     * Nilai saringan kolom MRP pada header tabel.
     *
     * @var array<string, string>
     */
    public const MRP_SARING = [
        'punya' => 'Punya data MRP',
        'tanpa' => 'Belum ada di MRP',
        'sama'  => 'Stok Sama',
        'beda'  => 'Stok Beda',
    ];

    /**
     * Halaman utama: tabel keadaan seluruh material daftar pantau.
     *
     * @param  Request $request Permintaan HTTP (saringan dan nomor halaman)
     * @return View             Halaman tabel keadaan
     */
    public function index(Request $request): View
    {
        $saringan = $this->saringan($request);

        return view('sparepart.index', [
            'saringan'  => $saringan,
            'baris'     => $this->queryKeadaan($saringan)->paginate(self::BARIS_PER_HALAMAN)->withQueryString(),
            'ringkasan' => $this->ringkasan(),
            'kartuAksi' => $this->kartuAksi(),
            'terakhir'  => SpImpor::query()->orderByDesc('id')->first(),
        ]);
    }

    /**
     * Mengunduh tabel keadaan (mengikuti saringan yang sedang dipakai) sebagai Excel.
     *
     * @param  Request $request Permintaan HTTP (saringan)
     * @return StreamedResponse Berkas Excel
     */
    public function export(Request $request): StreamedResponse
    {
        $saringan = $this->saringan($request);

        return $this->exportKeadaanExcel(
            $this->queryKeadaan($saringan)->get(),
            $this->labelSaringan($saringan),
            $this->ringkasan()
        );
    }

    /**
     * Halaman unggah berkas import.
     *
     * @return View Halaman form unggah
     */
    public function impor(): View
    {
        return view('sparepart.impor', [
            'terakhir'     => SpImpor::query()->orderByDesc('id')->first(),
            'jumlahPantau' => SpPantau::query()->where('aktif', true)->count(),
        ]);
    }

    /**
     * Memproses unggahan berkas import.
     *
     * Berkas disimpan sementara di storage lalu dibaca layanan import; berkas
     * sementara selalu dihapus lagi setelah dipakai (termasuk saat gagal).
     *
     * @param  Request                $request Permintaan HTTP (berkas unggahan)
     * @param  SparepartImportService $layanan Layanan import keadaan material
     * @param  SparepartPantauService $pantau  Layanan daftar pantau
     * @return RedirectResponse                 Pengalihan ke riwayat import
     */
    public function imporProses(
        Request $request,
        SparepartImportService $layanan,
        SparepartPantauService $pantau
    ): RedirectResponse {
        $request->validate([
            'stok'        => ['nullable', 'file', 'mimes:xlsx,xls', 'max:51200'],
            'pr'          => ['nullable', 'file', 'mimes:xlsx,xls', 'max:51200'],
            'po'          => ['nullable', 'file', 'mimes:xlsx,xls', 'max:51200'],
            'mrp'         => ['nullable', 'file', 'mimes:xlsx,xls', 'max:51200'],
            'pantau'      => ['nullable', 'file', 'mimes:xlsx,xls,csv', 'max:51200'],
            'label'       => ['nullable', 'string', 'max:120'],
            'mode_pantau' => ['nullable', 'in:gabung,ganti'],
        ], [], [
            'stok'   => 'berkas stok gudang',
            'pr'     => 'berkas EXPORT PR',
            'po'     => 'berkas EXPORT PO',
            'mrp'    => 'berkas EXPORT MRP',
            'pantau' => 'berkas daftar pantau',
        ]);

        if (! $request->hasFile('stok') && ! $request->hasFile('pr')
            && ! $request->hasFile('po') && ! $request->hasFile('mrp')) {
            return back()
                ->withInput()
                ->with('error', 'Pilih minimal satu berkas sumber (stok gudang, EXPORT PR, EXPORT PO, atau EXPORT MRP).');
        }

        $tersimpan     = [];
        $catatanPantau = null;

        try {
            foreach (['stok', 'pr', 'po', 'mrp', 'pantau'] as $kunci) {
                if ($request->hasFile($kunci)) {
                    $tersimpan[$kunci] = $this->simpanSementara($request->file($kunci), $kunci);
                }
            }

            if (isset($tersimpan['pantau'])) {
                $catatanPantau = $pantau->imporDariBerkas(
                    $tersimpan['pantau'],
                    $request->input('mode_pantau') === SparepartPantauService::MODE_GANTI
                        ? SparepartPantauService::MODE_GANTI
                        : SparepartPantauService::MODE_GABUNG
                );
            }

            $impor = $layanan->import(
                [
                    SparepartImportService::SUMBER_STOK => $tersimpan['stok'] ?? null,
                    SparepartImportService::SUMBER_PR   => $tersimpan['pr'] ?? null,
                    SparepartImportService::SUMBER_PO   => $tersimpan['po'] ?? null,
                    SparepartImportService::SUMBER_MRP  => $tersimpan['mrp'] ?? null,
                ],
                $request->user()?->id,
                (string) ($request->input('label') ?? '') ?: null
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Import dibatalkan: ' . $e->getMessage());
        } finally {
            foreach ($tersimpan as $path) {
                @unlink($path);
            }
        }

        $pesan = 'Import selesai. Rincian analisanya ada di halaman ini.';

        if (is_array($catatanPantau)) {
            $pesan = 'Daftar pantau: ' . $catatanPantau['kode'] . ' kode (baru ' . $catatanPantau['baru']
                . ', diperbarui ' . $catatanPantau['diperbarui'] . ', dihapus ' . $catatanPantau['dihapus'] . '). ' . $pesan;
        }

        return redirect()
            ->route('sparepart.riwayat', ['impor' => $impor->id])
            ->with('success', $pesan);
    }

    /**
     * Riwayat import beserta analisanya.
     *
     * @param  Request $request Permintaan HTTP (opsional: id import yang dibuka)
     * @return View             Halaman riwayat import
     */
    public function riwayat(Request $request): View
    {
        $dibuka = null;

        if ($request->filled('impor')) {
            $dibuka = SpImpor::query()->with('pengguna')->find((int) $request->input('impor'));
        }

        return view('sparepart.riwayat', [
            'daftar' => SpImpor::query()->with('pengguna')->orderByDesc('id')->paginate(10)->withQueryString(),
            'dibuka' => $dibuka,
        ]);
    }

    /**
     * Membaca saringan halaman dari permintaan HTTP.
     *
     * @param  Request $request Permintaan HTTP
     * @return array<string, string> Saringan yang sudah dibersihkan
     */
    private function saringan(Request $request): array
    {
        $aksi   = (string) $request->input('aksi', '');
        $status = (string) $request->input('status', '');
        $punya  = (string) $request->input('punya', '');
        $pr     = (string) $request->input('pr', '');
        $po     = (string) $request->input('po', '');
        $mrp    = (string) $request->input('mrp', '');
        $urut   = (string) $request->input('urut', 'reorder');

        $angka = static function (mixed $nilai): string {
            $teks = trim((string) $nilai);

            return $teks !== '' && is_numeric($teks) ? $teks : '';
        };

        $tanggal = static function (mixed $nilai): string {
            $teks = trim((string) $nilai);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $teks) === 1 ? $teks : '';
        };

        return [
            'q'            => trim((string) $request->input('q', '')),
            'kode'         => trim((string) $request->input('kode', '')),
            'deskripsi'    => trim((string) $request->input('deskripsi', '')),
            'aksi'         => in_array($aksi, SpMaterial::AKSI, true) ? $aksi : '',
            'status'       => array_key_exists($status, self::STATUS_SARING) ? $status : '',
            'punya'        => array_key_exists($punya, self::PUNYA_SARING) ? $punya : '',
            'pr'           => array_key_exists($pr, self::PR_SARING) ? $pr : '',
            'po'           => array_key_exists($po, self::PO_SARING) ? $po : '',
            'mrp'          => array_key_exists($mrp, self::MRP_SARING) ? $mrp : '',
            'min_stok_min' => $angka($request->input('min_stok_min', '')),
            'max_stok_min' => $angka($request->input('max_stok_min', '')),
            'min_stok'     => $angka($request->input('min_stok', '')),
            'max_stok'     => $angka($request->input('max_stok', '')),
            'purch'        => trim((string) $request->input('purch', '')),
            'vendor'       => trim((string) $request->input('vendor', '')),
            'dari'         => $tanggal($request->input('dari', '')),
            'sampai'       => $tanggal($request->input('sampai', '')),
            'urut'         => in_array($urut, ['reorder', 'kode', 'stok', 'aksi', 'terakhir'], true) ? $urut : 'reorder',
        ];
    }

    /**
     * Query tabel keadaan: daftar pantau digabung keadaan materialnya.
     *
     * Kolom keadaan diberi awalan m_ supaya tidak bertabrakan dengan kolom
     * daftar pantau (keduanya punya deskripsi dan stok_min).
     *
     * @param  array<string, string> $saringan Saringan halaman
     * @return QueryBuilder                    Query siap ditampilkan
     */
    private function queryKeadaan(array $saringan): QueryBuilder
    {
        $query = DB::table('sp_pantau')
            ->leftJoin('sp_material', 'sp_material.kode_material', '=', 'sp_pantau.kode_material')
            ->where('sp_pantau.aktif', true)
            ->select([
                'sp_pantau.kode_material',
                'sp_pantau.deskripsi as deskripsi_pantau',
                'sp_pantau.plant',
                'sp_pantau.model',
                'sp_pantau.tag_no',
                'sp_pantau.uom',
                'sp_pantau.sumber',
                'sp_material.id as m_id',
                'sp_material.deskripsi as m_deskripsi',
                'sp_material.stok_min as m_stok_min',
                'sp_material.stok_tersedia as m_stok_tersedia',
                'sp_material.reorder as m_reorder',
                'sp_material.penanda as m_penanda',
                'sp_material.aksi as m_aksi',
                'sp_material.pr_no as m_pr_no',
                'sp_material.pr_status_aksi as m_pr_status_aksi',
                'sp_material.pr_status as m_pr_status',
                'sp_material.pr_qty as m_pr_qty',
                'sp_material.pr_line as m_pr_line',
                'sp_material.pr_terbuka as m_pr_terbuka',
                'sp_material.pr_open_nums as m_pr_open_nums',
                'sp_material.pr_nums as m_pr_nums',
                'sp_material.req_list as m_req_list',
                'sp_material.pr_history as m_pr_history',
                'sp_material.po_no as m_po_no',
                'sp_material.po_sisa as m_po_sisa',
                'sp_material.po_line as m_po_line',
                'sp_material.po_uniq as m_po_uniq',
                'sp_material.po_closed as m_po_closed',
                'sp_material.po_open as m_po_open',
                'sp_material.po_open_nums as m_po_open_nums',
                'sp_material.po_sisa_total as m_po_sisa_total',
                'sp_material.po_tanggal_teks as m_po_tanggal_teks',
                'sp_material.po_nums as m_po_nums',
                'sp_material.purch as m_purch',
                'sp_material.vendor as m_vendor',
                'sp_material.po_prev as m_po_prev',
                'sp_material.vendor_prev as m_vendor_prev',
                'sp_material.purch_last as m_purch_last',
                'sp_material.vendor_last as m_vendor_last',
                'sp_material.g_baris as m_g_baris',
                'sp_material.g_awal as m_g_awal',
                'sp_material.g_masuk as m_g_masuk',
                'sp_material.g_keluar as m_g_keluar',
                'sp_material.g_sisa as m_g_sisa',
                'sp_material.g_satuan as m_g_satuan',
                'sp_material.g_lokasi as m_g_lokasi',
                'sp_material.mrp_stock as m_mrp_stock',
                'sp_material.mrp_safety as m_mrp_safety',
                'sp_material.mrp_reorder as m_mrp_reorder',
                'sp_material.mrp_unit as m_mrp_unit',
                'sp_material.mrp_type as m_mrp_type',
                'sp_material.mrp_cmp as m_mrp_cmp',
                'sp_material.ket as m_ket',
                'sp_material.stok_diupdate_at as m_stok_diupdate_at',
                'sp_material.pr_diupdate_at as m_pr_diupdate_at',
                'sp_material.po_diupdate_at as m_po_diupdate_at',
                'sp_material.mrp_diupdate_at as m_mrp_diupdate_at',
                'sp_material.updated_at as m_diubah_at',
            ]);

        if ($saringan['q'] !== '') {
            $kunci = $saringan['q'];

            $query->where(function (QueryBuilder $sub) use ($kunci): void {
                $sub->where('sp_pantau.kode_material', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_pantau.deskripsi', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.deskripsi', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.pr_no', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.pr_nums', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.pr_open_nums', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.po_no', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.po_nums', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.po_open_nums', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.vendor', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.mrp_type', 'like', '%' . $kunci . '%');
            });
        }

        if ($saringan['kode'] !== '') {
            $query->where('sp_pantau.kode_material', 'like', '%' . $saringan['kode'] . '%');
        }

        if ($saringan['deskripsi'] !== '') {
            $kunci = $saringan['deskripsi'];

            $query->where(function (QueryBuilder $sub) use ($kunci): void {
                $sub->where('sp_pantau.deskripsi', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.deskripsi', 'like', '%' . $kunci . '%');
            });
        }

        if ($saringan['aksi'] !== '') {
            $query->where('sp_material.aksi', $saringan['aksi']);
        }

        if ($saringan['status'] === 'reorder') {
            $query->where('sp_material.reorder', true);
        } elseif ($saringan['status'] === 'aman') {
            $query->whereNotNull('sp_material.stok_min')->where('sp_material.reorder', false);
        } elseif ($saringan['status'] === 'belum') {
            $query->whereNull('sp_material.stok_min');
        }

        if ($saringan['punya'] === 'stok') {
            $query->whereNotNull('sp_material.stok_tersedia');
        } elseif ($saringan['punya'] === 'pr') {
            $query->where('sp_material.pr_line', '>', 0);
        } elseif ($saringan['punya'] === 'po') {
            $query->where('sp_material.po_line', '>', 0);
        } elseif ($saringan['punya'] === 'mrp') {
            $query->whereNotNull('sp_material.mrp_safety');
        }

        if ($saringan['min_stok_min'] !== '') {
            $query->where('sp_material.stok_min', '>=', (float) $saringan['min_stok_min']);
        }

        if ($saringan['max_stok_min'] !== '') {
            $query->where('sp_material.stok_min', '<=', (float) $saringan['max_stok_min']);
        }

        if ($saringan['min_stok'] !== '') {
            $query->where('sp_material.stok_tersedia', '>=', (float) $saringan['min_stok']);
        }

        if ($saringan['max_stok'] !== '') {
            $query->where('sp_material.stok_tersedia', '<=', (float) $saringan['max_stok']);
        }

        if ($saringan['pr'] === 'punya') {
            $query->where('sp_material.pr_line', '>', 0);
        } elseif ($saringan['pr'] === 'terbuka') {
            $query->where('sp_material.pr_terbuka', '>', 0);
        } elseif ($saringan['pr'] === 'tanpa') {
            $query->where('sp_material.pr_line', 0);
        } elseif ($saringan['pr'] === 'tanpa_terbuka') {
            $query->where('sp_material.pr_terbuka', 0);
        }

        if ($saringan['po'] === 'punya') {
            $query->where('sp_material.po_line', '>', 0);
        } elseif ($saringan['po'] === 'terbuka') {
            $query->where('sp_material.po_open', '>', 0);
        } elseif ($saringan['po'] === 'tanpa') {
            $query->where('sp_material.po_line', 0);
        } elseif ($saringan['po'] === 'tanpa_terbuka') {
            $query->where('sp_material.po_open', 0);
        }

        if ($saringan['mrp'] === 'punya') {
            $query->whereNotNull('sp_material.mrp_safety');
        } elseif ($saringan['mrp'] === 'tanpa') {
            $query->whereNull('sp_material.mrp_safety');
        } elseif ($saringan['mrp'] === 'sama') {
            $query->where('sp_material.mrp_cmp', 'Sama');
        } elseif ($saringan['mrp'] === 'beda') {
            $query->where('sp_material.mrp_cmp', 'Beda');
        }

        if ($saringan['purch'] !== '') {
            $kunci = $saringan['purch'];

            $query->where(function (QueryBuilder $sub) use ($kunci): void {
                $sub->where('sp_material.purch', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.purch_last', 'like', '%' . $kunci . '%');
            });
        }

        if ($saringan['vendor'] !== '') {
            $kunci = $saringan['vendor'];

            $query->where(function (QueryBuilder $sub) use ($kunci): void {
                $sub->where('sp_material.vendor', 'like', '%' . $kunci . '%')
                    ->orWhere('sp_material.vendor_last', 'like', '%' . $kunci . '%');
            });
        }

        if ($saringan['dari'] !== '') {
            $query->whereDate('sp_material.updated_at', '>=', $saringan['dari']);
        }

        if ($saringan['sampai'] !== '') {
            $query->whereDate('sp_material.updated_at', '<=', $saringan['sampai']);
        }

        return match ($saringan['urut']) {
            'kode'     => $query->orderBy('sp_pantau.kode_material'),
            'stok'     => $query->orderByRaw('sp_material.stok_tersedia is null, sp_material.stok_tersedia asc'),
            'aksi'     => $query->orderBy('sp_material.aksi')->orderBy('sp_pantau.kode_material'),
            'terakhir' => $query->orderByDesc('sp_material.updated_at'),
            default    => $query->orderByDesc('sp_material.reorder')->orderBy('sp_pantau.kode_material'),
        };
    }

    /**
     * Ringkasan angka halaman: jumlah per status stok dan per kepemilikan data.
     *
     * @return array<string, int> Peta label => jumlah
     */
    private function ringkasan(): array
    {
        $baris = DB::table('sp_pantau')
            ->leftJoin('sp_material', 'sp_material.kode_material', '=', 'sp_pantau.kode_material')
            ->where('sp_pantau.aktif', true)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN sp_material.stok_min IS NULL THEN 1 ELSE 0 END) AS belum')
            ->selectRaw('SUM(CASE WHEN sp_material.stok_min IS NOT NULL AND sp_material.reorder = 1 THEN 1 ELSE 0 END) AS reorder')
            ->selectRaw('SUM(CASE WHEN sp_material.stok_min IS NOT NULL AND sp_material.reorder = 0 THEN 1 ELSE 0 END) AS aman')
            ->selectRaw('SUM(CASE WHEN sp_material.stok_tersedia IS NULL THEN 1 ELSE 0 END) AS tanpa_stok')
            ->selectRaw('SUM(CASE WHEN sp_material.pr_line > 0 THEN 1 ELSE 0 END) AS punya_pr')
            ->selectRaw('SUM(CASE WHEN sp_material.po_line > 0 THEN 1 ELSE 0 END) AS punya_po')
            ->selectRaw('SUM(CASE WHEN sp_material.mrp_safety IS NOT NULL THEN 1 ELSE 0 END) AS punya_mrp')
            ->first();

        return [
            'total'      => (int) ($baris->total ?? 0),
            'reorder'    => (int) ($baris->reorder ?? 0),
            'aman'       => (int) ($baris->aman ?? 0),
            'belum'      => (int) ($baris->belum ?? 0),
            'tanpa_stok' => (int) ($baris->tanpa_stok ?? 0),
            'punya_pr'   => (int) ($baris->punya_pr ?? 0),
            'punya_po'   => (int) ($baris->punya_po ?? 0),
            'punya_mrp'  => (int) ($baris->punya_mrp ?? 0),
        ];
    }

    /**
     * Jumlah material per nilai kolom aksi, dihitung hanya dari item REORDER.
     *
     * Lingkup ini disamakan dengan sheet "Daftar Aksi" dashboard lama: angka
     * 25/51/45/28 di sana juga hanya mencakup 149 item REORDER (fokus tindakan),
     * bukan seluruh daftar pantau. Material yang stoknya masih aman tidak ikut
     * dihitung supaya angka kartu tidak didominasi pekerjaan yang belum perlu
     * dilakukan (permintaan user 12/10/2026).
     *
     * @return array<int, array<string, mixed>> Kartu aksi (nilai, label, keterangan, jumlah)
     */
    private function kartuAksi(): array
    {
        $rekap = DB::table('sp_material')
            ->where('reorder', true)
            ->groupBy('aksi')
            ->selectRaw('aksi, COUNT(*) AS jumlah')
            ->pluck('jumlah', 'aksi');

        $kartu = [];

        foreach (SpMaterial::AKSI as $nilai) {
            $kartu[] = [
                'nilai'      => $nilai,
                'label'      => SpMaterial::labelAksi($nilai),
                'keterangan' => SpMaterial::keteranganAksi($nilai),
                'jumlah'     => (int) ($rekap[$nilai] ?? 0),
            ];
        }

        return $kartu;
    }

    /**
     * Keterangan saringan untuk baris judul berkas Excel.
     *
     * @param  array<string, string> $saringan Saringan halaman
     * @return string                          Contoh: "Aksi: Kejar PO | Pencarian: seal"
     */
    private function labelSaringan(array $saringan): string
    {
        $bagian = [];

        if ($saringan['kode'] !== '') {
            $bagian[] = 'Kode: ' . $saringan['kode'];
        }

        if ($saringan['deskripsi'] !== '') {
            $bagian[] = 'Deskripsi: ' . $saringan['deskripsi'];
        }

        if ($saringan['aksi'] !== '') {
            $bagian[] = 'Aksi: ' . SpMaterial::labelAksi($saringan['aksi']);
        }

        if ($saringan['status'] !== '') {
            $bagian[] = 'Status: ' . self::STATUS_SARING[$saringan['status']];
        }

        if ($saringan['punya'] !== '') {
            $bagian[] = self::PUNYA_SARING[$saringan['punya']];
        }

        if ($saringan['pr'] !== '') {
            $bagian[] = 'PR: ' . self::PR_SARING[$saringan['pr']];
        }

        if ($saringan['po'] !== '') {
            $bagian[] = 'PO: ' . self::PO_SARING[$saringan['po']];
        }

        if ($saringan['mrp'] !== '') {
            $bagian[] = 'MRP: ' . self::MRP_SARING[$saringan['mrp']];
        }

        if ($saringan['min_stok_min'] !== '' || $saringan['max_stok_min'] !== '') {
            $bagian[] = 'Stok Min: ' . ($saringan['min_stok_min'] !== '' ? $saringan['min_stok_min'] : '0')
                . '-' . ($saringan['max_stok_min'] !== '' ? $saringan['max_stok_min'] : 'tak terbatas');
        }

        if ($saringan['min_stok'] !== '' || $saringan['max_stok'] !== '') {
            $bagian[] = 'Stok Gudang: ' . ($saringan['min_stok'] !== '' ? $saringan['min_stok'] : '0')
                . '-' . ($saringan['max_stok'] !== '' ? $saringan['max_stok'] : 'tak terbatas');
        }

        if ($saringan['purch'] !== '') {
            $bagian[] = 'Purchaser: ' . $saringan['purch'];
        }

        if ($saringan['vendor'] !== '') {
            $bagian[] = 'Vendor: ' . $saringan['vendor'];
        }

        if ($saringan['dari'] !== '' || $saringan['sampai'] !== '') {
            $bagian[] = 'Diperbarui: ' . ($saringan['dari'] !== '' ? $saringan['dari'] : 'awal')
                . ' s.d. ' . ($saringan['sampai'] !== '' ? $saringan['sampai'] : 'akhir');
        }

        if ($saringan['q'] !== '') {
            $bagian[] = 'Pencarian: ' . $saringan['q'];
        }

        return $bagian === [] ? '(tanpa saringan)' : implode(' | ', $bagian);
    }

    /**
     * Menyimpan berkas unggahan ke folder sementara di storage.
     *
     * Nama berkas diberi cap waktu dan angka acak supaya unggahan bersamaan
     * tidak saling menimpa.
     *
     * @param  UploadedFile $berkas Berkas unggahan
     * @param  string       $kunci  Kunci sumber (stok/pr/po/pantau)
     * @return string               Path berkas sementara
     */
    private function simpanSementara(UploadedFile $berkas, string $kunci): string
    {
        return (new SparepartUnggahan())->simpan($berkas, $kunci);
    }
}