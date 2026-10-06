<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\PanelRuangan;
use App\Models\Technician;
use App\Services\FunclocOptionService;
use App\Services\PanelAssetSyncService;
use App\Services\PanelNotifikasiService;
use App\Services\PanelRiwayatService;
use App\Services\PanelSensorService;
use App\Http\Controllers\Traits\PanelExportTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Halaman Monitoring Ruang Panel.
 *
 * Menampilkan suhu dan kelembapan tiap ruangan panel secara berkala serta
 * menyediakan pendataan ruangan: form tambah/ubah lengkap (lokasi, kode alat,
 * dan cara baca perangkat) dan halaman detail dengan tombol uji koneksi,
 * tarik data, serta sinkronisasi baris aset.
 *
 * Data diambil dengan cara server menarik sendiri (pull) dari perangkat
 * ESP32, sehingga tidak memerlukan aturan port masuk di firewall.
 *
 * Setiap kali ruangan disimpan, PanelAssetSyncService membuat/memperbarui satu
 * baris cermin di tabel assets (data_source 'panel_sync') supaya ruang panel
 * bisa dipilih sebagai equipment pada laporan.
 */
class PanelRuanganController extends Controller
{
    use PanelExportTrait;

    public function __construct(
        protected PanelSensorService $sensor,
        protected PanelAssetSyncService $penyinkronAset,
        protected FunclocOptionService $pilihanFuncloc,
        protected PanelRiwayatService $riwayat,
        protected PanelNotifikasiService $notifikasi
    ) {
    }

    /**
     * Tampilkan halaman monitoring ruang panel.
     *
     * @return View
     */
    public function index(): View
    {
        return view('panel.index', [
            'daftarRuangan' => PanelRuangan::query()->orderBy('nama_ruangan')->get(),
            'intervalPolling' => (int) config('panel.tampilan.interval_polling_detik', 5),
            'pilihanProtokol' => PanelRuangan::PROTOKOL,
            'titikGrafik' => (int) config('panel.tampilan.jumlah_titik_grafik', 60),
            'barisRiwayat' => (int) config('panel.tampilan.jumlah_baris_riwayat', 20),
            'ambangBawaan' => [
                'atas' => (float) config('panel.ambang.suhu_atas', 30),
                'bawah' => (float) config('panel.ambang.suhu_bawah', 18),
            ],
        ]);
    }

    /**
     * Kembalikan data seluruh ruangan dalam bentuk JSON untuk polling halaman.
     *
     * Sebelum membaca database, data ditarik dari perangkat bila jeda cache
     * sudah lewat. Dengan begitu angka yang tampil di layar tetap segar tanpa
     * membebani perangkat dan database pada setiap polling browser.
     *
     * @return JsonResponse
     */
    public function data(): JsonResponse
    {
        $this->tarikBilaJedaLewat();

        $titik = (int) config('panel.tampilan.jumlah_titik_grafik', 60);
        $baris = (int) config('panel.tampilan.jumlah_baris_riwayat', 20);

        $daftarRuangan = PanelRuangan::query()->with('penanggungJawab')->orderBy('nama_ruangan')->get();

        $hasil = $daftarRuangan->map(function (PanelRuangan $ruangan) use ($titik, $baris): array {
            $data = $this->sensor->dataRuangan($ruangan, $titik, $baris);
            $terakhir = $data['terakhir'];

            return [
                'id'               => $ruangan->id,
                'nama_ruangan'     => $ruangan->nama_ruangan,
                'pt_location'      => $ruangan->pt_location,
                'plant'            => $ruangan->plant,
                'keterangan'       => $ruangan->keterangan,
                'lokasi'           => $ruangan->labelLokasi(),
                'ip_address'       => $ruangan->ip_address,
                'port'             => $ruangan->port,
                'port_tcp'         => $ruangan->portTcpEfektif(),
                'kode_alat'        => $ruangan->kode_alat,
                'protokol'         => $ruangan->protokol ?: 'otomatis',
                'protokol_label'   => $ruangan->labelProtokol(),
                'mac_address'      => $ruangan->mac_address,
                'aktif'            => $ruangan->aktif,
                'online'           => $ruangan->apakahOnline(),
                'status_terakhir'  => $ruangan->status_terakhir,
                'catatan'          => $ruangan->catatan,
                'terakhir_ditarik' => $ruangan->terakhir_ditarik?->format('d/m/Y H:i:s'),
                'ambang_suhu_atas' => $ruangan->ambang_suhu_atas,
                'ambang_suhu_bawah' => $ruangan->ambang_suhu_bawah,
                'suhu'             => $terakhir?->suhu,
                'kelembapan'       => $terakhir?->kelembapan,
                'waktu_pembacaan'  => $terakhir?->waktu?->format('d/m/Y H:i:s'),
                'kondisi'          => $ruangan->kondisiSuhu($terakhir?->suhu),
                'penanggung_jawab' => $ruangan->namaPenanggungJawab(),
                'grafik'           => $data['grafik'],
                'riwayat'          => $data['riwayat'],
            ];
        })->all();

        return response()->json([
            'waktu_server' => now()->format('d/m/Y H:i:s'),
            'ruangan'      => $hasil,
        ]);
    }

