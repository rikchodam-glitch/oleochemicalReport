<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * PencarianLaporanService
 *
 * SATU sumber aturan pencarian laporan untuk halaman Laporan dan endpoint
 * saran (dropdown). Dipakai supaya daftar hasil dan rekomendasi selalu
 * memakai kata kunci, sinonim, dan urutan yang sama.
 *
 * Aturan pokok:
 *   - Kata kunci dipecah menjadi beberapa kata; SEMUA kata harus cocok
 *     (AND), sedangkan satu kata boleh cocok di kolom mana pun (OR).
 *   - Kolom langsung: deskripsi pekerjaan, kode laporan, penyebab, temuan,
 *     dan equipment tag.
 *   - Relasi: teknisi (nama/NIK), area (kode/nama), Functional Location
 *     (kode/nama), alat (tech ident/equipment no/deskripsi/kode lokasi),
 *     teknisi kolaborator, serta alat/lokasi tambahan pekerjaan multi item.
 *   - Sinonim ringan dibaca dari config('pencarian.sinonim').
 */
class PencarianLaporanService
{
    /**
     * Panjang kata kunci minimum yang boleh diproses.
     *
     * @return int
     */
    public function minHuruf(): int
    {
        return (int) config('pencarian.min_huruf', 2);
    }

    /**
     * Pecah kata kunci menjadi token pencarian.
     *
     * Karakter wildcard LIKE (%) dan (_) dibuang supaya kata kunci pengguna
     * tidak bisa mengubah pola query. Token yang terlalu pendek diabaikan;
     * bila semua token pendek, kata kunci utuh tetap dipakai selama memenuhi
     * panjang minimum.
     *
     * @param  string  $kataKunci  Kata kunci mentah dari pengguna
     * @return array<int, string>  Token yang sudah bersih dan unik
     */
    public function token(string $kataKunci): array
    {
        $bersih = $this->bersihkanKunci($kataKunci);

        if ($bersih === '') {
            return [];
        }

        $mentah = preg_split('/\s+/u', $bersih, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $token  = [];

        foreach ($mentah as $kata) {
            if (mb_strlen($kata) >= $this->minHuruf()) {
                $token[mb_strtolower($kata)] = $kata;
            }
        }

        if ($token === [] && mb_strlen($bersih) >= $this->minHuruf()) {
            return [$bersih];
        }

        return array_slice(array_values($token), 0, (int) config('pencarian.maks_token', 8));
    }

    /**
     * Terapkan filter kata kunci ke query laporan.
     *
     * Setiap token dibungkus grup sendiri sehingga antar token berlaku AND.
     * Di dalam satu token, seluruh kolom dan sinonimnya berlaku OR.
     *
     * @param  Builder<Report>  $query      Query laporan yang sedang disusun
     * @param  string           $kataKunci  Kata kunci dari pengguna
     * @return Builder<Report>              Query dengan filter pencarian
     */
    public function terapkan(Builder $query, string $kataKunci): Builder
    {
        $token = $this->token($kataKunci);

        if ($token === []) {
            return $query;
        }

        foreach ($token as $kata) {
            $varian = $this->variasi($kata);

            $query->where(function (Builder $sub) use ($varian) {
                foreach ($varian as $teks) {
                    $pola = '%' . $teks . '%';

                    $sub->orWhere('reports.work_description', 'like', $pola)
                        ->orWhere('reports.report_code', 'like', $pola)
                        ->orWhere('reports.root_cause', 'like', $pola)
                        ->orWhere('reports.finding', 'like', $pola)
                        ->orWhere('reports.equipment_tag', 'like', $pola)
                        ->orWhereHas('technician', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('name', 'like', $pola)
                                    ->orWhere('nik', 'like', $pola);
                            });
                        })
                        ->orWhereHas('area', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('code', 'like', $pola)
                                    ->orWhere('name', 'like', $pola);
                            });
                        })
                        ->orWhereHas('functionalLocation', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('code', 'like', $pola)
                                    ->orWhere('name', 'like', $pola);
                            });
                        })
                        ->orWhereHas('asset', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('tech_ident_no', 'like', $pola)
                                    ->orWhere('equipment_no', 'like', $pola)
                                    ->orWhere('description', 'like', $pola)
                                    ->orWhere('functional_loc', 'like', $pola);
                            });
                        })
                        ->orWhereHas('collaboratorReports.technician', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('name', 'like', $pola)
                                    ->orWhere('nik', 'like', $pola);
                            });
                        })
                        ->orWhereHas('multiItems.asset', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('tech_ident_no', 'like', $pola)
                                    ->orWhere('equipment_no', 'like', $pola)
                                    ->orWhere('description', 'like', $pola);
                            });
                        })
                        ->orWhereHas('multiItems.functionalLocation', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('code', 'like', $pola)
                                    ->orWhere('name', 'like', $pola);
                            });
                        });
                }
            });
        }

        return $query;
    }

    /**
     * Urutkan hasil pencarian dari yang paling relevan.
     *
     * Urutan yang dipakai: kode laporan persis, awalan kode laporan,
     * equipment tag, awalan deskripsi, deskripsi, lalu kecocokan kata pertama
     * pada kode/deskripsi/alat/lokasi/teknisi/area. Pemanggil tetap menambah
     * urutan tanggal setelah method ini.
     *
     * @param  Builder<Report>  $query      Query laporan
     * @param  string           $kataKunci  Kata kunci dari pengguna
     * @return void
     */
    public function terapkanUrutan(Builder $query, string $kataKunci): void
    {
        $kunci = $this->bersihkanKunci($kataKunci);

        if ($kunci === '') {
            return;
        }

        $token      = $this->token($kataKunci);
        $utama      = $token[0] ?? $kunci;
        $awalan     = $kunci . '%';
        $pola       = '%' . $kunci . '%';
        $awalanUtama = $utama . '%';
        $polaUtama   = '%' . $utama . '%';

        $ekspresi = <<<'SQL'
CASE
    WHEN LOWER(reports.report_code) = LOWER(?) THEN 1000
    WHEN reports.report_code LIKE ? THEN 900
    WHEN reports.equipment_tag LIKE ? THEN 850
    WHEN reports.work_description LIKE ? THEN 700
    WHEN reports.work_description LIKE ? THEN 600
    WHEN reports.report_code LIKE ? THEN 580
    WHEN reports.work_description LIKE ? THEN 560
    WHEN reports.equipment_tag LIKE ? THEN 540
    WHEN EXISTS (SELECT 1 FROM assets WHERE assets.id = reports.asset_id AND (assets.tech_ident_no LIKE ? OR assets.equipment_no LIKE ? OR assets.description LIKE ?)) THEN 500
    WHEN EXISTS (SELECT 1 FROM functional_locations WHERE functional_locations.id = reports.funcloc_id AND (functional_locations.code LIKE ? OR functional_locations.name LIKE ?)) THEN 450
    WHEN EXISTS (SELECT 1 FROM technicians WHERE technicians.id = reports.technician_id AND (technicians.name LIKE ? OR technicians.nik LIKE ?)) THEN 400
    WHEN EXISTS (SELECT 1 FROM areas WHERE areas.id = reports.area_id AND (areas.code LIKE ? OR areas.name LIKE ?)) THEN 350
    ELSE 100
END
SQL;

        // Relevansi dihitung sebagai kolom terpilih lalu diurutkan lewat
        // alias. Parameter (?) di dalam ekspresi ORDER BY tidak dapat diikat
        // dengan benar oleh SQLite, sedangkan di daftar SELECT hasilnya benar
        // pada SQLite maupun MySQL.
        $query->select('reports.*')
            ->selectRaw($ekspresi . ' as relevansi', [
                $kunci,
                $awalan,
                $awalan,
                $awalan,
                $pola,
                $polaUtama,
                $awalanUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
            ])
            ->orderByDesc('relevansi');
    }

    /**
     * Susun rekomendasi pencarian untuk dropdown halaman Laporan.
     *
     * Kelompok yang dikembalikan: laporan (potongan hasil teratas), teknisi,
     * area, alat, dan lokasi. Setiap item sudah membawa URL tujuan supaya
     * halaman cukup menampilkan hasil.
     *
     * @param  string  $kataKunci  Kata kunci dari pengguna
     * @return array{kata_kunci: string, total: int, kelompok: array<int, array<string, mixed>>}
     */
    public function saran(string $kataKunci): array
    {
        $kunci = $this->bersihkanKunci($kataKunci);
        $token = $this->token($kataKunci);

        if ($kunci === '' || $token === []) {
            return ['kata_kunci' => $kunci, 'total' => 0, 'kelompok' => []];
        }

        $kelompok = [];

        $laporan = $this->saranLaporan($kunci);

        if ($laporan['items'] !== []) {
            $kelompok[] = $laporan;
        }

        foreach ([
            $this->saranTeknisi($token),
            $this->saranArea($token),
            $this->saranAlat($token),
            $this->saranLokasi($token),
        ] as $grup) {
            if ($grup['items'] !== []) {
                $kelompok[] = $grup;
            }
        }

        return [
            'kata_kunci' => $kunci,
            'total'      => $laporan['jumlah'],
            'kelompok'   => $kelompok,
        ];
    }

    /**
     * Saran potongan laporan yang cocok, maksimal batas_saran_laporan.
     *
     * @param  string  $kunci  Kata kunci yang sudah dibersihkan
     * @return array<string, mixed>
     */
    private function saranLaporan(string $kunci): array
    {
        $dasar = Report::query()
            ->whereNull('multi_of')
            ->whereNull('collaborator_of');

        $this->terapkan($dasar, $kunci);

        $jumlah = (clone $dasar)->count();

        $query = (clone $dasar)
            ->with([
                'technician:id,name',
                'area:id,code,name',
                'functionalLocation:id,code,name',
                'asset:id,tech_ident_no,equipment_no,description',
            ]);

        $this->terapkanUrutan($query, $kunci);

        $laporan = $query
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->limit((int) config('pencarian.batas_saran_laporan', 4))
            ->get();

        $items = $laporan->map(function (Report $baris) {
            $kodeAlat = $baris->asset?->tech_ident_no
                ?? $baris->asset?->equipment_no
                ?? $baris->equipment_tag;

            return [
                'kunci'        => 'laporan-' . $baris->id,
                'id'           => (int) $baris->id,
                'kode'         => (string) ($baris->report_code ?? '-'),
                'deskripsi'    => Str::limit(trim((string) $baris->work_description), 150) ?: '(tanpa deskripsi pekerjaan)',
                'tanggal'      => $baris->report_date?->format('d/m/Y') ?? '-',
                'teknisi'      => $baris->technician?->name ?? '-',
                'lokasi'       => $baris->functionalLocation?->code ?? $baris->area?->code ?? '-',
                'alat'         => $kodeAlat !== null && $kodeAlat !== '' ? (string) $kodeAlat : '-',
                'status_label' => $this->labelStatus($baris->status),
                'jenis'        => (string) ($baris->jenis_pekerjaan ?? ''),
                'url'          => route('reports.show', $baris->id),
            ];
        })->values()->all();

        return [
            'tipe'   => 'laporan',
            'label'  => 'Laporan',
            'jumlah' => $jumlah,
            'items'  => $items,
        ];
    }

    /**
     * Saran teknisi berdasarkan nama atau NIK.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranTeknisi(array $token): array
    {
        $query = Technician::query();

        $this->terapkanTokenEntitas($query, ['name', 'nik'], $token);

        $jumlah = (clone $query)->count();

        $items = $query
            ->orderByRaw('CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($token[0]) . '%'])
            ->orderBy('name')
            ->limit((int) config('pencarian.batas_saran_entitas', 3))
            ->get(['id', 'name', 'nik', 'group'])
            ->map(fn (Technician $teknisi) => [
                'kunci' => 'teknisi-' . $teknisi->id,
                'id'    => (int) $teknisi->id,
                'nama'  => (string) $teknisi->name,
                'nik'   => (string) ($teknisi->nik ?? ''),
                'grup'  => Technician::GROUPS[$teknisi->group] ?? (string) ($teknisi->group ?? ''),
                'url'   => route('reports.index', [
                    'technician_id' => $teknisi->id,
                    'cakupan'       => 'semua',
                ]),
            ])
            ->values()
            ->all();

        return [
            'tipe'   => 'teknisi',
            'label'  => 'Teknisi',
            'jumlah' => $jumlah,
            'items'  => $items,
        ];
    }

    /**
     * Saran area berdasarkan kode atau nama.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranArea(array $token): array
    {
        $query = Area::query();

        $this->terapkanTokenEntitas($query, ['code', 'name'], $token);

        $jumlah = (clone $query)->count();

        $items = $query
            ->orderByRaw('CASE WHEN LOWER(code) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($token[0]) . '%'])
            ->orderBy('code')
            ->limit((int) config('pencarian.batas_saran_entitas', 3))
            ->get(['id', 'code', 'name'])
            ->map(fn (Area $area) => [
                'kunci' => 'area-' . $area->id,
                'id'    => (int) $area->id,
                'kode'  => (string) $area->code,
                'nama'  => (string) $area->name,
                'url'   => route('reports.index', [
                    'area_id' => $area->id,
                    'cakupan' => 'semua',
                ]),
            ])
            ->values()
            ->all();

        return [
            'tipe'   => 'area',
            'label'  => 'Area',
            'jumlah' => $jumlah,
            'items'  => $items,
        ];
    }

    /**
     * Saran alat berdasarkan tech ident, equipment no, atau deskripsi.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranAlat(array $token): array
    {
        $query = Asset::query();

        $this->terapkanTokenEntitas(
            $query,
            ['tech_ident_no', 'equipment_no', 'description', 'functional_loc'],
            $token
        );

        $jumlah = (clone $query)->count();

        $items = $query
            ->orderByRaw('CASE WHEN LOWER(tech_ident_no) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($token[0]) . '%'])
            ->orderBy('tech_ident_no')
            ->limit((int) config('pencarian.batas_saran_entitas', 3))
            ->get(['id', 'tech_ident_no', 'equipment_no', 'description', 'functional_loc'])
            ->map(function (Asset $aset) {
                $kode = $aset->tech_ident_no ?: $aset->equipment_no;

                return [
                    'kunci'  => 'alat-' . $aset->id,
                    'id'     => (int) $aset->id,
                    'kode'   => (string) ($kode ?? '-'),
                    'nama'   => (string) ($aset->description ?? ''),
                    'lokasi' => (string) ($aset->functional_loc ?? ''),
                    'url'    => $kode !== null && $kode !== ''
                        ? route('reports.index', ['asset_code' => $kode, 'cakupan' => 'semua'])
                        : null,
                ];
            })
            ->filter(fn (array $item) => $item['url'] !== null)
            ->values()
            ->all();

        return [
            'tipe'   => 'alat',
            'label'  => 'Alat',
            'jumlah' => $jumlah,
            'items'  => $items,
        ];
    }

    /**
     * Saran Functional Location berdasarkan kode atau nama.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranLokasi(array $token): array
    {
        $query = FunctionalLocation::query()->active();

        $this->terapkanTokenEntitas($query, ['code', 'name'], $token);

        $jumlah = (clone $query)->count();

        $items = $query
            ->orderByRaw('CASE WHEN LOWER(code) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($token[0]) . '%'])
            ->orderBy('code')
            ->limit((int) config('pencarian.batas_saran_entitas', 3))
            ->get(['id', 'code', 'name', 'level'])
            ->map(fn (FunctionalLocation $lokasi) => [
                'kunci' => 'lokasi-' . $lokasi->id,
                'id'    => (int) $lokasi->id,
                'kode'  => (string) $lokasi->code,
                'nama'  => (string) $lokasi->name,
                'level' => (int) $lokasi->level,
                'url'   => route('reports.index', [
                    'funcloc_id' => $lokasi->id,
                    'cakupan'    => 'semua',
                ]),
            ])
            ->values()
            ->all();

        return [
            'tipe'   => 'lokasi',
            'label'  => 'Lokasi',
            'jumlah' => $jumlah,
            'items'  => $items,
        ];
    }

    /**
     * Terapkan semua token (AND) ke satu atau beberapa kolom entitas.
     *
     * @param  Builder             $query   Query entitas (teknisi/area/alat/lokasi)
     * @param  array<int, string>  $kolom   Nama kolom yang dicari
     * @param  array<int, string>  $token   Token pencarian
     * @return void
     */
    private function terapkanTokenEntitas(Builder $query, array $kolom, array $token): void
    {
        foreach ($token as $kata) {
            $pola = '%' . $kata . '%';

            $query->where(function (Builder $sub) use ($kolom, $pola) {
                $sub->where($kolom[0], 'like', $pola);

                foreach (array_slice($kolom, 1) as $namaKolom) {
                    $sub->orWhere($namaKolom, 'like', $pola);
                }
            });
        }
    }

    /**
     * Variasi pencarian satu kata: kata asli + sinonim dari config.
     *
     * @param  string  $kata  Satu token pencarian
     * @return array<int, string>
     */
    private function variasi(string $kata): array
    {
        $sinonim = (array) config('pencarian.sinonim', []);
        $kunci   = mb_strtolower($kata);
        $tambahan = $sinonim[$kunci] ?? [];

        return array_values(array_unique(array_merge([$kata], (array) $tambahan)));
    }

    /**
     * Bersihkan kata kunci dari karakter wildcard LIKE dan spasi berlebih.
     *
     * @param  string  $kataKunci  Kata kunci mentah
     * @return string
     */
    private function bersihkanKunci(string $kataKunci): string
    {
        $bersih = str_replace(['%', '_', '\\'], ' ', $kataKunci);
        $bersih = preg_replace('/\s+/u', ' ', $bersih) ?? '';

        return trim($bersih);
    }

    /**
     * Label status untuk potongan laporan di dropdown saran.
     *
     * @param  string|null  $status  Nilai kolom reports.status
     * @return string
     */
    private function labelStatus(?string $status): string
    {
        return match ($status) {
            'completed'    => 'Done',
            'continue'     => 'Continue',
            'needs_review' => 'Perlu Diperiksa',
            default        => (string) ($status ?? '-'),
        };
    }
}
