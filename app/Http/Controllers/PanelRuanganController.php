<?php

namespace App\Http\Controllers;

use App\Models\PanelRuangan;
use App\Services\PanelSensorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Halaman Monitoring Ruang Panel.
 *
 * Menampilkan suhu dan kelembapan tiap ruangan panel secara berkala serta
 * menyediakan pendataan ruangan (tambah, ubah, hapus) dan tombol uji koneksi
 * ke perangkat sensor.
 *
 * Data diambil dengan cara server menarik sendiri (pull) dari perangkat
 * ESP32, sehingga tidak memerlukan aturan port masuk di firewall.
 */
class PanelRuanganController extends Controller
{
    public function __construct(
        protected PanelSensorService $sensor
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

        $daftarRuangan = PanelRuangan::query()->orderBy('nama_ruangan')->get();

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
     * Simpan ruangan panel baru.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasiRuangan($request);
        $data['aktif'] = $request->boolean('aktif');

        PanelRuangan::query()->create($data);

        return redirect()
            ->route('panel.index')
            ->with('success', 'Ruangan panel "' . $data['nama_ruangan'] . '" berhasil ditambahkan. Data akan mulai ditarik pada siklus berikutnya.');
    }

    /**
     * Perbarui data satu ruangan panel.
     *
     * @param  Request  $request
     * @param  PanelRuangan  $panelRuangan
     * @return RedirectResponse
     */
    public function update(Request $request, PanelRuangan $panelRuangan): RedirectResponse
    {
        $data = $this->validasiRuangan($request, $panelRuangan);
        $data['aktif'] = $request->boolean('aktif');

        $panelRuangan->update($data);

        return redirect()
            ->route('panel.index')
            ->with('success', 'Data ruangan panel "' . $data['nama_ruangan'] . '" berhasil diperbarui.');
    }

    /**
     * Hapus ruangan panel beserta seluruh riwayat pembacaannya.
     *
     * @param  PanelRuangan  $panelRuangan
     * @return RedirectResponse
     */
    public function destroy(PanelRuangan $panelRuangan): RedirectResponse
    {
        $nama = $panelRuangan->nama_ruangan;

        $panelRuangan->delete();

        return redirect()
            ->route('panel.index')
            ->with('success', 'Ruangan panel "' . $nama . '" beserta riwayat pembacaannya berhasil dihapus.');
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
     * Validasi input form ruangan panel.
     *
     * @param  Request  $request
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
            'mac_address'       => ['nullable', 'string', 'max:17'],
            'ambang_suhu_atas'  => ['required', 'numeric', 'between:-50,150'],
            'ambang_suhu_bawah' => ['required', 'numeric', 'between:-50,150'],
        ];

        $namaAtribut = [
            'nama_ruangan'      => 'nama ruangan',
            'pt_location'       => 'PT location',
            'plant'             => 'plant',
            'keterangan'        => 'keterangan',
            'ip_address'        => 'alamat IP perangkat',
            'port'              => 'port',
            'mac_address'       => 'alamat MAC',
            'ambang_suhu_atas'  => 'ambang suhu atas',
            'ambang_suhu_bawah' => 'ambang suhu bawah',
        ];

        return $request->validate($aturan, [], $namaAtribut);
    }

    /**
     * Susun pesan hasil uji koneksi agar mudah ditindaklanjuti.
     *
     * @param  array<string, mixed>  $hasil  Hasil PanelSensorService::ujiKoneksi().
     * @return string
     */
    private function pesanUjiKoneksi(array $hasil): string
    {
        if ($hasil['status'] === null) {
            return ($hasil['ping']['status'] ?? '') === 'online'
                ? 'Perangkat menjawab ping, tetapi server HTTP di perangkat tidak merespons. Cek firmware dan wiring modul W5500.'
                : 'Perangkat tidak bisa dihubungi. Cek kabel LAN, sumber daya POE, dan alamat IP perangkat.';
        }

        if (!($hasil['status']['w5500_terdeteksi'] ?? false)) {
            return 'Perangkat terjangkau, tetapi modul W5500 tidak terdeteksi. Cek wiring SPI: SCK=18, MISO=19, MOSI=23, CS=5, VCC=3V3.';
        }

        if ($hasil['pembacaan'] === null) {
            return 'Perangkat terjangkau, tetapi belum ada pembacaan sensor yang valid. Cek wiring sensor DHT11 pada GPIO4.';
        }

        return sprintf(
            'Perangkat sehat. Pembacaan terakhir di perangkat: %s C / %s %%.',
            $hasil['pembacaan']['suhu'] ?? '-',
            $hasil['pembacaan']['kelembapan'] ?? '-'
        );
    }
}