    /**
     * Tampilkan form tambah ruangan panel (pengaturan lengkap).
     *
     * @return View
     */
    public function create(): View
    {
        $ruangan = new PanelRuangan([
            'protokol'          => 'otomatis',
            'port'              => 80,
            'aktif'             => true,
            'ambang_suhu_atas'  => (float) config('panel.ambang.suhu_atas', 30),
            'ambang_suhu_bawah' => (float) config('panel.ambang.suhu_bawah', 18),
        ]);

        return view('panel.ruangan.form', $this->dataForm($ruangan));
    }

    /**
     * Simpan ruangan panel baru lalu sinkronkan baris aset cerminnya.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasiRuangan($request);
        $data['aktif']    = $request->boolean('aktif');
        $data['protokol'] = $data['protokol'] ?? 'otomatis';

        $ruangan = PanelRuangan::query()->create($data);
        $this->simpanPenanggungJawab($ruangan, $request->input('penanggung_jawab', []));
        $sinkron = $this->penyinkronAset->syncOne($ruangan);

        return redirect()
            ->route('panel.ruangan.show', $ruangan)
            ->with('success', 'Ruangan panel "' . $ruangan->nama_ruangan . '" berhasil ditambahkan.')
            ->with('hasil_sinkron', $this->pesanSinkron($sinkron, $ruangan));
    }

    /**
     * Tampilkan halaman detail satu ruangan panel: identitas, cara baca
     * perangkat, lokasi + baris aset cermin, grafik pembacaan terakhir, dan
     * rekap riwayat harian periode terpilih (bisa diekspor ke Excel).
     *
     * @param  Request       $request
     * @param  PanelRuangan  $panelRuangan
     * @return View
     */
    public function show(Request $request, PanelRuangan $panelRuangan): View
    {
        $panelRuangan->load(['funcloc', 'pembacaanTerakhir', 'penanggungJawab']);

        [$awal, $akhir] = $this->periodeBulan($request->query('bulan'));
        $harian         = $this->riwayat->harian($panelRuangan, $awal, $akhir);

        return view('panel.ruangan.show', [
            'ruangan'        => $panelRuangan,
            'asetCermin'     => $this->penyinkronAset->asetCermin($panelRuangan),
            'alasanLewat'    => $this->penyinkronAset->alasanLewatSinkron($panelRuangan),
            'portTcpEfektif' => $panelRuangan->portTcpEfektif(),
            'riwayat'        => $panelRuangan->pembacaan()
                ->orderByDesc('waktu')
                ->limit((int) config('panel.tampilan.jumlah_baris_riwayat', 20))
                ->get(),
            'harian'          => $harian,
            'ringkasanHarian' => $this->riwayat->ringkasan($harian),
            'bulanTerpilih'   => $awal->format('Y-m'),
            'labelPeriode'    => $awal->translatedFormat('d F Y') . ' s/d ' . $akhir->translatedFormat('d F Y'),
            'intervalPolling' => (int) config('panel.tampilan.interval_polling_detik', 5),
            'titikGrafik'     => (int) config('panel.tampilan.jumlah_titik_grafik', 60),
        ]);
    }

