<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportFilters;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * LocationController
 *
 * Halaman detail lokasi (Functional Location). Dipakai dari halaman Laporan
 * (kolom "Lokasi / Alat"), halaman detail Asset, dan halaman Functional
 * Location supaya teknisi bisa melihat seluruh alat dan seluruh laporan pada
 * satu lokasi.
 *
 * Daftar laporan di halaman ini dikelompokkan PER HARI (seperti halaman
 * Laporan) dan bisa difilter per tanggal maupun per teknisi.
 */
class LocationController extends Controller
{
    use ResolvesReportFilters;

    /**
     * Tampilkan detail satu lokasi: ringkasan, daftar alat, dan daftar laporan.
     *
     * Cakupan data mencakup lokasi ini BESERTA seluruh turunannya (kode FuncLoc
     * selalu berawalan kode induk, mis. EPE-PROD-BD02 mencakup
     * EPE-PROD-BD02-6153), sehingga node level PT/Departemen/Area tetap berguna.
     *
     * @param  Request             $request   Request HTTP (filter + paginasi)
     * @param  FunctionalLocation  $location  Node lokasi yang dibuka
     * @return View
     */
    public function show(Request $request, FunctionalLocation $location): View
    {
        $location->load(['company', 'department', 'area', 'subArea', 'parent']);

        // Node lokasi ini + seluruh turunannya.
        $idLokasi = $this->idLokasiDenganTurunan($location);

        // ---- Parameter filter (tanggal per-hari, rentang, teknisi, cari alat) ----
        $tanggal  = $this->tanggalValid($request->query('tanggal'));
        $dari     = $this->tanggalValid($request->query('date_from'));
        $sampai   = $this->tanggalValid($request->query('date_to'));
        $teknisi  = $request->filled('teknisi') ? (int) $request->query('teknisi') : null;
        $cariAlat = trim((string) $request->query('alat', ''));

        // ---- Query dasar (tanpa filter tanggal/teknisi) ----
        $queryAset    = $this->queryAsetLokasi($idLokasi, $location, $cariAlat);
        $queryLaporan = $this->queryLaporanLokasi($idLokasi, $queryAset);

        // ---- Query yang ditampilkan (dengan filter tanggal/teknisi) ----
        $queryTampil = $this->terapkanFilterTanggal(clone $queryLaporan, $tanggal, $dari, $sampai)
            ->when($teknisi !== null, fn (Builder $q) => $q->where('technician_id', $teknisi));

        $jumlahTampil = (clone $queryTampil)->count();

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

        // Teknisi yang benar-benar punya laporan di lokasi ini (isi dropdown filter).
        $teknisiOpsi = Technician::query()
            ->whereIn('id', (clone $queryLaporan)->reorder()->select('technician_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $alat    = $queryAset->paginate(15, ['*'], 'page_alat')->withQueryString();
        $laporan = $queryTampil->paginate(25, ['*'], 'page_laporan')->withQueryString();

        // Daftar laporan dikelompokkan PER HARI seperti halaman Laporan.
        $laporanPerHari = $laporan->getCollection()
            ->groupBy(fn (Report $item) => $item->report_date?->toDateString() ?? 'tanpa-tanggal');

        // Rentang tanggal laporan lokasi ini, dipakai tombol "Lihat semua di
        // halaman Laporan" supaya halaman laporan tidak terbatas tanggal hari ini.
        $tanggalAwal = (clone $queryLaporan)->min('report_date');

        return view('locations.show', [
            'location'       => $location,
            'jalur'          => $this->jalurLokasi($location),
            'alat'           => $alat,
            'laporan'        => $laporan,
            'laporanPerHari' => $laporanPerHari,
            'statistik'      => $statistik,
            'asalLaporan'    => $asalLaporan,
            'teknisiOpsi'    => $teknisiOpsi,
            'filterAktif'    => [
                'tanggal'      => $tanggal,
                'date_from'    => $dari,
                'date_to'      => $sampai,
                'teknisi'      => $teknisi,
                'alat'         => $cariAlat,
                'jumlah_tampil' => $jumlahTampil,
            ],
            'tautanTanggal'  => $this->tautanTanggal($request, $location, $tanggal),
            'rentangLaporan' => [
                'dari'   => $tanggalAwal ? Carbon::parse($tanggalAwal)->toDateString() : null,
                'sampai' => now()->toDateString(),
            ],
        ]);
    }

    /**
     * Query alat pada satu lokasi (termasuk turunannya) beserta filter
     * pencarian alat (kode/tag, nomor equipment, atau deskripsi).
     *
     * @param  Collection<int, int>  $idLokasi  ID lokasi + turunannya
     * @param  FunctionalLocation    $location  Node lokasi yang dibuka
     * @param  string                $cariAlat   Kata kunci pencarian alat (kosong = semua)
     * @return Builder  Query alat yang siap dipaginasi
     */
    protected function queryAsetLokasi(Collection $idLokasi, FunctionalLocation $location, string $cariAlat = ''): Builder
    {
        return Asset::query()
            ->with(['company', 'department', 'area'])
            ->where(function (Builder $q) use ($idLokasi, $location) {
                $q->whereIn('funcloc_id', $idLokasi)
                    ->orWhere('functional_loc', $location->code)
                    ->orWhere('functional_loc', 'like', $location->code . '-%');
            })
            ->when($cariAlat !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($cariAlat) {
                $sub->where('tech_ident_no', 'like', "%{$cariAlat}%")
                    ->orWhere('equipment_no', 'like', "%{$cariAlat}%")
                    ->orWhere('description', 'like', "%{$cariAlat}%");
            }))
            ->orderByRaw('tech_ident_no IS NULL')
            ->orderBy('tech_ident_no');
    }

    /**
     * Query laporan yang menyentuh satu lokasi: laporan ber-lokasi ini, atau
     * laporan perbaikan atas alat yang berlokasi di sini (termasuk turunan).
     *
     * @param  Collection<int, int>  $idLokasi    ID lokasi + turunannya
     * @param  Builder               $queryAset   Query alat lokasi (dipakai sebagai subquery)
     * @return Builder  Query laporan dasar (belum difilter tanggal/teknisi)
     */
    protected function queryLaporanLokasi(Collection $idLokasi, Builder $queryAset): Builder
    {
        return Report::query()
            ->with(['technician', 'asset', 'functionalLocation', 'linkedFinding'])
            ->where(function (Builder $q) use ($idLokasi, $queryAset) {
                $q->whereIn('funcloc_id', $idLokasi)
                    ->orWhereIn('asset_id', (clone $queryAset)->select('id'));
            })
            ->orderByDesc('report_date')
            ->orderByDesc('id');
    }

    /**
     * Susun tautan navigasi tanggal (hari sebelumnya/ini/berikutnya/semua) yang
     * tetap membawa filter lain (teknisi, pencarian alat), dipakai tombol
     * per-hari pada daftar laporan.
     *
     * @param  Request             $request   Request HTTP saat ini
     * @param  FunctionalLocation  $location  Node lokasi yang dibuka
     * @param  string|null         $tanggal   Tanggal aktif (null = semua tanggal)
     * @return array<string, string|null>  Peta nama tautan ke URL
     */
    protected function tautanTanggal(Request $request, FunctionalLocation $location, ?string $tanggal): array
    {
        // Parameter paginasi dibuang supaya kembali ke halaman pertama.
        $filter = $request->except(['tanggal', 'date_from', 'date_to', 'page_laporan', 'page_alat']);

        $buatTautan = fn (?string $nilai) => route('locations.show', array_merge(
            $filter,
            ['location' => $location->id],
            $nilai === null ? [] : ['tanggal' => $nilai]
        ));

        $acuan = Carbon::parse($tanggal ?? now()->toDateString());

        return [
            'semua'      => $buatTautan(null),
            'sebelumnya' => $buatTautan($acuan->copy()->subDay()->toDateString()),
            'ini'        => $buatTautan(now()->toDateString()),
            'berikutnya' => $buatTautan($acuan->copy()->addDay()->toDateString()),
            'aktif'      => $tanggal,
        ];
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
