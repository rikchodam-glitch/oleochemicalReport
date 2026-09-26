<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * LocationController
 *
 * Halaman detail lokasi (Functional Location). Dipakai dari halaman Laporan
 * (kolom "Lokasi / Alat") dan halaman detail Asset supaya teknisi bisa melihat
 * seluruh alat dan seluruh laporan pada satu lokasi, sama seperti halaman detail
 * asset menampilkan riwayat laporan satu alat.
 */
class LocationController extends Controller
{
    /**
     * Tampilkan detail satu lokasi: ringkasan, daftar alat, dan daftar laporan.
     *
     * Cakupan data mencakup lokasi ini BESERTA seluruh turunannya (kode FuncLoc
     * selalu berawalan kode induk, mis. EPE-PROD-BD02 mencakup
     * EPE-PROD-BD02-6153), sehingga node level PT/Departemen/Area tetap berguna.
     *
     * @param  Request             $request   Request HTTP (untuk paginasi)
     * @param  FunctionalLocation  $location  Node lokasi yang dibuka
     * @return View
     */
    public function show(Request $request, FunctionalLocation $location): View
    {
        $location->load(['company', 'department', 'area', 'subArea', 'parent']);

        // Node lokasi ini + seluruh turunannya.
        $idLokasi = $this->idLokasiDenganTurunan($location);

        // Alat di lokasi tersebut. Sebagian baris lama hanya menyimpan kode lokasi
        // sebagai teks (functional_loc tanpa funcloc_id), jadi keduanya dicari.
        $queryAset = Asset::query()
            ->with(['company', 'department', 'area'])
            ->where(function ($q) use ($idLokasi, $location) {
                $q->whereIn('funcloc_id', $idLokasi)
                    ->orWhere('functional_loc', $location->code)
                    ->orWhere('functional_loc', 'like', $location->code . '-%');
            })
            ->orderByRaw('tech_ident_no IS NULL')
            ->orderBy('tech_ident_no');

        // Laporan yang menyentuh lokasi ini: laporan ber-lokasi ini, atau
        // laporan perbaikan atas alat yang berlokasi di sini.
        $queryLaporan = Report::query()
            ->with(['technician', 'asset', 'functionalLocation', 'linkedFinding'])
            ->where(function ($q) use ($idLokasi, $queryAset) {
                $q->whereIn('funcloc_id', $idLokasi)
                    ->orWhereIn('asset_id', (clone $queryAset)->select('id'));
            })
            ->orderByDesc('report_date')
            ->orderByDesc('id');

        $statistik = [
            'alat'          => (clone $queryAset)->count(),
            'sub_lokasi'    => max($idLokasi->count() - 1, 0),
            'laporan'       => (clone $queryLaporan)->count(),
            'laporan_baru'  => (clone $queryLaporan)
                ->where('report_date', '>=', now()->subDays(30)->toDateString())
                ->count(),
            'belum_selesai' => (clone $queryLaporan)
                ->where('status', '!=', 'completed')
                ->count(),
        ];

        $asalLaporan = [
            'lokasi'   => (clone $queryLaporan)->whereIn('funcloc_id', $idLokasi)->count(),
            'per_alat' => (clone $queryLaporan)->whereNull('funcloc_id')->count(),
        ];

        $alat    = $queryAset->paginate(15, ['*'], 'alat')->withQueryString();
        $laporan = $queryLaporan->paginate(20, ['*'], 'laporan')->withQueryString();

        // Rentang tanggal laporan lokasi ini, dipakai tombol "Lihat semua di
        // halaman Laporan" supaya halaman laporan tidak terbatas tanggal hari ini.
        $tanggalAwal = (clone $queryLaporan)->min('report_date');

        return view('locations.show', [
            'location'        => $location,
            'jalur'           => $this->jalurLokasi($location),
            'alat'            => $alat,
            'laporan'         => $laporan,
            'statistik'       => $statistik,
            'asalLaporan'     => $asalLaporan,
            'rentangLaporan'  => [
                'dari'   => $tanggalAwal ? \Illuminate\Support\Carbon::parse($tanggalAwal)->toDateString() : null,
                'sampai' => now()->toDateString(),
            ],
        ]);
    }

    /**
     * Kumpulkan ID lokasi ini dan seluruh node turunannya.
     *
     * @param  FunctionalLocation  $location  Node induk
     * @return Collection<int, int>  Daftar ID lokasi
     */
    protected function idLokasiDenganTurunan(FunctionalLocation $location): Collection
    {
        return FunctionalLocation::query()
            ->where('code', $location->code)
            ->orWhere('code', 'like', $location->code . '-%')
            ->pluck('id');
    }

    /**
     * Susun rantai node leluhur (PT > Departemen > Area > Section) berdasarkan
     * akumulasi segmen kode, dipakai untuk breadcrumb halaman detail lokasi.
     *
     * @param  FunctionalLocation  $location  Node lokasi
     * @return Collection<int, FunctionalLocation>  Node dari level teratas ke lokasi ini
     */
    protected function jalurLokasi(FunctionalLocation $location): Collection
    {
        $segmen = explode('-', $location->code);
        $kode   = [];
        $jalan  = '';

        foreach ($segmen as $bagian) {
            $jalan  = $jalan === '' ? $bagian : $jalan . '-' . $bagian;
            $kode[] = $jalan;
        }

        return FunctionalLocation::whereIn('code', $kode)
            ->orderBy('level')
            ->get();
    }
}