    /**
     * Tampilkan form ubah ruangan panel.
     *
     * @param  PanelRuangan  $panelRuangan
     * @return View
     */
    public function edit(PanelRuangan $panelRuangan): View
    {
        return view('panel.ruangan.form', $this->dataForm($panelRuangan));
    }

    /**
     * Perbarui data satu ruangan panel lalu sinkronkan baris aset cerminnya.
     *
     * @param  Request       $request
     * @param  PanelRuangan  $panelRuangan
     * @return RedirectResponse
     */
    public function update(Request $request, PanelRuangan $panelRuangan): RedirectResponse
    {
        $data = $this->validasiRuangan($request, $panelRuangan);
        $data['aktif']    = $request->boolean('aktif');
        $data['protokol'] = $data['protokol'] ?? ($panelRuangan->protokol ?: 'otomatis');

        $panelRuangan->update($data);
        $this->simpanPenanggungJawab($panelRuangan, $request->input('penanggung_jawab', []));
        $sinkron = $this->penyinkronAset->syncOne($panelRuangan);

        return redirect()
            ->route('panel.ruangan.show', $panelRuangan)
            ->with('success', 'Data ruangan panel "' . $panelRuangan->nama_ruangan . '" berhasil diperbarui.')
            ->with('hasil_sinkron', $this->pesanSinkron($sinkron, $panelRuangan));
    }

    /**
     * Hapus ruangan panel beserta seluruh riwayat pembacaannya.
     *
     * Baris aset cermin ruangan ini dibuang lebih dulu supaya daftar equipment
     * pada laporan tidak menyisakan alat yang ruangannya sudah tidak ada.
     *
     * @param  PanelRuangan  $panelRuangan
     * @return RedirectResponse
     */
    public function destroy(PanelRuangan $panelRuangan): RedirectResponse
    {
        $nama = $panelRuangan->nama_ruangan;

        $this->penyinkronAset->hapusCermin($panelRuangan);

        $panelRuangan->delete();

        return redirect()
            ->route('panel.index')
            ->with('success', 'Ruangan panel "' . $nama . '" beserta riwayat pembacaannya berhasil dihapus.');
    }

    /**
     * Sinkronkan ulang baris aset cermin milik satu ruangan (tombol
     * "Sinkronkan Aset" pada halaman detail).
     *
     * @param  PanelRuangan  $panelRuangan
     * @return RedirectResponse
     */
    public function sinkronAset(PanelRuangan $panelRuangan): RedirectResponse
    {
        $sinkron = $this->penyinkronAset->syncOne($panelRuangan);

        return redirect()
            ->route('panel.ruangan.show', $panelRuangan)
            ->with('success', $this->pesanSinkron($sinkron, $panelRuangan));
    }

    /**
     * Uji koneksi perangkat sensor pada satu ruangan (tombol Uji Koneksi).
     *
     * @param  PanelRuangan  $panelRuangan
     * @return JsonResponse
     */
    public function ujiKoneksi(PanelRuangan $panelRuangan): JsonResponse
    {
        $hasil = $this->sensor->ujiKoneksi($panelRuangan);

        return response()->json([
            'status'    => 'ok',
            'ruangan'   => $panelRuangan->nama_ruangan,
            'ping'      => $hasil['ping'],
            'perangkat' => $hasil['status'],
            'pembacaan' => $hasil['pembacaan'],
            'sumber'    => $hasil['sumber'],
            'protokol'  => $hasil['protokol'],
            'pesan'     => $this->pesanUjiKoneksi($hasil),
        ]);
    }

