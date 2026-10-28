<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\HariLibur;
use App\Models\ShiftSchedule;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Services\Shift\ManHourService;
use App\Services\Shift\ShiftPatternService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TechnicianController extends Controller
{
    public function index(Request $request)
    {
        $query = Technician::with(['department', 'approver']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by department
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Filter by group
        if ($request->filled('group')) {
            $query->where('group', $request->group);
        }

        // Filter by section
        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%")
                  ->orWhere('telegram_username', 'like', "%{$search}%");
            });
        }

        // Tautan paginasi hanya membawa filter daftar. Parameter kalender
        // (jadwal_bulan/buka_kalender) sengaja TIDAK diikutkan supaya popup
        // kalender tidak ikut terbuka saat berpindah halaman (perbaikan
        // 15/10/2026).
        $technicians = $query->latest()
            ->paginate(20)
            ->appends($request->only(['status', 'department_id', 'group', 'section', 'search']));
        $departments = app(\App\Services\LocationHierarchyService::class)->semuaDepartemen();

        // Ringkasan man hour periode berjalan untuk kolom di tabel teknisi.
        $manHour = app(ManHourService::class);

        [$mulaiManHour, $selesaiManHour, $labelPeriode] = $this->rentangManHour();

        // Jumlah hari rentang dipakai untuk menghitung laju per minggu sehingga
        // perbandingan antar tipe jadwal setara (39 jam/minggu vs 42 jam/minggu).
        $jumlahHariPeriode = (int) $mulaiManHour->diffInDays($selesaiManHour) + 1;

        $idTeknisi   = $technicians->pluck('id')->all();
        $menitKerja  = $manHour->menitPerTeknisi($idTeknisi, $mulaiManHour, $selesaiManHour);
        $targetKerja = $manHour->targetPerTeknisi($idTeknisi, $mulaiManHour, $selesaiManHour);

        // Info jadwal shift: file terakhir yang diimport (acuan pola rotasi).
        $shiftTerakhir = ShiftSchedule::latest('id')->first();

        // Ringkasan jadwal yang tersimpan sekarang (dihitung dari data aktual,
        // bukan angka historis hasil import) supaya keterangannya selalu benar.
        $ringkasanJadwal = [
            'jumlah_baris'     => TechnicianShift::count(),
            'jumlah_teknisi'   => TechnicianShift::whereNotNull('technician_id')->distinct()->count('technician_id'),
            'belum_terhubung'  => TechnicianShift::whereNull('technician_id')->count(),
            'periode_mulai'    => TechnicianShift::min('shift_date'),
            'periode_selesai'  => TechnicianShift::max('shift_date'),
        ];

        // Preview jadwal bulanan (popup): default bulan berjalan, bisa digeser
        // lewat parameter ?jadwal_bulan=YYYY-MM.
        $bulanJadwal = $this->bulanJadwalDariRequest($request);

        $jadwalBulanan = app(ShiftPatternService::class)->jadwalBulan($bulanJadwal);

        // Hari libur nasional bulan yang ditampilkan: peta (tanggal => nama)
        // untuk penanda kalender, dan daftar modelnya untuk pengelolaan.
        $daftarHariLibur = HariLibur::query()
            ->antara(
                $bulanJadwal->copy()->startOfMonth()->toDateString(),
                $bulanJadwal->copy()->endOfMonth()->toDateString()
            )
            ->orderBy('tanggal')
            ->get();

        $hariLiburBulanan = $daftarHariLibur
            ->mapWithKeys(fn (HariLibur $hari) => [$hari->tanggal->toDateString() => (string) $hari->nama])
            ->all();

        return view('technicians.index', compact(
            'technicians',
            'departments',
            'menitKerja',
            'targetKerja',
            'labelPeriode',
            'jumlahHariPeriode',
            'shiftTerakhir',
            'ringkasanJadwal',
            'jadwalBulanan',
            'hariLiburBulanan',
            'daftarHariLibur'
        ));
    }

    /**
     * Baca parameter bulan jadwal dari request (?jadwal_bulan=YYYY-MM) dengan
     * fallback ke bulan berjalan bila kosong atau tidak valid.
     *
     * @param  Request $request Request halaman daftar teknisi
     * @return \Illuminate\Support\Carbon Bulan yang akan ditampilkan
     */
    private function bulanJadwalDariRequest(Request $request): \Illuminate\Support\Carbon
    {
        $bulan = (string) $request->input('jadwal_bulan', '');

        if (preg_match('/^\d{4}-\d{2}$/', $bulan) === 1) {
            return Carbon::createFromFormat('Y-m-d', $bulan . '-01')->startOfMonth();
        }

        return now()->startOfMonth();
    }

    /**
     * Rentang tanggal man hour daftar/detail teknisi: periode 26-25 berjalan.
     *
     * Periode dihitung dari tanggal hari ini, bukan dari file jadwal terakhir:
     * - tanggal 26 sampai akhir bulan  -> mulai tanggal 26 bulan ini;
     * - tanggal 1 sampai 25           -> mulai tanggal 26 bulan lalu.
     * Selesai selalu tanggal 25 bulan berikutnya. Dengan begitu periode
     * bergeser otomatis saat tanggal 26 tiba.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon, 2: string}
     *         [tanggal mulai, tanggal selesai, label periode]
     */
    private function rentangManHour(): array
    {
        $hariIni = now()->startOfDay();

        $mulai = $hariIni->day >= 26
            ? $hariIni->copy()->startOfMonth()->addDays(25)
            : $hariIni->copy()->subMonthNoOverflow()->startOfMonth()->addDays(25);

        $selesai = $mulai->copy()->addMonthNoOverflow()->subDay();

        return [
            $mulai,
            $selesai,
            $mulai->format('d/m/Y') . ' - ' . $selesai->format('d/m/Y'),
        ];
    }

    /**
     * Parameter halaman Laporan yang sesuai tipe jadwal teknisi.
     *
     * Pekerja shift dibuka pada tampilan Shift beserta grubnya; teknisi lain
     * pada tampilan Reguler. Dipakai tautan tabel, grafik, dan pemuatan bulan.
     *
     * @param  Technician $technician Teknisi yang dibuka
     * @return array<string, mixed>   Parameter query halaman Laporan
     */
    private function parameterLaporan(Technician $technician): array
    {
        $tampilan = Technician::grupShift($technician->group) ? 'shift' : 'reguler';

        return array_filter([
            'technician_id' => $technician->id,
            'tampilan'      => $tampilan,
            'grub'          => $tampilan === 'shift' ? $technician->group : null,
        ], fn ($nilai) => $nilai !== null && $nilai !== '');
    }

    public function create()
    {
        $departments = app(\App\Services\LocationHierarchyService::class)->semuaDepartemen();
        $groups = \App\Models\Technician::GROUPS;
        $sections = \App\Models\Technician::SECTIONS;
        return view('technicians.create', compact('departments', 'groups', 'sections'));
    }

    public function store(Request $request, TelegramService $telegram)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'nik' => 'nullable|string|max:50|unique:technicians,nik',
            'telegram_id' => 'nullable|string|max:50|unique:technicians,telegram_id',
            'telegram_username' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'group' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::GROUPS)),
            'section' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::SECTIONS)),
            'status' => 'required|in:pending,active',
            'send_notification' => 'boolean',
        ]);

        $technician = Technician::create([
            'name' => $validated['name'],
            'nik' => $validated['nik'] ?? null,
            'telegram_id' => $validated['telegram_id'] ?? null,
            'telegram_username' => $validated['telegram_username'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'group' => $validated['group'] ?? null,
            'section' => $validated['section'] ?? null,
            'status' => $validated['status'],
            'approved_by' => $validated['status'] === 'active' ? Auth::id() : null,
            'approved_at' => $validated['status'] === 'active' ? now() : null,
        ]);

        // Broadcast jika diminta dan teknisi punya telegram
        $broadcasted = false;
        if (
            $request->boolean('send_notification') &&
            $technician->telegram_id &&
            $technician->status === 'active'
        ) {
            $message = "🎉 Hai {$technician->name}!\n\n";
            $message .= "Akun teknisi Anda telah berhasil dibuat dan aktif.\n\n";
            $message .= "📋 <b>Identitas:</b>\n";
            $message .= "Nama: {$technician->name}\n";
            if ($technician->nik) $message .= "NIK: {$technician->nik}\n";
            $message .= "\nAnda dapat mulai melaporkan pekerjaan harian melalui bot ini.\n";
            $message .= "Kirim laporan Anda sekarang!";

            $broadcasted = $telegram->sendMessage($technician->telegram_id, $message);
        }

        $message = 'Teknisi berhasil ditambahkan.';
        if ($broadcasted) {
            $message .= ' Notasi Telegram terkirim.';
        } elseif ($request->boolean('send_notification') && !$technician->telegram_id) {
            $message .= ' Notifikasi tidak dikirim (teknisi belum punya Telegram ID).';
        }

        return redirect()->route('technicians.show', $technician)
            ->with('success', $message);
    }

    public function edit(Technician $technician)
    {
        $departments = app(\App\Services\LocationHierarchyService::class)->semuaDepartemen();

        // Pertahankan departemen lama yang belum ada di hierarki FuncLoc agar
        // nilai teknisi tidak hilang saat form edit dibuka.
        if ($technician->department_id !== null
            && ! $departments->contains(fn ($dept) => (int) $dept->id === (int) $technician->department_id)) {
            $lama = Department::find($technician->department_id);

            if ($lama !== null) {
                $departments->push((object) [
                    'id'   => $lama->id,
                    'code' => $lama->code,
                    'name' => $lama->name,
                ]);
            }
        }

        $groups = \App\Models\Technician::GROUPS;
        $sections = \App\Models\Technician::SECTIONS;
        return view('technicians.edit', compact('technician', 'departments', 'groups', 'sections'));
    }

    public function update(Request $request, Technician $technician, TelegramService $telegram)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'nik' => 'nullable|string|max:50|unique:technicians,nik,' . $technician->id,
            'telegram_id' => 'nullable|string|max:50|unique:technicians,telegram_id,' . $technician->id,
            'telegram_username' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'group' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::GROUPS)),
            'section' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::SECTIONS)),
            'status' => 'required|in:pending,active,suspended',
        ]);

        $technician->update($validated);

        // Kirim notifikasi jika diaktifkan dan punya telegram
        if (
            $technician->wasChanged('status') &&
            $technician->status === 'active' &&
            $technician->telegram_id
        ) {
            $message = "✅ Akun teknisi Anda telah diaktifkan!\n\n";
            $message .= "Anda sekarang dapat melaporkan pekerjaan harian melalui bot ini.";
            $telegram->sendMessage($technician->telegram_id, $message);
        }

        return redirect()->route('technicians.show', $technician)
            ->with('success', 'Data teknisi berhasil diperbarui.');
    }

    public function show(Technician $technician)
    {
        $technician->load(['department', 'approver']);

        // Pekerjaan harian teknisi untuk tabel di halaman detail: seluruh riwayat
        // terbaru (bukan hanya 20) dengan relasi lokasi/alat agar bisa diklik.
        $laporanTerbaru = $technician->reports()
            // Relasi pembuat ikut dimuat karena lencana "Kolaborasi : <nama
            // pembuat>" pada baris salinan kolaborasi membacanya per baris.
            ->with(['asset', 'functionalLocation', 'area', 'creator', 'parentReport.creator', 'parentReport.technician'])
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->take(60)
            ->get();

        $stats = [
            'total_reports' => $technician->reports()->count(),
            'completed_reports' => $technician->reports()->where('status', 'completed')->count(),
            'pending_reports' => $technician->reports()->where('status', 'continue')->count(),
            'last_report' => $technician->reports()->latest()->first()?->report_date,
            'reports_this_month' => $technician->reports()
                ->whereMonth('report_date', now()->month)
                ->whereYear('report_date', now()->year)
                ->count(),
        ];

        // Statistik koneksi bot untuk seksi Koneksi Bot Telegram
        $botStats = [
            'reports_via_bot'          => $technician->reports()
                ->whereNotNull('telegram_message_id')
                ->count(),
            'laporan_terakhir_via_bot' => $technician->reports()
                ->whereNotNull('telegram_message_id')
                ->latest('report_date')
                ->value('report_date'),
        ];

        // Man hour: realisasi jam kerja vs target sesuai jadwal shift.
        $manHour = app(ManHourService::class);

        [$mulaiManHour, $selesaiManHour, $labelPeriodeManHour] = $this->rentangManHour();

        // Ringkasan kartu Man Hour tetap memakai periode 26-25 berjalan.
        $harianPeriode    = $manHour->harian($technician, $mulaiManHour, $selesaiManHour);
        $manHourRingkasan = $manHour->ringkasan($harianPeriode);

        // Grafik dan tabel memakai satu tahun kalender berjalan (01/01-31/12).
        $tahunManHour = (int) now()->year;
        $awalTahun    = Carbon::create($tahunManHour, 1, 1)->startOfDay();
        $akhirTahun   = Carbon::create($tahunManHour, 12, 31)->endOfDay();
        $grafikHarian = $manHour->harian($technician, $awalTahun, $akhirTahun);

        // Sesi pembagi grafik tetap periode 26-25, termasuk sesi yang mulai
        // sebelum 01/01 dan sesi yang berakhir setelah 31/12.
        $mulaiSesiPertama = $awalTahun->copy()->subMonthNoOverflow()->startOfMonth()->addDays(25);
        $sesiGrafik       = [];
        $mulaiSesi        = $mulaiSesiPertama->copy();

        while ($mulaiSesi->lte($akhirTahun)) {
            $selesaiSesi = $mulaiSesi->copy()->addMonthNoOverflow()->subDay();

            $sesiGrafik[] = [
                'key'     => $mulaiSesi->toDateString(),
                'mulai'   => $mulaiSesi->toDateString(),
                'selesai' => $selesaiSesi->toDateString(),
                'label'   => $mulaiSesi->format('d/m/Y') . ' - ' . $selesaiSesi->format('d/m/Y'),
            ];

            $mulaiSesi->addMonthNoOverflow();
        }

        foreach ($grafikHarian as &$hariGrafik) {
            $tanggalHari = Carbon::parse($hariGrafik['tanggal']);
            $sesiHari    = $tanggalHari->day >= 26
                ? $tanggalHari->copy()->startOfMonth()->addDays(25)
                : $tanggalHari->copy()->subMonthNoOverflow()->startOfMonth()->addDays(25);

            $hariGrafik['sesi']       = $sesiHari->toDateString();
            $hariGrafik['mulai_sesi'] = $tanggalHari->day === 26;
        }
        unset($hariGrafik);

        // Popup Cek Overtime memuat seluruh sesi 26-25 yang menyentuh tahun ini.
        $lemburMulai   = Carbon::parse($sesiGrafik[0]['mulai'])->startOfDay();
        $lemburSelesai = Carbon::parse($sesiGrafik[count($sesiGrafik) - 1]['selesai'])->endOfDay();
        $lemburSemua   = $manHour->lemburPerTanggal($technician, $lemburMulai, $lemburSelesai);
        $lemburPerSesi = [];

        foreach ($sesiGrafik as $sesi) {
            $rincian = array_filter(
                $lemburSemua,
                static fn (string $tanggal): bool => $tanggal >= $sesi['mulai'] && $tanggal <= $sesi['selesai'],
                ARRAY_FILTER_USE_KEY
            );
            ksort($rincian);

            $totalMenit = array_sum(array_column($rincian, 'menit'));

            $lemburPerSesi[$sesi['key']] = $sesi + [
                'total_menit'    => $totalMenit,
                'total_jam'      => number_format($totalMenit / 60, 1, ',', '.'),
                'jumlah_tanggal' => count($rincian),
                'rincian'        => $rincian,
            ];
        }

        // Tampilan awal grafik: tanggal HARI INI di sisi kanan. Tabel fokus ke
        // baris HARI INI (paling atas), lalu memuat bulan lain saat digulir.
        $tanggalHariIni    = now()->toDateString();
        $indeksFokusGrafik = 0;

        foreach ($grafikHarian as $indeksHari => $hariGrafik) {
            if ($hariGrafik['tanggal'] === $tanggalHariIni) {
                $indeksFokusGrafik = $indeksHari;
                break;
            }
        }

        $tanggalFokusTabel = $tanggalHariIni;
        $currentKey        = $mulaiManHour->toDateString();

        // Baris awal tabel = bulan sebelumnya + bulan berjalan + bulan
        // berikutnya; bulan lain dimuat oleh JavaScript saat tabel digulir.
        $bulanSekarang   = (int) now()->month;
        $bulanAwalTabel  = max(1, $bulanSekarang - 1);
        $bulanAkhirTabel = min(12, $bulanSekarang + 1);
        $bulanTabelAwal  = range($bulanAwalTabel, $bulanAkhirTabel);

        $harianTabelAwal = array_values(array_filter(
            $grafikHarian,
            static fn (array $hari): bool => in_array(
                (int) Carbon::parse($hari['tanggal'])->month,
                $bulanTabelAwal,
                true
            )
        ));
        $harianTabelAwal = array_reverse($harianTabelAwal);

        $parameterLaporan = $this->parameterLaporan($technician);

        $shiftHariIni = $technician->shifts()->untukTanggal(now()->toDateString())->first();

        // Bila belum ada baris jadwal (mis. periode berjalan belum diupload),
        // pakai hasil proyeksi pola rotasi supaya badge shift tetap terisi.
        if ($shiftHariIni === null) {
            $kodeHariIni = app(ShiftPatternService::class)->kode($technician->group, now());

            if ($kodeHariIni !== null) {
                $shiftHariIni = new TechnicianShift([
                    'technician_id' => $technician->id,
                    'nik'           => (string) $technician->nik,
                    'nama'          => (string) $technician->name,
                    'shift_date'    => now()->toDateString(),
                    'shift_code'    => $kodeHariIni,
                ]);
            }
        }

        return view('technicians.show', compact(
            'technician',
            'stats',
            'botStats',
            'laporanTerbaru',
            'grafikHarian',
            'harianTabelAwal',
            'tahunManHour',
            'bulanAwalTabel',
            'bulanAkhirTabel',
            'parameterLaporan',
            'manHourRingkasan',
            'lemburPerSesi',
            'currentKey',
            'labelPeriodeManHour',
            'indeksFokusGrafik',
            'tanggalFokusTabel',
            'shiftHariIni'
        ));
    }

    /**
     * Ambil baris Rincian Man Hour Harian untuk satu bulan.
     *
     * Dipakai saat tabel digulir supaya seluruh bulan tidak dirender sekaligus.
     * Rentang tetap dibatasi tahun kalender berjalan di halaman.
     *
     * @param  Request   $request   Request (query tahun opsional)
     * @param  Technician $technician Teknisi yang dibuka
     * @param  int       $bulan     Bulan 1-12
     * @return \Illuminate\Http\Response
     */
    public function manHourBulan(Request $request, Technician $technician, int $bulan)
    {
        if ($bulan < 1 || $bulan > 12) {
            abort(404);
        }

        $tahun = (int) $request->query('tahun', now()->year);

        if ($tahun < 2000 || $tahun > 2100) {
            abort(404);
        }

        $mulai   = Carbon::create($tahun, $bulan, 1)->startOfDay();
        $selesai = $mulai->copy()->endOfMonth();
        $harian  = app(ManHourService::class)->harian($technician, $mulai, $selesai);

        return response()->view('technicians.partials._daftar-baris-manhour', [
            'harian'           => array_reverse($harian),
            'parameterLaporan' => $this->parameterLaporan($technician),
        ]);
    }

    public function approve(Technician $technician)
    {
        $technician->update([
            'status' => 'active',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Teknisi berhasil diaktifkan.');
    }

    public function suspend(Technician $technician)
    {
        $technician->update(['status' => 'suspended']);
        return back()->with('success', 'Teknisi ditangguhkan.');
    }

    public function reactivate(Technician $technician)
    {
        $technician->update(['status' => 'active']);
        return back()->with('success', 'Teknisi diaktifkan kembali.');
    }

    public function bulkApprove(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:technicians,id',
        ]);

        Technician::whereIn('id', $request->ids)
            ->where('status', 'pending')
            ->update([
                'status' => 'active',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

        return back()->with('success', count($request->ids) . ' teknisi berhasil diaktifkan.');
    }

    public function destroy(Technician $technician)
    {
        $technician->delete();
        return redirect()->route('technicians.index')
            ->with('success', 'Teknisi berhasil dihapus.');
    }

    public function broadcast(Request $request, Technician $technician, TelegramService $telegram)
    {
        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $sent = false;
        if ($technician->telegram_id) {
            $sent = $telegram->sendMessage($technician->telegram_id, $validated['message']);
        }

        if ($sent) {
            return response()->json([
                'success' => true,
                'message' => 'Pesan berhasil dikirim ke ' . $technician->name . '.',
            ]);
        } elseif ($technician->telegram_id) {
            return response()->json([
                'success' => true,
                'message' => 'Pesan dicatat (mock mode — token Telegram belum dikonfigurasi).',
            ]);
        } else {
            return response()->json([
                'success' => true,
                'message' => 'Pesan dicatat (teknisi tidak punya Telegram ID).',
            ]);
        }
    }
}

