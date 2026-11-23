<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Support\PencarianKataKunci;
use Illuminate\Database\Eloquent\Builder;

/**
 * PencarianAsetService
 *
 * SATU sumber aturan pencarian halaman Asset Management dan dropdown
 * sarannya. Kata kunci dipecah menjadi beberapa kata; SEMUA kata harus cocok
 * (AND), satu kata boleh cocok di kolom/relasi mana pun (OR).
 *
 * Kolom langsung: kode alat, equipment no, deskripsi, kode lokasi, tipe,
 * object type, pabrikan, dan model. Relasi: PT, departemen, area, sub area,
 * Functional Location, serta perangkat IT cermin (tag/nama/IP).
 */
class PencarianAsetService
{
    /**
     * Panjang kata kunci minimum yang boleh diproses.
     */
    public function minHuruf(): int
    {
        return (int) config('pencarian_entitas.min_huruf', 2);
    }

    /**
     * Terapkan filter kata kunci ke query asset.
     *
     * @param  Builder<Asset>  $query  Query asset
     * @param  string  $kataKunci  Kata kunci dari pengguna
     * @return Builder<Asset>
     */
    public function terapkan(Builder $query, string $kataKunci): Builder
    {
        $token = $this->token($kataKunci);

        if ($token === []) {
            return $query;
        }

        $sinonim = (array) config('pencarian_entitas.sinonim_aset', []);

        foreach ($token as $kata) {
            $varian = PencarianKataKunci::variasi($kata, $sinonim);

            $query->where(function (Builder $sub) use ($varian) {
                foreach ($varian as $teks) {
                    $pola = '%'.$teks.'%';

                    $sub->orWhere('assets.equipment_no', 'like', $pola)
                        ->orWhere('assets.tech_ident_no', 'like', $pola)
                        ->orWhere('assets.description', 'like', $pola)
                        ->orWhere('assets.functional_loc', 'like', $pola)
                        ->orWhere('assets.equipment_type', 'like', $pola)
                        ->orWhere('assets.object_type', 'like', $pola)
                        ->orWhere('assets.manufacturer', 'like', $pola)
                        ->orWhere('assets.model_number', 'like', $pola)
                        ->orWhereHas('company', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('code', 'like', $pola)
                                    ->orWhere('name', 'like', $pola);
                            });
                        })
                        ->orWhereHas('department', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('code', 'like', $pola)
                                    ->orWhere('name', 'like', $pola);
                            });
                        })
                        ->orWhereHas('area', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('code', 'like', $pola)
                                    ->orWhere('name', 'like', $pola);
                            });
                        })
                        ->orWhereHas('subArea', function (Builder $relasi) use ($pola) {
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
                        ->orWhereHas('itAsset', function (Builder $relasi) use ($pola) {
                            $relasi->where(function (Builder $kolom) use ($pola) {
                                $kolom->where('tag_name', 'like', $pola)
                                    ->orWhere('name', 'like', $pola)
                                    ->orWhere('ip_address', 'like', $pola);
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
     * Kode alat persis/awalan didahulukan, lalu equipment no, deskripsi,
     * tipe/lokasi. Skor dihitung sebagai kolom `relevansi` (bukan parameter di
     * ORDER BY) karena SQLite tidak mengikat parameter ORDER BY dengan benar.
     *
     * @param  Builder<Asset>  $query  Query asset
     * @param  string  $kataKunci  Kata kunci dari pengguna
     */
    public function terapkanUrutan(Builder $query, string $kataKunci): void
    {
        $kunci = PencarianKataKunci::bersihkan($kataKunci);

        if ($kunci === '') {
            return;
        }

        $token = $this->token($kataKunci);
        $utama = $token[0] ?? $kunci;
        $awalan = $kunci.'%';
        $pola = '%'.$kunci.'%';
        $polaUtama = '%'.$utama.'%';

        $ekspresi = <<<'SQL'
CASE
    WHEN LOWER(assets.tech_ident_no) = LOWER(?) THEN 1000
    WHEN assets.tech_ident_no LIKE ? THEN 950
    WHEN LOWER(assets.equipment_no) = LOWER(?) THEN 900
    WHEN assets.equipment_no LIKE ? THEN 850
    WHEN assets.description LIKE ? THEN 750
    WHEN assets.description LIKE ? THEN 650
    WHEN assets.tech_ident_no LIKE ? THEN 600
    WHEN assets.equipment_no LIKE ? THEN 580
    WHEN assets.description LIKE ? THEN 560
    WHEN assets.equipment_type LIKE ? THEN 500
    WHEN assets.functional_loc LIKE ? THEN 450
    ELSE 100
END
SQL;

        $query->select('assets.*')
            ->selectRaw($ekspresi.' as relevansi', [
                $kunci,
                $awalan,
                $kunci,
                $awalan,
                $awalan,
                $pola,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
            ])
            ->orderByDesc('relevansi');
    }

    /**
     * Susun rekomendasi pencarian untuk dropdown halaman Asset Management.
     *
     * Kelompok: aset (potongan hasil teratas), tipe equipment, dan lokasi
     * Functional Location. Setiap item sudah membawa URL tujuan.
     *
     * @param  string  $kataKunci  Kata kunci dari pengguna
     * @return array{kata_kunci: string, total: int, kelompok: array<int, array<string, mixed>>}
     */
    public function saran(string $kataKunci): array
    {
        $kunci = PencarianKataKunci::bersihkan($kataKunci);
        $token = $this->token($kataKunci);

        if ($kunci === '' || $token === []) {
            return ['kata_kunci' => $kunci, 'total' => 0, 'kelompok' => []];
        }

        $kelompok = [];

        $aset = $this->saranAset($kunci);

        if ($aset['items'] !== []) {
            $kelompok[] = $aset;
        }

        foreach ([$this->saranTipe($token), $this->saranLokasi($token)] as $grup) {
            if ($grup['items'] !== []) {
                $kelompok[] = $grup;
            }
        }

        return [
            'kata_kunci' => $kunci,
            'total' => $aset['jumlah'],
            'kelompok' => $kelompok,
        ];
    }

    /**
     * Saran potongan asset yang cocok, maksimal batas_saran_aset.
     *
     * @param  string  $kunci  Kata kunci yang sudah dibersihkan
     * @return array<string, mixed>
     */
    private function saranAset(string $kunci): array
    {
        $dasar = Asset::query();

        $this->terapkan($dasar, $kunci);

        $jumlah = (clone $dasar)->count();

        $query = (clone $dasar)->with([
            'company:id,code,name',
            'department:id,code,name',
            'area:id,code,name',
            'subArea:id,code,name',
        ]);

        $this->terapkanUrutan($query, $kunci);

        $assets = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) config('pencarian_entitas.batas_saran_aset', 5))
            ->get();

        $items = $assets->map(fn (Asset $aset) => [
            'kunci' => 'aset-'.$aset->id,
            'judul' => $this->kodeAset($aset),
            'subjudul' => trim((string) ($aset->description ?? '')) ?: '(tanpa deskripsi)',
            'meta' => $this->metaAset($aset),
            'url' => route('assets.show', $aset->id),
        ])->values()->all();

        return [
            'tipe' => 'aset',
            'label' => 'Aset',
            'jumlah' => $jumlah,
            'items' => $items,
        ];
    }

    /**
     * Saran tipe equipment yang cocok, lengkap dengan jumlah asetnya.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranTipe(array $token): array
    {
        $query = Asset::query()
            ->whereNotNull('equipment_type')
            ->where('equipment_type', '!=', '');

        $this->terapkanTokenEntitas($query, ['equipment_type'], $token);

        $jumlah = (clone $query)->distinct()->count('equipment_type');

        $items = $query
            ->distinct()
            ->orderBy('equipment_type')
            ->limit((int) config('pencarian_entitas.batas_saran_entitas', 3))
            ->pluck('equipment_type')
            ->map(function (string $tipe) {
                return [
                    'kunci' => 'tipe-'.$tipe,
                    'judul' => $tipe,
                    'subjudul' => 'Tipe equipment',
                    'meta' => number_format(Asset::where('equipment_type', $tipe)->count()).' aset',
                    'url' => route('assets.index', ['equipment_type' => $tipe]),
                ];
            })
            ->values()
            ->all();

        return [
            'tipe' => 'tipe',
            'label' => 'Tipe',
            'jumlah' => $jumlah,
            'items' => $items,
        ];
    }

    /**
     * Saran lokasi Functional Location yang cocok. Klik item membuka halaman
     * asset dengan kata kunci kode lokasi (termasuk turunan kodenya).
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
            ->orderByRaw('CASE WHEN LOWER(code) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($token[0]).'%'])
            ->orderBy('code')
            ->limit((int) config('pencarian_entitas.batas_saran_entitas', 3))
            ->get(['id', 'code', 'name', 'level'])
            ->map(function (FunctionalLocation $lokasi) {
                $labelLevel = [0 => 'PT', 1 => 'Departemen', 2 => 'Area', 3 => 'Section'][$lokasi->level] ?? 'Lokasi';
                $jumlahAset = Asset::where('funcloc_id', $lokasi->id)->count();

                return [
                    'kunci' => 'lokasi-'.$lokasi->id,
                    'judul' => (string) $lokasi->code,
                    'subjudul' => (string) $lokasi->name,
                    'meta' => $labelLevel.' · '.number_format($jumlahAset).' aset langsung',
                    'url' => route('assets.index', ['search' => $lokasi->code]),
                ];
            })
            ->values()
            ->all();

        return [
            'tipe' => 'lokasi',
            'label' => 'Lokasi',
            'jumlah' => $jumlah,
            'items' => $items,
        ];
    }

    /**
     * Terapkan semua token (AND) ke satu atau beberapa kolom entitas.
     *
     * @param  Builder  $query  Query entitas
     * @param  array<int, string>  $kolom  Nama kolom yang dicari
     * @param  array<int, string>  $token  Token pencarian
     */
    private function terapkanTokenEntitas(Builder $query, array $kolom, array $token): void
    {
        foreach ($token as $kata) {
            $pola = '%'.$kata.'%';

            $query->where(function (Builder $sub) use ($kolom, $pola) {
                $sub->where($kolom[0], 'like', $pola);

                foreach (array_slice($kolom, 1) as $namaKolom) {
                    $sub->orWhere($namaKolom, 'like', $pola);
                }
            });
        }
    }

    /**
     * Token pencarian dari kata kunci mentah.
     *
     * @param  string  $kataKunci  Kata kunci mentah
     * @return array<int, string>
     */
    private function token(string $kataKunci): array
    {
        return PencarianKataKunci::token(
            $kataKunci,
            $this->minHuruf(),
            (int) config('pencarian_entitas.maks_token', 8)
        );
    }

    /**
     * Kode tampil utama satu asset.
     *
     * @param  Asset  $aset  Asset
     */
    private function kodeAset(Asset $aset): string
    {
        return (string) ($aset->tech_ident_no ?: $aset->equipment_no ?: ('#'.$aset->id));
    }

    /**
     * Meta ringkas satu asset untuk dropdown saran.
     *
     * @param  Asset  $aset  Asset
     */
    private function metaAset(Asset $aset): string
    {
        $bagian = array_filter([
            $aset->equipment_type,
            $aset->area?->code,
            $aset->functional_loc,
        ], fn ($nilai) => $nilai !== null && $nilai !== '');

        return implode(' · ', array_map('strval', $bagian));
    }
}