    /**
     * Tarik data satu ruangan saat itu juga (tombol Tarik Sekarang).
     *
     * @param  PanelRuangan  $panelRuangan
     * @return JsonResponse
     */
    public function tarik(PanelRuangan $panelRuangan): JsonResponse
    {
        $hasil = $this->sensor->tarikPembacaan($panelRuangan);

        return response()->json([
            'status' => $hasil['berhasil'] ? 'ok' : 'gagal',
            'pesan'  => $hasil['pesan'],
            'baru'   => $hasil['baru'],
        ]);
    }

    /**
     * Data grafik realtime satu ruangan untuk halaman detail (dipanggil berkala
     * oleh JavaScript halaman itu).
     *
     * Sebelum membaca database, data ruangan ini ditarik dari perangkat bila
     * jeda cache sudah lewat, sehingga angka di layar tetap segar tanpa
     * membebani perangkat pada setiap polling.
     *
     * @param  PanelRuangan  $panelRuangan
     * @return JsonResponse
     */
    public function dataRuangan(PanelRuangan $panelRuangan): JsonResponse
    {
        $this->tarikRuanganBilaJedaLewat($panelRuangan);
        $panelRuangan->refresh();

        $titik = (int) config('panel.tampilan.jumlah_titik_grafik', 60);
        $data  = $this->sensor->dataRuangan($panelRuangan, $titik, 1);
        $terakhir = $data['terakhir'];

        return response()->json([
            'waktu_server' => now()->format('d/m/Y H:i:s'),
            'ruangan'      => [
                'id'                => $panelRuangan->id,
                'nama_ruangan'      => $panelRuangan->nama_ruangan,
                'lokasi'            => $panelRuangan->labelLokasi(),
                'online'            => $panelRuangan->apakahOnline(),
                'kondisi'           => $panelRuangan->kondisiSuhu($terakhir?->suhu),
                'ambang_suhu_atas'  => $panelRuangan->ambang_suhu_atas,
                'ambang_suhu_bawah' => $panelRuangan->ambang_suhu_bawah,
                'suhu'              => $terakhir?->suhu,
                'kelembapan'        => $terakhir?->kelembapan,
                'waktu_pembacaan'   => $terakhir?->waktu?->format('d/m/Y H:i:s'),
                'terakhir_ditarik'  => $panelRuangan->terakhir_ditarik?->format('d/m/Y H:i:s'),
                'catatan'           => $panelRuangan->catatan,
                'grafik'            => $data['grafik'],
            ],
        ]);
    }

    /**
     * Unduh rekap riwayat harian satu ruangan dalam bentuk berkas Excel.
     *
     * @param  Request       $request
     * @param  PanelRuangan  $panelRuangan
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportHarian(Request $request, PanelRuangan $panelRuangan)
    {
        [$awal, $akhir] = $this->periodeBulan($request->query('bulan'));
        $harian         = $this->riwayat->harian($panelRuangan, $awal, $akhir);

        return $this->exportHarianExcel(
            $panelRuangan,
            $awal->format('d/m/Y') . ' s/d ' . $akhir->format('d/m/Y'),
            $harian,
            $this->riwayat->ringkasan($harian)
        );
    }

    /**
     * Kirim pengingat Telegram sekarang juga kepada penanggung jawab ruangan
     * ini (tombol "Kirim Pengingat" pada halaman detail). Dipakai administrator
     * untuk memastikan pengingat harian benar-benar sampai ke teknisi.
     *
     * @param  PanelRuangan  $panelRuangan
     * @return JsonResponse
     */
    public function kirimPengingat(PanelRuangan $panelRuangan): JsonResponse
    {
        $idTeknisi = $panelRuangan->penanggungJawab()->pluck('technicians.id')->all();

        if ($idTeknisi === []) {
            return response()->json([
                'status' => 'gagal',
                'pesan'  => 'Ruangan ini belum punya penanggung jawab, jadi belum ada penerima pengingat.',
            ]);
        }

        $ringkasan = $this->notifikasi->kirimKeTeknisi($idTeknisi);

        if ($ringkasan['terkirim'] === 0) {
            return response()->json([
                'status' => 'gagal',
                'pesan'  => 'Pengingat belum terkirim: ' . $ringkasan['dilewati']
                    . ' penerima tanpa Telegram ID dan ' . $ringkasan['gagal']
                    . ' gagal. Pastikan penanggung jawab sudah punya Telegram ID dan bot aktif.',
            ]);
        }

        $pesan = 'Pengingat terkirim kepada ' . $ringkasan['terkirim'] . ' penanggung jawab.';

        if ($ringkasan['gagal'] > 0) {
            $pesan .= ' ' . $ringkasan['gagal'] . ' gagal terkirim.';
        }

        if ($ringkasan['dilewati'] > 0) {
            $pesan .= ' ' . $ringkasan['dilewati'] . ' dilewati karena belum punya Telegram ID.';
        }

        return response()->json(['status' => 'ok', 'pesan' => $pesan]);
    }

