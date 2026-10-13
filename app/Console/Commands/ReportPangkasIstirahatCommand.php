<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\Shift\JamKerjaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Samakan durasi man hour laporan lama dengan aturan istirahat.
 *
 * Latar belakang: durasi laporan yang dihitung dari jam mulai/selesai
 * (Report::durasiDariJam()) dulu TIDAK memotong istirahat, sehingga laporan
 * seperti 10:00-16:00 tersimpan 360 menit padahal pekerja non-shift tidak
 * bekerja pada 12:00-13:00 (300 menit). Sejak aturan istirahat dipasang di
 * JamKerjaService::durasiKerja(), laporan BARU sudah benar; perintah ini
 * menyamakan laporan LAMA yang jamnya sudah terisi.
 *
 * Aturan yang dipakai sama dengan form web dan wizard Telegram:
 *   - hanya pekerja non-shift (reguler & reguler staff) yang dipotong,
 *   - jendela istirahat dari config('cm.man_hour.jam_kerja') (Senin-Sabtu
 *     12:00-13:00, Jumat 11:00-13:00),
 *   - pekerja shift (grub A-D) dan hari libur tidak dipotong,
 *   - laporan tanpa jam mulai/selesai (durasi diketik manual) TIDAK disentuh.
 *
 * Kolom yang diubah HANYA work_duration_minutes (durasi NORMAL). LEMBUR tidak
 * disimpan di kolom mana pun: dihitung ulang dari jam laporan (lihat
 * JamKerjaService::ringkasJamKerja()) sehingga selalu ikut kalau jam dikoreksi,
 * dan dilaporkan pada kolom `lembur` berkas CSV. Sejak 13/10/2026 jam ISTIRAHAT
 * tidak dihitung lembur (menitnya hanya terpotong dari durasi), jadi angka
 * lembur pada berkas CSV lebih kecil daripada sebelum perubahan itu.
 * Tanpa --execute perintah ini hanya menampilkan simulasi.
 *
 * Contoh:
 *   php artisan reports:pangkas-istirahat
 *   php artisan reports:pangkas-istirahat --laporan="C:\laragon\backup-oleochemical\pangkas-istirahat.csv"
 *   php artisan reports:pangkas-istirahat --execute
 */
class ReportPangkasIstirahatCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'reports:pangkas-istirahat
                            {--execute : Simpan perubahan (tanpa flag ini hanya simulasi)}
                            {--laporan= : Tulis daftar seluruh baris yang diperiksa ke berkas CSV}
                            {--limit=0 : Batasi jumlah laporan yang diperiksa (0 = semua)}';

    /**
     * @var string
     */
    protected $description = 'Hitung ulang durasi man hour NORMAL laporan lama (jam kerja dikurangi istirahat) dan laporkan lembur (jam di luar jam kerja/hari OFF; jam istirahat bukan lembur)';

    /**
     * Jalankan perintah: periksa laporan yang punya jam mulai & selesai,
     * hitung durasi bersihnya, lalu simpan bila --execute diberikan.
     *
     * @param  JamKerjaService  $jamKerja  Sumber aturan jam kerja & istirahat
     * @return int  Kode keluar perintah
     */
    public function handle(JamKerjaService $jamKerja): int
    {
        $jalankan = (bool) $this->option('execute');
        $batas    = (int) $this->option('limit');
        $berkas   = (string) ($this->option('laporan') ?? '');

        $this->info($jalankan
            ? 'MODE SIMPAN: durasi laporan lama akan ditulis ke database.'
            : 'MODE SIMULASI: tidak ada perubahan ditulis (tambahkan --execute untuk menyimpan).');
        $this->newLine();

        $ringkas = [
            'diperiksa'     => 0,
            'berubah'       => 0,
            'tidak_berubah' => 0,
            'tanpa_aturan'  => 0,
            'gagal'         => 0,
            'menit_lama'    => 0,
            'menit_baru'    => 0,
            'lembur_total'  => 0,
        ];

        $contoh  = [];
        $tangani = null;

        if ($berkas !== '') {
            $tangani = fopen($berkas, 'w');

            if ($tangani === false) {
                $this->error('Tidak bisa menulis berkas laporan: ' . $berkas);

                return self::FAILURE;
            }

            fputcsv($tangani, [
                'id', 'kode_laporan', 'tanggal', 'teknisi', 'group', 'jam_mulai', 'jam_selesai',
                'durasi_lama', 'durasi_baru', 'potongan_istirahat', 'lembur', 'status',
            ]);
        }

        $query = Report::query()
            ->with('technician')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->orderBy('id');

        if ($batas > 0) {
            $query->limit($batas);
        }

        // Rentang tanggal laporan berjam: jadwal (shift) tiap teknisi dibangun
        // SEKALI untuk rentang ini, bukan per laporan, supaya tetap cepat pada
        // database PROD yang memuat ribuan laporan.
        $rentang = Report::query()
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->selectRaw('MIN(report_date) AS awal, MAX(report_date) AS akhir')
            ->first();

        $awalSemua        = $rentang?->awal ?? now()->toDateString();
        $akhirSemua       = $rentang?->akhir ?? now()->toDateString();
        $jadwalPerTeknisi = [];

        $query->chunk(200, function ($daftarLaporan) use ($jamKerja, $jalankan, $tangani, $awalSemua, $akhirSemua, &$jadwalPerTeknisi, &$ringkas, &$contoh) {
            foreach ($daftarLaporan as $laporan) {
                $ringkas['diperiksa']++;

                $lama = (int) ($laporan->work_duration_minutes ?? 0);

                if ($laporan->technician !== null) {
                    $jadwalPerTeknisi[$laporan->technician->id] ??= $jamKerja->jadwalRentang(
                        $laporan->technician,
                        Carbon::parse((string) $awalSemua),
                        Carbon::parse((string) $akhirSemua)
                    );
                }

                // Durasi NORMAL (jam kerja) dan LEMBUR dihitung satu sumber:
                // JamKerjaService::ringkasJamKerja().
                $ringkasLaporan = $laporan->report_date === null
                    ? null
                    : $jamKerja->ringkasJamKerja(
                        $jadwalPerTeknisi[$laporan->technician?->id][$laporan->report_date->toDateString()] ?? null,
                        $laporan->technician?->group,
                        $laporan->report_date,
                        $laporan->start_time,
                        $laporan->end_time
                    );

                $baru        = $ringkasLaporan['normal'] ?? null;
                $lemburBaris = $ringkasLaporan['lembur'] ?? 0;

                $ringkas['lembur_total'] += $lemburBaris;

                $status = 'tidak_berubah';

                if ($baru === null) {
                    $status = 'tanpa_aturan';
                    $ringkas['tanpa_aturan']++;
                    $ringkas['menit_lama'] += $lama;
                    $ringkas['menit_baru'] += $lama;
                } elseif ($baru === $lama) {
                    $ringkas['tidak_berubah']++;
                    $ringkas['menit_lama'] += $lama;
                    $ringkas['menit_baru'] += $baru;
                } else {
                    $status = 'berubah';

                    try {
                        if ($jalankan) {
                            // Mass update: hanya satu kolom, tanpa menyentuh
                            // updated_at supaya laporan tidak tampak "diubah
                            // manual" hanya karena penyamaan angka.
                            Report::whereKey($laporan->id)->update(['work_duration_minutes' => $baru]);
                        }

                        $ringkas['berubah']++;
                        $ringkas['menit_lama'] += $lama;
                        $ringkas['menit_baru'] += $baru;

                        if (count($contoh) < 10) {
                            $contoh[] = sprintf(
                                '%s (%s %s) %s-%s: %d -> %d menit',
                                $laporan->report_code,
                                $laporan->report_date?->toDateString() ?? '-',
                                $laporan->technician?->group ?? '-',
                                $laporan->start_time,
                                $laporan->end_time,
                                $lama,
                                $baru
                            );
                        }
                    } catch (\Throwable $e) {
                        $status = 'gagal';
                        $ringkas['berubah']--;
                        $ringkas['gagal']++;
                        $ringkas['menit_lama'] += $lama;
                        $ringkas['menit_baru'] += $lama;

                        Log::error('reports:pangkas-istirahat gagal menyimpan', [
                            'report_id' => $laporan->id,
                            'pesan'     => $e->getMessage(),
                        ]);
                    }
                }

                if ($tangani !== null) {
                    fputcsv($tangani, [
                        $laporan->id,
                        $laporan->report_code,
                        $laporan->report_date?->toDateString() ?? '',
                        $laporan->technician?->name ?? '',
                        $laporan->technician?->group ?? '',
                        $laporan->start_time,
                        $laporan->end_time,
                        $lama,
                        $baru ?? $lama,
                        max(0, $lama - ($baru ?? $lama)),
                        $lemburBaris,
                        $status,
                    ]);
                }
            }
        });

        if ($tangani !== null) {
            fclose($tangani);
        }

        if ($contoh !== []) {
            $this->line('Contoh perubahan:');

            foreach ($contoh as $baris) {
                $this->line('   ' . $baris);
            }

            $this->newLine();
        }

        $this->table(
            ['Kategori', 'Jumlah'],
            [
                ['Laporan berjam diperiksa', $ringkas['diperiksa']],
                ['Durasi berubah', $ringkas['berubah']],
                ['Durasi sudah benar', $ringkas['tidak_berubah']],
                ['Rentang tidak sah / tanpa tanggal', $ringkas['tanpa_aturan']],
                ['Gagal', $ringkas['gagal']],
                ['Total menit sebelum', $ringkas['menit_lama']],
                ['Total menit sesudah', $ringkas['menit_baru']],
                ['Total menit lembur', $ringkas['lembur_total']],
            ]
        );

        // Penjaga keseimbangan (AGENTS.md poin 9): setiap baris yang diperiksa
        // WAJIB berakhir di salah satu kategori.
        $tercatat = $ringkas['berubah'] + $ringkas['tidak_berubah'] + $ringkas['tanpa_aturan'] + $ringkas['gagal'];

        if ($tercatat !== $ringkas['diperiksa']) {
            $this->warn(sprintf(
                'PERINGATAN: jumlah tidak balance (diperiksa %d, tercatat %d).',
                $ringkas['diperiksa'],
                $tercatat
            ));
            Log::warning('reports:pangkas-istirahat tidak balance', $ringkas);
        }

        if ($berkas !== '') {
            $this->line('Daftar lengkap ditulis ke: ' . $berkas);
        }

        if ($ringkas['berubah'] === 0) {
            $this->info('Tidak ada durasi laporan yang perlu disamakan.');

            return self::SUCCESS;
        }

        if (! $jalankan) {
            $this->warn('Simulasi selesai. Jalankan ulang dengan --execute untuk menyimpan ' . $ringkas['berubah'] . ' baris.');

            return self::SUCCESS;
        }

        Log::info('reports:pangkas-istirahat selesai', $ringkas);
        $this->info('Selesai: ' . $ringkas['berubah'] . ' durasi laporan disamakan.');

        return self::SUCCESS;
    }
}
