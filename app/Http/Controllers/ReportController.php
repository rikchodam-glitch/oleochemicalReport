<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\ReportExportTrait;
use App\Http\Controllers\Traits\ReportLocationTrait;
use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Services\TechnicianKinerjaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    use ReportExportTrait;
    use ReportLocationTrait;

    /**
     * Halaman daftar laporan.
     *
     * Aturan hitungan angka ringkasan (kartu jenis pekerjaan, kartu status,
     * dan kartu "Total Pekerjaan"):
     *   - Satu laporan = satu baris tampilan. Salinan kolaborasi
     *     (collaborator_of) dan salinan multi alat (multi_of) TIDAK dihitung.
     *   - Kartu jenis pekerjaan & kartu status memakai seluruh filter yang
     *     sedang aktif KECUALI filter yang dipegang kartu itu sendiri
     *     (jenis_pekerjaan / status), supaya angkanya tidak berubah menjadi
     *     nol saat kartu itu sendiri dipakai sebagai filter.
     *   - "Total Pekerjaan" memakai angka paginator, jadi DIJAMIN sama dengan
     *     jumlah baris tabel yang tampil pada filter yang sedang aktif.
     *
     * @param  Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $tampilan     = $request->get('tampilan') === 'shift' ? 'shift' : 'reguler';
        $grubAktif    = $tampilan === 'shift' ? $this->resolveGrub($request->get('grub')) : null;
        $tanggalAktif = $this->resolveTanggal($request->get('tanggal'));
        $pakaiRentang = $request->filled('date_from') || $request->filled('date_to');

        $query = $this->buildReportQuery($request, $tampilan, $grubAktif, $tanggalAktif);

        // Ringkasan jenis pekerjaan & status: query yang sama tanpa filter
        // jenis_pekerjaan/status (sebelum paginasi).
        $queryRingkasan = $this->buildReportQuery($request, $tampilan, $grubAktif, $tanggalAktif, false);

        $countEI        = (clone $queryRingkasan)->where('jenis_pekerjaan', 'EI')->count();
        $countMekanik   = (clone $queryRingkasan)->where('jenis_pekerjaan', 'Mekanik')->count();
        $countCmPm      = (clone $queryRingkasan)->where('jenis_pekerjaan', 'CM/PM')->count();
        $countFabrikasi = (clone $queryRingkasan)->where('jenis_pekerjaan', 'Fabrikasi')->count();
        $countSipil     = (clone $queryRingkasan)->where('jenis_pekerjaan', 'Sipil')->count();
        $countIT        = (clone $queryRingkasan)->where('jenis_pekerjaan', 'IT')->count();
        $countProject   = (clone $queryRingkasan)->where('jenis_pekerjaan', 'Project')->count();
        $countUmum      = (clone $queryRingkasan)->where('jenis_pekerjaan', 'UMUM')->count();
        $countContinue  = (clone $queryRingkasan)->where('status', 'continue')->count();
        $countDone      = (clone $queryRingkasan)->where('status', 'completed')->count();

        $reports = $query
            ->with(['technician', 'area', 'asset', 'functionalLocation', 'linkedFinding', 'multiItems', 'collaboratorReports.technician'])
            // Laporan terbaru di paling atas; dalam satu tanggal diurutkan dari
            // yang paling akhir dibuat (created_at, lalu id sebagai penentu).
            ->orderByDesc('report_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Kartu "Total Pekerjaan" memakai angka paginator supaya DIJAMIN sama
        // dengan jumlah baris tabel ("Menampilkan 1-25 dari N pekerjaan") pada
        // filter yang sedang aktif.
        $totalLaporan = $reports->total();

        // Append jumlah foto dokumentasi & hygiene ke setiap laporan di
        // halaman ini (bukan kolom DB — dihitung dari array JSON).
        $reports->getCollection()->transform(function (Report $report) {
            $report->photo_doc_count = count($report->photo_documentation ?? []);
            $report->photo_hyg_count = count($report->photo_hygiene_clearance ?? []);

            return $report;
        });

        $tanggalSekitar = $this->tanggalSekitar($request, $tampilan, $grubAktif, $tanggalAktif);

        $areas       = Area::orderBy('code')->get();
        $technicians = Technician::orderBy('name')->get(['id', 'name', 'nik']);
        // Pilihan grub hanya untuk pekerja shift (Grub A-D); reguler & reguler
        // staff masuk ke tampilan Reguler, bukan ke daftar grub.
        $grubOptions = array_intersect_key(Technician::GROUPS, array_flip(Technician::GRUP_SHIFT));

        return view('reports.index', compact(
            'reports', 'areas', 'technicians', 'grubOptions',
            'totalLaporan', 'countEI', 'countMekanik', 'countCmPm', 'countFabrikasi', 'countSipil',
            'countIT', 'countProject', 'countUmum', 'countContinue', 'countDone',
            'tampilan', 'grubAktif', 'tanggalAktif', 'pakaiRentang', 'tanggalSekitar'
        ));
    }

    /**
     * Query dasar laporan: aturan konsolidasi + filter tampilan/grup + filter
     * lanjutan, TANPA filter tanggal (filter tanggal ditambahkan oleh
     * buildReportQuery()).
     *
     * Aturan yang dipakai:
     *   - Laporan kolaborasi tampil sebagai SATU baris: hanya laporan induk
     *     (collaborator_of NULL) yang diambil; daftar kolaborator dibaca dari
     *     relasi collaboratorReports. Salinan multi alat (multi_of) juga bukan
     *     baris tersendiri.
     *   - PENGECUALIAN (05/10/2026): bila daftar disaring ke SATU teknisi
     *     (technician_id), salinan kolaborasi milik teknisi itu IKUT tampil.
     *     Baris itulah pekerjaan yang tercatat atas namanya, sehingga jumlahnya
     *     sama dengan kolom "Pekerjaan" pada Rincian Man Hour Harian teknisi
     *     tersebut (baris "N laporan" di sana membuka halaman ini).
     *   - Tampilan "reguler" memuat laporan teknisi non-shift: grup 'reguler'
     *     dan 'reguler_staff' (termasuk teknisi yang grupnya belum diisi);
     *     tampilan "shift" memuat laporan teknisi grub (A/B/C/D) saja dan bisa
     *     dipersempit ke satu grub.
     *
     * @param  Request      $request
     * @param  string       $tampilan             'reguler', 'shift', atau 'semua' (keduanya)
     * @param  string|null  $grubAktif            Kunci grub terpilih (khusus shift)
     * @param  bool         $sertakanJenisStatus  Ikutkan filter jenis_pekerjaan & status
     * @return \Illuminate\Database\Eloquent\Builder<Report>
     */
    private function buildBaseQuery(Request $request, string $tampilan, ?string $grubAktif, bool $sertakanJenisStatus = true)
    {
        $query = Report::query()->whereNull('multi_of');

        // Konsolidasi kolaborasi hanya berlaku pada daftar UMUM (tanpa saringan
        // teknisi). Bila daftar disaring ke satu teknisi, salinan kolaborasi
        // milik teknisi itu ikut tampil supaya jumlahnya sama dengan kolom
        // "Pekerjaan" pada Rincian Man Hour Harian (baris "N laporan" di sana
        // membuka halaman ini). Salinan multi alat (multi_of) tetap tidak
        // tampil karena pekerjaan itu sudah terwakili laporan induknya.
        if (!$request->filled('technician_id')) {
            $query->whereNull('collaborator_of');
        }

        $this->applyGroupFilter($query, $tampilan, $grubAktif);

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        if ($request->filled('work_classification')) {
            $query->where('work_classification', $request->work_classification);
        }

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        if ($request->filled('technician_id')) {
            $query->where('technician_id', $request->technician_id);
        }

        if ($request->filled('asset_code')) {
            $assetCode = $request->asset_code;
            $query->whereHas('asset', function ($q) use ($assetCode) {
                $q->where('tech_ident_no', 'like', '%' . $assetCode . '%');
            });
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            // Pencarian lapangan: deskripsi pekerjaan, kode laporan, kode lokasi
            // (Functional Location), serta lokasi/kode alat — supaya pencarian
            // seperti "EPE-PROD-BD02-6153" atau "LOK-P-01" ikut ketemu.
            $query->where(function ($q) use ($search) {
                $q->where('work_description', 'like', "%{$search}%")
                    ->orWhere('report_code', 'like', "%{$search}%")
                    ->orWhereHas('functionalLocation', fn ($sub) => $sub->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('asset', fn ($sub) => $sub
                        ->where('functional_loc', 'like', "%{$search}%")
                        ->orWhere('tech_ident_no', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('report_code')) {
            $query->where('report_code', 'like', '%' . $request->report_code . '%');
        }

        if ($sertakanJenisStatus && $request->filled('jenis_pekerjaan')) {
            $query->where('jenis_pekerjaan', $request->jenis_pekerjaan);
        }

        // Filter status pekerjaan (mis. 'continue' = pekerjaan belum selesai).
        if ($sertakanJenisStatus && $request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query;
    }

    /**
     * Susun query laporan yang tampil pada tabel halaman laporan: query dasar
     * (buildBaseQuery) ditambah filter tanggal — satu hari (default hari ini),
     * atau rentang bila date_from / date_to diisi.
     *
     * @param  Request         $request
     * @param  string          $tampilan             'reguler', 'shift', atau 'semua' (keduanya)
     * @param  string|null     $grubAktif            Kunci grub terpilih (khusus shift)
     * @param  \Carbon\Carbon  $tanggalAktif         Tanggal aktif (dipakai bila tanpa rentang)
     * @param  bool            $sertakanJenisStatus  Ikutkan filter jenis_pekerjaan & status
     * @return \Illuminate\Database\Eloquent\Builder<Report>
     */
    private function buildReportQuery(Request $request, string $tampilan, ?string $grubAktif, $tanggalAktif, bool $sertakanJenisStatus = true)
    {
        $query = $this->buildBaseQuery($request, $tampilan, $grubAktif, $sertakanJenisStatus);

        if ($request->filled('date_from')) {
            $query->whereDate('report_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('report_date', '<=', $request->date_to);
        }

        if (! $request->filled('date_from') && ! $request->filled('date_to')) {
            // Khusus popup Export Laporan: centang "seluruh data" mengirim
            // tanpa_batas=1 sehingga batas tanggal satu hari dilewati. Halaman
            // Laporan tidak pernah mengirim kunci ini, jadi perilakunya utuh.
            if (! $request->boolean('tanpa_batas')) {
                $query->whereDate('report_date', $tanggalAktif->toDateString());
            }
        }

        return $query;
    }

    /**
     * Terapkan filter grup teknisi sesuai tampilan yang dipilih.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Report>  $query
     * @param  string       $tampilan  'reguler', 'shift', atau 'semua'
     * @param  string|null  $grubAktif
     * @return void
     */
    private function applyGroupFilter($query, string $tampilan, ?string $grubAktif): void
    {
        // Tampilan 'semua' (diputuskan user 08/10/2026) = pilihan "Keduanya"
        // pada popup export: pekerja reguler DAN shift ikut, jadi tidak ada
        // penyaringan grup sama sekali. Halaman Laporan tidak pernah mengirim
        // nilai ini (selalu 'reguler' atau 'shift'), jadi perilakunya utuh.
        if ($tampilan === 'semua') {
            return;
        }

        if ($tampilan === 'shift') {
            // Tampilan Shift hanya memuat pekerja shift (Grub A-D). Reguler dan
            // Reguler Staff BUKAN shift, jadi tidak boleh ikut di sini.
            $grubShift = Technician::GRUP_SHIFT;

            $query->whereHas('technician', function ($q) use ($grubAktif, $grubShift) {
                if ($grubAktif !== null) {
                    $q->where('group', $grubAktif);

                    return;
                }

                $q->whereIn('group', $grubShift);
            });

            return;
        }

        // Reguler: grup 'reguler' DAN 'reguler_staff' (keduanya non-shift),
        // termasuk teknisi yang grupnya belum diisi.
        $query->whereHas('technician', function ($q) {
            $q->whereIn('group', Technician::GRUP_NON_SHIFT)->orWhereNull('group');
        });
    }

    /**
     * Pastikan kunci grub yang dipilih benar-benar ada pada daftar grup.
     *
     * @param  string|null  $grub
     * @return string|null
     */
    private function resolveGrub(?string $grub): ?string
    {
        if ($grub === null || $grub === '') {
            return null;
        }

        return array_key_exists($grub, Technician::GROUPS) ? $grub : null;
    }

    /**
     * Tentukan tanggal aktif halaman laporan (default hari ini).
     *
     * @param  string|null  $tanggal  Format Y-m-d
     * @return \Carbon\Carbon
     */
    private function resolveTanggal(?string $tanggal)
    {
        if ($tanggal === null || $tanggal === '') {
            return now()->startOfDay();
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d', $tanggal)->startOfDay();
        } catch (\Exception $e) {
            return now()->startOfDay();
        }
    }


    /**
     * Cari tanggal laporan terdekat sebelum/sesudah tanggal aktif, plus daftar
     * tanggal terakhir yang punya laporan (untuk tombol lompat cepat).
     *
     * Hitungan per tanggal memakai definisi yang SAMA dengan tabel: satu
     * laporan = satu baris (salinan kolaborasi & salinan multi alat tidak
     * dihitung) dan mengikuti seluruh filter yang sedang aktif kecuali tanggal.
     *
     * @param  Request         $request
     * @param  string          $tampilan
     * @param  string|null     $grubAktif
     * @param  \Carbon\Carbon  $tanggalAktif
     * @return array{sebelumnya: string|null, berikutnya: string|null, daftar: \Illuminate\Support\Collection}
     */
    private function tanggalSekitar(Request $request, string $tampilan, ?string $grubAktif, $tanggalAktif): array
    {
        $dasar = $this->buildBaseQuery($request, $tampilan, $grubAktif);

        $sebelumnya = (clone $dasar)
            ->whereDate('report_date', '<', $tanggalAktif->toDateString())
            ->max('report_date');

        $berikutnya = (clone $dasar)
            ->whereDate('report_date', '>', $tanggalAktif->toDateString())
            ->min('report_date');

        $daftar = (clone $dasar)
            ->selectRaw('report_date, count(*) as jumlah')
            ->whereNotNull('report_date')
            ->groupBy('report_date')
            ->orderByDesc('report_date')
            ->limit(10)
            ->get()
            ->map(fn ($baris) => [
                'tanggal' => \Carbon\Carbon::parse($baris->report_date)->toDateString(),
                'jumlah'  => (int) $baris->jumlah,
            ]);

        return [
            'sebelumnya' => $sebelumnya !== null ? \Carbon\Carbon::parse($sebelumnya)->toDateString() : null,
            'berikutnya' => $berikutnya !== null ? \Carbon\Carbon::parse($berikutnya)->toDateString() : null,
            'daftar'     => $daftar,
        ];
    }

    /**
     * Tampilkan form laporan baru (dibuat manual oleh admin di web).
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $areas = Area::orderBy('code')->get(['id', 'code', 'name']);

        return view('reports.create', compact('areas'));
    }

    /**
     * Simpan laporan baru yang dibuat manual oleh admin di web.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'report_date'             => 'required|date',
            'work_description'        => 'required|string',
            'work_duration_minutes'   => 'required|integer|min:0',
            'root_cause'              => 'nullable|string',
            // Temuan (finding) — dicatat teknisi setelah root cause, opsional.
            'finding'                 => 'nullable|string',
            'area_id'                 => 'nullable|exists:areas,id',
            'funcloc_id'              => 'nullable|exists:functional_locations,id',
            'asset_id'                => 'nullable|exists:assets,id',
            'report_type'             => 'required|in:equipment_repair,area_work,general',
            'work_classification'     => 'nullable|in:zpm1,zpm3,zpm6,zpm5,zpm7,zpm8',
            'jenis_pekerjaan'         => 'nullable|in:CM,dCM,PM,EI,Mekanik,CM/PM,Fabrikasi,Sipil,IT,Project,UMUM',
            'linked_finding_id'       => 'nullable|exists:cm_findings,id',
            'sesuai_rencana'          => 'nullable|in:ya,tidak',
            'tanggal_kejadian'        => 'nullable|date',
            'equipment_tag'           => 'nullable|string|max:100',
        ]);

        // Validasi khusus dCM: sesuai_rencana wajib bila jenis dCM
        if (($validated['jenis_pekerjaan'] ?? '') === 'dCM' && empty($validated['sesuai_rencana'])) {
            return back()->withErrors(['sesuai_rencana' => 'Untuk pekerjaan dCM, field "Sesuai Rencana" wajib diisi.'])
                ->withInput();
        }

        // Validasi non-dCM: null-kan sesuai_rencana
        if (($validated['jenis_pekerjaan'] ?? '') !== 'dCM') {
            $validated['sesuai_rencana'] = null;
        }

        $validated['report_code']    = Report::generateReportCode();
        // Status 'draft' sudah tidak dipakai; laporan manual langsung 'completed'.
        $validated['status']         = $request->status ?? 'completed';
        $validated['is_manually_edited'] = true;

        $report = Report::create($validated);

        return redirect()->route('reports.show', $report)
            ->with('success', 'Laporan berhasil dibuat.');
    }

    public function show(Report $report)
{
    $report->load([
        'technician',
        'area',
        'asset',
        'aiSuggestions.suggestedArea',
        'aiSuggestions.suggestedAsset',
        'creator',
        'parentReport.technician',
        'collaboratorReports.technician',
        'linkedFinding',
        'cmEquipment',
    ]);

    // Variabel untuk dropdown inline edit di show.blade.php.
    // Mengikuti pola yang sama dengan edit() agar prefetch awal sudah terisi.
    $areas = Area::orderBy('code')->get(['id', 'code', 'name']);

    $funcLocs = $report->area_id
        ? FunctionalLocation::where('area_id', $report->area_id)->orderBy('code')->get(['id', 'code', 'name', 'level'])
        : collect();

    $assets = $report->area_id
        ? Asset::where('area_id', $report->area_id)->orderBy('tech_ident_no')->get(['id', 'equipment_no', 'tech_ident_no', 'description'])
        : collect();

    // Data pengelolaan (laporan induk, kolaborator, teknisi, alat tambahan)
    // dipakai kartu Kolaborasi Teknisi & Alat/Lokasi Tambahan pada halaman
    // detail, karena halaman edit terpisah sudah tidak ada lagi.
    $pengelolaan = $this->dataPengelolaanLaporan($report);

    // Data kartu "Teknisi Pelaksana" pada kolom kanan (profil + grafik kinerja).
    $kartuTeknisi = $this->siapkanKartuTeknisi($report, $pengelolaan['laporanInduk'], $pengelolaan['kolaborator']);

    return view('reports.show', array_merge(
        compact('report', 'areas', 'funcLocs', 'assets', 'kartuTeknisi'),
        $pengelolaan
    ));
}

    /**
     * Siapkan data pengelolaan laporan untuk halaman DETAIL laporan.
     *
     * Halaman detail adalah SATU-SATUNYA tempat mengubah laporan sekarang
     * (rute reports.edit sudah dihapus), jadi kartu Kolaborasi Teknisi dan
     * Alat/Lokasi Tambahan membutuhkan data yang dulu disiapkan halaman edit.
     * Kolaborator dan alat tambahan SELALU dikelola dari laporan INDUK supaya
     * satu pekerjaan tidak bercabang menjadi banyak daftar.
     *
     * @param  Report  $report  Laporan yang sedang dibuka.
     * @return array<string, mixed>  Kunci: laporanInduk, kolaborator,
     *                               teknisiPilihan, alatTambahan.
     */
    private function dataPengelolaanLaporan(Report $report): array
    {
        // Laporan INDUK dari keluarga laporan ini: salinan kolaborasi
        // (collaborator_of) maupun salinan alat tambahan (multi_of) selalu
        // dikelola dari laporan induknya.
        $laporanInduk = $report->multi_of !== null
            ? $report->multiOf
            : ($report->collaborator_of !== null ? $report->parentReport : $report);

        // Null-safe bila kolom penautnya menunjuk laporan yang sudah terhapus.
        $laporanInduk ??= $report;

        return [
            'laporanInduk'   => $laporanInduk,
            'kolaborator'    => $laporanInduk->collaboratorReports()
                ->with('technician:id,name')
                ->orderBy('id')
                ->get(),
            'teknisiPilihan' => Technician::orderBy('name')->get(['id', 'name', 'nik']),
            'alatTambahan'   => $laporanInduk->multiItems()
                ->with(['asset', 'functionalLocation'])
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * Siapkan data kartu "Teknisi Pelaksana" pada kolom kanan halaman detail:
     * profil + angka kinerja + deret bulanan grafik untuk setiap teknisi yang
     * terlibat pada pekerjaan ini.
     *
     * Teknisi yang ikut dihitung (urutannya juga urutan chip di tampilan):
     *   1. pelaksana laporan INDUK (pemilik pekerjaan),
     *   2. pelaksana baris yang sedang dibuka (mis. saat membuka baris salinan),
     *   3. seluruh teknisi kolaborator laporan induk.
     *
     * Laporan tanpa teknisi (data lama) menghasilkan daftar kosong supaya kartu
     * bisa disembunyikan, bukan menampilkan nama kosong.
     *
     * @param  Report                              $report        Laporan yang sedang dibuka
     * @param  Report                              $laporanInduk  Laporan induk pekerjaan ini
     * @param  \Illuminate\Support\Collection       $kolaborator   Baris salinan kolaborasi laporan induk
     * @return array{pelaksana: int|null, daftar: array<int, array<string, mixed>>}
     */
    private function siapkanKartuTeknisi(Report $report, Report $laporanInduk, $kolaborator): array
    {
        $idTeknisi = [];

        if ($laporanInduk->technician_id !== null) {
            $idTeknisi[] = (int) $laporanInduk->technician_id;
        }

        if ($report->technician_id !== null) {
            $idTeknisi[] = (int) $report->technician_id;
        }

        foreach ($kolaborator as $baris) {
            if ($baris->technician_id !== null) {
                $idTeknisi[] = (int) $baris->technician_id;
            }
        }

        $idTeknisi = array_values(array_unique($idTeknisi));

        if ($idTeknisi === []) {
            return ['pelaksana' => null, 'daftar' => []];
        }

        $kinerja = app(TechnicianKinerjaService::class)->ringkasUntuk($idTeknisi);

        // Tidak ada satu pun teknisi yang datanya masih ada: kartu disembunyikan.
        if ($kinerja === []) {
            return ['pelaksana' => null, 'daftar' => []];
        }

        // Pelaksana = pemilik laporan induk; bila datanya tidak ada, ambil
        // teknisi pertama yang masih punya data kinerja.
        $pelaksana = (int) ($laporanInduk->technician_id ?? $idTeknisi[0]);

        if (! isset($kinerja[$pelaksana])) {
            $pelaksana = (int) array_key_first($kinerja);
        }

        foreach ($kinerja as $id => $data) {
            $kinerja[$id]['peran'] = $id === $pelaksana ? 'Pelaksana' : 'Kolaborator';
        }

        return [
            'pelaksana' => $pelaksana,
            'daftar'    => array_values($kinerja),
        ];
    }

    /**
     * Simpan perubahan hasil edit admin pada laporan.
     * Menandai laporan sebagai is_manually_edited agar panel AI di halaman
     * show/index menampilkan badge "Edited" alih-alih persentase confidence.
     * Termasuk perubahan Area, Functional Location, dan Asset (kode alat).
     *
     * @param  Request  $request  Data form edit.
     * @param  Report  $report  Laporan yang diperbarui.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Report $report)
    {
        $validated = $request->validate([
            'work_description'       => 'required|string',
            // Durasi boleh dikosongkan: bila jam mulai & jam selesai diisi,
            // durasinya dihitung otomatis dari rentang jam (lihat di bawah).
            'work_duration_minutes'  => 'nullable|integer|min:0',
            // Jam kerja format HH:MM. Memakai regex (bukan date_format) supaya
            // nilainya benar-benar jam 00:00-23:59 dan pesan galatnya jelas.
            'start_time'             => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'end_time'               => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'root_cause'              => 'nullable|string',
            // Temuan (finding) — dicatat teknisi setelah root cause, opsional.
            'finding'                 => 'nullable|string',
            'report_date'             => 'required|date',
            'area_id'                 => 'nullable|exists:areas,id',
            'funcloc_id'              => 'nullable|exists:functional_locations,id',
            'asset_id'                => 'nullable|exists:assets,id',
            'work_classification'     => 'nullable|in:zpm1,zpm3,zpm6,zpm5,zpm7,zpm8',
            'jenis_pekerjaan'         => 'nullable|in:CM,dCM,PM,EI,Mekanik,CM/PM,Fabrikasi,Sipil,IT,Project,UMUM',
            'linked_finding_id'       => 'nullable|exists:cm_findings,id',
            'sesuai_rencana'          => 'nullable|in:ya,tidak',
            'tanggal_kejadian'        => 'nullable|date',
            'equipment_tag'           => 'nullable|string|max:100',
            // Tipe laporan & status boleh dikirim dari form edit. Dibuat
            // nullable supaya pemanggil lama (mis. uji yang hanya mengirim
            // sebagian data) tidak memaksa nilai baru dan status lama tidak
            // ikut terhapus.
            'report_type'             => 'nullable|in:equipment_repair,area_work,general',
            'status'                  => 'nullable|in:completed,continue,needs_review',
        ], [
            'start_time.regex' => 'Jam mulai harus format HH:MM (contoh 08:00).',
            'end_time.regex'   => 'Jam selesai harus format HH:MM (contoh 09:00).',
        ]);

        // Durasi dikosongkan + jam mulai/selesai terisi -> hitung dari rentang
        // jam. Jam selesai lebih kecil dari jam mulai diartikan pekerjaan yang
        // melewati tengah malam (ditambah 24 jam, lihat Report::durasiDariJam()).
        // Bila durasi kosong DAN jam juga kosong, nilai lama DIPERTAHANKAN
        // (kolom dihapus dari data update) supaya angka man hour tidak hilang
        // karena kolom terlewat; isi 0 bila memang ingin dinolkan.
        $durasiDikirim = $validated['work_duration_minutes'] ?? null;

        if ($durasiDikirim === null || $durasiDikirim === '') {
            $durasi = Report::durasiDariJam($validated['start_time'] ?? null, $validated['end_time'] ?? null);

            if ($durasi !== null) {
                $validated['work_duration_minutes'] = $durasi;
            } else {
                unset($validated['work_duration_minutes']);
            }
        }

        // Status yang dikirim form edit ikut menyetel completed_at seperti
        // tombol status di halaman detail: 'completed' -> waktu selesai diisi
        // (nilai lama dipertahankan bila sudah pernah selesai), status lain
        // (continue / needs_review) mengosongkan waktu selesai.
        if (! empty($validated['status'])) {
            $validated['completed_at'] = $validated['status'] === 'completed'
                ? ($report->completed_at ?? now())
                : null;
        } else {
            unset($validated['status']);
        }

        // Validasi khusus dCM: sesuai_rencana wajib jika jenis diisi dCM
        if (($validated['jenis_pekerjaan'] ?? '') === 'dCM' && empty($validated['sesuai_rencana'])) {
            return back()->withErrors(['sesuai_rencana' => 'Untuk pekerjaan dCM, field "Sesuai Rencana" wajib diisi.'])
                ->withInput();
        }

        // Validasi non-dCM: null-kan sesuai_rencana
        if (($validated['jenis_pekerjaan'] ?? '') !== 'dCM') {
            $validated['sesuai_rencana'] = null;
        }

        // Jaring pengaman: bila form tidak mengirim jenis pekerjaan sama sekali
        // (isian dikosongkan), klasifikasi IT yang sudah tercatat tidak ikut
        // terhapus — jenis ini dulu hanya bisa dipilih dari wizard Telegram.
        if (empty($validated['jenis_pekerjaan']) && $report->jenis_pekerjaan === 'IT') {
            $validated['jenis_pekerjaan'] = 'IT';
        }

        $validated['is_manually_edited'] = true;

        $report->update($validated);

        return redirect()->route('reports.show', $report)
            ->with('success', 'Laporan berhasil diperbarui.');
    }

    public function updateStatus(Request $request, Report $report)
    {
        $request->validate([
            // 'draft' sudah tidak dipakai lagi (lihat migration 2026_09_26_000005).
            'status' => 'required|in:needs_review,completed,continue',
        ]);

        $data = ['status' => $request->status];
        if ($request->status === 'completed') {
            $data['completed_at'] = now();
        } else {
            $data['completed_at'] = null;
        }

        $report->update($data);

        return back()->with('success', 'Status laporan berhasil diperbarui.');
    }

    /**
     * Tambah foto ke laporan lewat upload manual admin (web), bukan dari bot.
     * Disk & folder mengikuti config('telegram.photo_disk'/'photo_folder')
     * yang sama dipakai PhotoStorageService, supaya accessor URL di Model
     * Report tetap konsisten untuk foto dari sumber manapun.
     *
     * Mendukung dua bentuk kiriman:
     *   - `photo`   : satu berkas (dipakai form di halaman detail laporan).
     *   - `photos[]`: banyak berkas sekaligus (dipakai halaman edit laporan).
     *
     * Balasan JSON (berisi daftar URL terbaru) bila pemanggilnya fetch, atau
     * redirect kembali bila pemanggilnya form biasa.
     *
     * @param  Request  $request  Permintaan berisi berkas foto dan jenisnya
     * @param  Report   $report   Laporan yang diberi foto
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function addPhoto(Request $request, Report $report)
    {
        $maxKb = (int) (config('telegram.photo_max_bytes', 20 * 1024 * 1024) / 1024);

        $request->validate([
            'photo'    => "nullable|image|mimes:jpg,jpeg,png,webp|max:{$maxKb}",
            'photos'   => 'nullable|array',
            'photos.*' => "image|mimes:jpg,jpeg,png,webp|max:{$maxKb}",
            'type'     => 'required|in:documentation,hygiene',
        ]);

        $berkas = $this->kumpulkanBerkasFoto($request);

        if (($galat = $this->penjagaFotoInduk($report)) !== null) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $galat], 422);
            }

            return back()->with('error', $galat);
        }

        if ($berkas === []) {
            $pesan = 'Tidak ada berkas foto yang dikirim.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $pesan], 422);
            }

            return back()->with('error', $pesan);
        }

        $disk   = config('telegram.photo_disk', 'public');
        $folder = config('telegram.photo_folder', 'reports');
        $kolom  = $request->type === 'hygiene' ? 'photo_hygiene_clearance' : 'photo_documentation';

        $photos = $report->{$kolom} ?? [];

        foreach ($berkas as $file) {
            $photos[] = $file->store(
                "{$folder}/" . now()->format('Y/m/d') . "/{$report->id}",
                $disk
            );
        }

        $report->update([$kolom => array_values($photos)]);

        $pesan = count($berkas) . ' foto berhasil ditambahkan ke laporan.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $pesan,
                'foto'    => [
                    'documentation' => $report->photo_documentation_urls,
                    'hygiene'       => $report->photo_hygiene_urls,
                ],
            ]);
        }

        return back()->with('success', $pesan);
    }

    /**
     * Kumpulkan berkas foto dari permintaan, baik bentuk tunggal (`photo`)
     * maupun bentuk banyak (`photos[]`).
     *
     * @param  Request  $request  Permintaan
     * @return array<int, \Illuminate\Http\UploadedFile>  Berkas foto yang dikirim
     */
    private function kumpulkanBerkasFoto(Request $request): array
    {
        $berkas = [];

        if ($request->hasFile('photo')) {
            $berkas[] = $request->file('photo');
        }

        foreach ((array) $request->file('photos', []) as $file) {
            if ($file !== null) {
                $berkas[] = $file;
            }
        }

        return $berkas;
    }

    /**
     * Foto hanya boleh diubah pada laporan INDUK. Baris salinan kolaborasi dan
     * salinan multi alat memakai PATH FOTO YANG SAMA dengan induk, sehingga
     * menghapus foto lewat baris salinan akan ikut menghapus berkas milik
     * laporan induk (dan menyisakan path kosong di sana).
     *
     * @param  Report  $report  Laporan yang sedang diproses
     * @return string|null      Pesan galat, null bila foto boleh diubah
     */
    private function penjagaFotoInduk(Report $report): ?string
    {
        if ($report->collaborator_of !== null || $report->multi_of !== null) {
            return 'Foto hanya bisa diubah dari laporan induk (laporan ini baris salinan).';
        }

        return null;
    }

    /**
     * Hapus satu foto dari laporan (dokumentasi atau hygiene clearance).
     * Dipanggil via fetch() dari halaman detail — return JSON, tidak redirect.
     * File fisik dihapus dari storage; array di DB di-splice dan di-reindex.
     *
     * @param  Request  $request  Query param: type (documentation|hygiene)
     * @param  Report   $report   Laporan yang fotonya dihapus.
     * @param  int      $index    Index foto dalam array JSON (0-based).
     * @return \Illuminate\Http\JsonResponse
     */
    public function deletePhoto(Request $request, Report $report, int $index)
    {
        $request->validate([
            'type' => 'required|in:documentation,hygiene',
        ]);

        // Baris salinan memakai path foto yang sama dengan laporan induk,
        // jadi penghapusan foto hanya boleh dari laporan induk.
        if (($galat = $this->penjagaFotoInduk($report)) !== null) {
            return response()->json(['success' => false, 'message' => $galat], 422);
        }

        // Tentukan kolom DB berdasarkan tipe foto
        $column = $request->type === 'hygiene'
            ? 'photo_hygiene_clearance'
            : 'photo_documentation';

        $photos = $report->{$column} ?? [];

        // Pastikan index valid sebelum proses apapun
        if (!array_key_exists($index, $photos)) {
            return response()->json([
                'success' => false,
                'message' => 'Foto tidak ditemukan pada index tersebut.',
            ], 404);
        }

        // Hapus file fisik dari storage (gagal diam-diam jika file sudah tidak ada)
        $disk = config('telegram.photo_disk', 'public');
        Storage::disk($disk)->delete($photos[$index]);

        // Buang elemen dari array, pastikan tetap sequential (bukan associative)
        // agar disimpan sebagai JSON array bukan JSON object
        array_splice($photos, $index, 1);

        $report->update([$column => array_values($photos)]);

        return response()->json(['success' => true]);
    }

    public function destroy(Report $report)
    {
        $report->delete();
        return redirect()->route('reports.index')
            ->with('success', 'Laporan berhasil dihapus.');
    }
}