    /**
     * Tentukan rentang tanggal dari pilihan bulan berformat Y-m.
     *
     * Isian yang tidak sah (mis. "2026-13" atau teks bebas) dikembalikan ke
     * bulan berjalan supaya halaman detail tidak pernah gagal karena parameter
     * alamat.
     *
     * @param  mixed  $bulan  Isian dari alamat (bisa null)
     * @return array{0: Carbon, 1: Carbon} Tanggal awal dan akhir bulan
     */
    private function periodeBulan(mixed $bulan): array
    {
        $awal = null;

        if (is_string($bulan) && $bulan !== '') {
            try {
                $awal = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
            } catch (\Throwable $e) {
                $awal = null;
            }
        }

        $awal ??= now()->startOfMonth();

        return [$awal, $awal->copy()->endOfMonth()];
    }

    /**
     * Tarik data SATU ruangan bila jeda cache sudah terlewati.
     *
     * Dipakai halaman detail ruangan; berbeda dengan tarikBilaJedaLewat() yang
     * menarik seluruh ruangan karena dipakai dashboard.
     *
     * @param  PanelRuangan  $ruangan
     * @return void
     */
    private function tarikRuanganBilaJedaLewat(PanelRuangan $ruangan): void
    {
        $jedaDetik = max(0, (int) config('panel.pull.cache_detik', 3));
        $kunci = 'panel:tarik-ruangan:' . $ruangan->id;
        $terakhir = (int) Cache::get($kunci, 0);

        if ($jedaDetik > 0 && (now()->getTimestamp() - $terakhir) < $jedaDetik) {
            return;
        }

        // Tandai lebih dulu supaya polling berikutnya tidak menumpuk.
        Cache::put($kunci, now()->getTimestamp(), now()->addMinutes(10));

        if (!(bool) config('panel.pull.enabled', true)) {
            return;
        }

        $this->sensor->tarikPembacaan($ruangan);
    }

    /**
     * Tarik data seluruh ruangan aktif bila jeda cache sudah terlewati.
     *
     * Dipakai sebelum data dikirim ke browser, supaya angka yang tampil di
     * halaman panel tetap segar saat halaman sedang dibuka, tanpa memanggil
     * perangkat pada setiap polling.
     *
     * @return void
     */
    private function tarikBilaJedaLewat(): void
    {
        $jedaDetik = max(0, (int) config('panel.pull.cache_detik', 3));
        $kunci = 'panel:tarik-terakhir';
        $terakhir = (int) Cache::get($kunci, 0);

        if ($jedaDetik > 0 && (now()->getTimestamp() - $terakhir) < $jedaDetik) {
            return;
        }

        // Tandai lebih dulu supaya polling berikutnya tidak menumpuk.
        Cache::put($kunci, now()->getTimestamp(), now()->addMinutes(10));

        if (!(bool) config('panel.pull.enabled', true)) {
            return;
        }

        $this->sensor->tarikSemua();
    }

    /**
     * Data yang dipakai form tambah/ubah ruangan panel.
     *
     * @param  PanelRuangan  $ruangan  Ruangan yang sedang diisi (bisa instance baru)
     * @return array<string, mixed>
     */
    private function dataForm(PanelRuangan $ruangan): array
    {
        return [
            'ruangan'         => $ruangan,
            'pilihanProtokol' => PanelRuangan::PROTOKOL,
            'funclocGroups'   => $this->pilihanFuncloc->grupPerPt(),
            'ambangBawaan'    => [
                'atas'  => (float) config('panel.ambang.suhu_atas', 30),
                'bawah' => (float) config('panel.ambang.suhu_bawah', 18),
            ],
            'portTcpBawaan'   => (int) config('panel.sketch.port_tcp', 5000),
            'asetCermin'      => $this->penyinkronAset->asetCermin($ruangan),
            'alasanLewat'     => $ruangan->exists
                ? $this->penyinkronAset->alasanLewatSinkron($ruangan)
                : null,
            'pilihanTeknisi'  => $this->pilihanTeknisi(),
            'penanggungJawabTerpilih' => $ruangan->exists
                ? $ruangan->penanggungJawab()->pluck('technicians.id')->all()
                : [],
        ];
    }

    /**
     * Daftar teknisi yang bisa dipilih sebagai penanggung jawab ruang panel,
     * dikelompokkan per Bagian supaya pilihan di form mudah ditelusuri.
     *
     * Teknisi tanpa Telegram ID tetap ditampilkan (supaya nama penanggung
     * jawab tetap bisa dicatat) tetapi diberi tanda "tanpa Telegram" karena
     * pengingat harian tidak bisa dikirim ke mereka.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function pilihanTeknisi(): array
    {
        $grup = [];

        Technician::query()
            ->orderBy('name')
            ->get(['id', 'name', 'nik', 'section', 'group', 'telegram_id', 'status'])
            ->each(function (Technician $teknisi) use (&$grup): void {
                $bagian = Technician::SECTIONS[$teknisi->section ?? ''] ?? ($teknisi->section ?: 'Bagian lain');

                $grup[$bagian][] = [
                    'id'           => $teknisi->id,
                    'nama'         => $teknisi->name,
                    'nik'          => $teknisi->nik,
                    'ada_telegram' => $teknisi->telegram_id !== null && $teknisi->telegram_id !== '',
                ];
            });

        return $grup;
    }

    /**
     * Simpan daftar penanggung jawab ruang panel dari isian form.
     *
     * Nilai kosong/duplikat disaring supaya sync tidak gagal karena kunci unik
     * pasangan (ruangan, teknisi). Method ini juga menjadi jalan menghapus
     * seluruh penanggung jawab (mengirim daftar kosong).
     *
     * @param  PanelRuangan  $ruangan  Ruangan yang sedang disimpan
     * @param  mixed         $daftar   Isian penanggung_jawab[] dari form
     * @return void
     */
    private function simpanPenanggungJawab(PanelRuangan $ruangan, mixed $daftar): void
    {
        $idTeknisi = collect(is_array($daftar) ? $daftar : [])
            ->map(fn (mixed $nilai): int => (int) $nilai)
            ->filter(fn (int $nilai): bool => $nilai > 0)
            ->unique()
            ->values()
            ->all();

        $ruangan->penanggungJawab()->sync($idTeknisi);
    }

    /**
     * Validasi input form ruangan panel.
     *
     * @param  Request            $request
     * @param  PanelRuangan|null  $ruangan  Diisi saat mengubah data yang sudah ada.
     * @return array<string, mixed> Data yang sudah tervalidasi.
     */
    private function validasiRuangan(Request $request, ?PanelRuangan $ruangan = null): array
    {
        $aturan = [
            'nama_ruangan' => [
                'required',
                'string',
                'max:100',
                Rule::unique('panel_ruangan', 'nama_ruangan')->ignore($ruangan?->id),
            ],
            'pt_location'       => ['nullable', 'string', 'max:100'],
            'plant'             => ['nullable', 'string', 'max:50'],
            'keterangan'        => ['nullable', 'string', 'max:255'],
            'ip_address'        => ['required', 'string', 'ip'],
            'port'              => ['required', 'integer', 'min:1', 'max:65535'],
            'port_tcp'          => ['nullable', 'integer', 'min:1', 'max:65535'],
            'protokol'          => ['nullable', 'string', Rule::in(array_keys(PanelRuangan::PROTOKOL))],
            'mac_address'       => ['nullable', 'string', 'max:17'],
            'ambang_suhu_atas'  => ['required', 'numeric', 'between:-50,150'],
            'ambang_suhu_bawah' => ['required', 'numeric', 'between:-50,150'],
            'funcloc_id'        => ['nullable', 'integer', 'exists:functional_locations,id'],
            'kode_alat'         => ['nullable', 'string', 'max:100', $this->penjagaKodeAlat($ruangan)],
            'equipment_type'    => ['nullable', 'string', 'max:100'],
            'penanggung_jawab'   => ['nullable', 'array'],
            'penanggung_jawab.*' => ['integer', 'exists:technicians,id'],
        ];

        $namaAtribut = [
            'nama_ruangan'      => 'nama ruangan',
            'pt_location'       => 'PT location',
            'plant'             => 'plant',
            'keterangan'        => 'keterangan',
            'ip_address'        => 'alamat IP perangkat',
            'port'              => 'port HTTP perangkat',
            'port_tcp'          => 'port JSON TCP perangkat',
            'protokol'          => 'protokol perangkat',
            'mac_address'       => 'alamat MAC',
            'ambang_suhu_atas'  => 'ambang suhu atas',
            'ambang_suhu_bawah' => 'ambang suhu bawah',
            'funcloc_id'        => 'lokasi (Functional Location)',
            'kode_alat'         => 'kode alat',
            'equipment_type'    => 'jenis alat',
            'penanggung_jawab'   => 'penanggung jawab',
            'penanggung_jawab.*' => 'penanggung jawab',
        ];

        return $request->validate($aturan, [], $namaAtribut);
    }

    /**
     * Penjaga Kode Alat: menolak kode yang sudah dipakai baris aset lain
     * (perangkat IT atau ruang panel lain) supaya pencarian equipment tidak
     * ambigu. Baris aset lama bertipe impor/manual yang berkode sama TETAP
     * diperbolehkan, karena PanelAssetSyncService akan mengadopsinya.
     *
     * @param  PanelRuangan|null  $ruangan  Ruangan yang sedang diubah
     * @return \Closure  Aturan validasi tambahan
     */
    private function penjagaKodeAlat(?PanelRuangan $ruangan): \Closure
    {
        return function (string $attribute, $value, $fail) use ($ruangan): void {
            $kode = trim((string) $value);

            if ($kode === '') {
                return;
            }

            $bentrok = Asset::query()
                ->where('tech_ident_no', $kode)
                // Baris cermin milik ruangan ini sendiri tidak dihitung bentrok
                // (kalau tidak, menyimpan perubahan pada kode alat yang sama
                // selalu ditolak).
                ->when($ruangan !== null, function ($query) use ($ruangan): void {
                    $query->where(function ($sub) use ($ruangan): void {
                        $sub->whereNull('panel_ruangan_id')
                            ->orWhere('panel_ruangan_id', '!=', $ruangan->id);
                    });
                })
                // Bentrok hanya bila baris itu milik modul lain (mis. perangkat
                // IT) atau sudah dipakai ruangan panel lain. Baris aset lama
                // bertipe impor/manual tetap diperbolehkan karena akan diadopsi.
                ->where(function ($query): void {
                    $query->whereIn('data_source', ['it_sync', 'panel_sync'])
                        ->orWhereNotNull('panel_ruangan_id');
                })
                ->exists();

            if ($bentrok) {
                $fail('Kode alat ini sudah dipakai baris aset lain (perangkat IT atau ruang panel lain). Gunakan kode yang berbeda.');
            }
        };
    }

    /**
     * Pesan hasil sinkronisasi baris aset cermin ruangan.
     *
     * @param  string        $hasil    Aksi hasil syncOne(): created, adopted, updated, skipped
     * @param  PanelRuangan  $ruangan
     * @return string
     */
    private function pesanSinkron(string $hasil, PanelRuangan $ruangan): string
    {
        return match ($hasil) {
            'created' => 'Baris aset ruangan ini dibuat otomatis di Asset Management (sumber: ruang panel).',
            'adopted' => 'Baris aset lama dengan kode alat yang sama disambungkan ke ruangan ini.',
            'updated' => 'Baris aset ruangan ini diperbarui (lokasi, kode alat, dan jenis alat).',
            default   => 'Ruangan ini belum bisa disinkronkan ke daftar aset: '
                . ($this->penyinkronAset->alasanLewatSinkron($ruangan) ?? 'penyebab tidak diketahui.'),
        };
    }

    /**
     * Susun pesan hasil uji koneksi agar mudah ditindaklanjuti.
     *
     * @param  array<string, mixed>  $hasil  Hasil PanelSensorService::ujiKoneksi().
     * @return string
     */
    private function pesanUjiKoneksi(array $hasil): string
    {
        $sumber = $hasil['sumber'] ?? null;
        $status = $hasil['status'];
        $pembacaan = $hasil['pembacaan'];

        if ($status === null) {
            $pesan = (string) ($hasil['pesan'] ?? 'Perangkat tidak bisa dihubungi.');

            return ($hasil['ping']['status'] ?? '') === 'online'
                ? $pesan . ' Perangkat menjawab ping, jadi kemungkinan layanan yang dipilih tidak aktif di perangkat (cek protokol ruangan ini).'
                : $pesan . ' Cek kabel LAN, sumber daya, dan alamat IP perangkat.';
        }

        // Perangkat tanpa buffer (sketch dashboard) tidak melaporkan modul
        // W5500, firmware, maupun statistik pengiriman, jadi pesannya berbeda.
        if ($sumber !== 'json-api') {
            $nilai = $pembacaan === null
                ? 'belum ada'
                : (($pembacaan['suhu'] ?? '-') . ' C / ' . ($pembacaan['kelembapan'] ?? '-') . ' %');

            if (!($status['sensor_membaca'] ?? false)) {
                return 'Perangkat terjangkau lewat ' . $sumber . ', tetapi sensor belum mengirim pembacaan (nilai 0). Cek kabel DATA sensor dan pasang resistor pull-up 10k antara VCC dan DATA bila sensornya tipe polos 4 kaki.';
            }

            return 'Perangkat sehat lewat ' . $sumber . '. Pembacaan terakhir di perangkat: ' . $nilai . '.';
        }

        if (!($status['w5500_terdeteksi'] ?? false)) {
            return 'Perangkat terjangkau, tetapi modul W5500 tidak terdeteksi. Cek wiring SPI: SCK=18, MISO=19, MOSI=23, CS=5, VCC=3V3.';
        }

        if ($pembacaan === null) {
            return 'Perangkat terjangkau, tetapi belum ada pembacaan sensor yang valid. Cek wiring sensor DHT22 pada GPIO27; salah tipe sensor membuat library menolak datanya.';
        }

        return sprintf(
            'Perangkat sehat. Pembacaan terakhir di perangkat: %s C / %s %%.',
            $pembacaan['suhu'] ?? '-',
            $pembacaan['kelembapan'] ?? '-'
        );
    }
}
