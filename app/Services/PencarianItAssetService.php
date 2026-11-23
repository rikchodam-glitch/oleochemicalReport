<?php

namespace App\Services;

use App\Models\IpNetwork;
use App\Models\ItAsset;
use App\Support\PencarianKataKunci;
use Illuminate\Database\Eloquent\Builder;

/**
 * PencarianItAssetService
 *
 * SATU sumber aturan pencarian halaman IT Assets dan dropdown sarannya.
 * Kata kunci dipecah menjadi beberapa kata; SEMUA kata harus cocok (AND),
 * satu kata boleh cocok di kolom/relasi mana pun (OR).
 *
 * Kolom langsung: tag, nama, IP, pabrikan, model, serial, plant, departemen,
 * lokasi, aplikasi, dan tipe perangkat (kode maupun label). Relasi:
 * Functional Location.
 */
class PencarianItAssetService
{
    /**
     * Panjang kata kunci minimum yang boleh diproses.
     */
    public function minHuruf(): int
    {
        return (int) config('pencarian_entitas.min_huruf', 2);
    }

    /**
     * Terapkan filter kata kunci ke query perangkat IT.
     *
     * @param  Builder<ItAsset>  $query  Query perangkat
     * @param  string  $kataKunci  Kata kunci dari pengguna
     * @return Builder<ItAsset>
     */
    public function terapkan(Builder $query, string $kataKunci): Builder
    {
        $token = $this->token($kataKunci);

        if ($token === []) {
            return $query;
        }

        $sinonim = (array) config('pencarian_entitas.sinonim_it', []);

        foreach ($token as $kata) {
            $varian = PencarianKataKunci::variasi($kata, $sinonim);

            $query->where(function (Builder $sub) use ($varian) {
                foreach ($varian as $teks) {
                    $pola = '%'.$teks.'%';

                    $sub->orWhere('tag_name', 'like', $pola)
                        ->orWhere('name', 'like', $pola)
                        ->orWhere('ip_address', 'like', $pola)
                        ->orWhere('brand', 'like', $pola)
                        ->orWhere('model', 'like', $pola)
                        ->orWhere('serial_number', 'like', $pola)
                        ->orWhere('plant', 'like', $pola)
                        ->orWhere('department', 'like', $pola)
                        ->orWhere('location', 'like', $pola)
                        ->orWhere('application', 'like', $pola)
                        ->orWhere('device_type', 'like', $pola)
                        ->orWhereHas('functionalLocation', function (Builder $relasi) use ($pola) {
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
     * Tag persis/awalan didahulukan, lalu IP persis/awalan, nama, pabrikan/
     * model/serial, dan lokasi/plant. Skor dihitung sebagai kolom `relevansi`
     * karena SQLite tidak mengikat parameter ORDER BY dengan benar.
     *
     * @param  Builder<ItAsset>  $query  Query perangkat
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
    WHEN LOWER(tag_name) = LOWER(?) THEN 1000
    WHEN tag_name LIKE ? THEN 950
    WHEN LOWER(ip_address) = LOWER(?) THEN 900
    WHEN ip_address LIKE ? THEN 850
    WHEN name LIKE ? THEN 750
    WHEN name LIKE ? THEN 650
    WHEN tag_name LIKE ? THEN 600
    WHEN ip_address LIKE ? THEN 580
    WHEN name LIKE ? THEN 560
    WHEN brand LIKE ? OR model LIKE ? OR serial_number LIKE ? THEN 500
    WHEN location LIKE ? OR plant LIKE ? THEN 450
    ELSE 100
END
SQL;

        $query->select('it_assets.*')
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
                $polaUtama,
                $polaUtama,
                $polaUtama,
            ])
            ->orderByDesc('relevansi');
    }

    /**
     * Susun rekomendasi pencarian untuk dropdown halaman IT Assets.
     *
     * Kelompok: perangkat, tipe, plant, lokasi, dan VLAN (bila kata kunci
     * memuat angka). Setiap item sudah membawa URL tujuan.
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

        $perangkat = $this->saranPerangkat($kunci);

        if ($perangkat['items'] !== []) {
            $kelompok[] = $perangkat;
        }

        foreach ([
            $this->saranTipe($token),
            $this->saranPlant($token),
            $this->saranLokasi($token),
            $this->saranVlan($token),
        ] as $grup) {
            if ($grup['items'] !== []) {
                $kelompok[] = $grup;
            }
        }

        return [
            'kata_kunci' => $kunci,
            'total' => $perangkat['jumlah'],
            'kelompok' => $kelompok,
        ];
    }

    /**
     * Saran potongan perangkat yang cocok, maksimal batas_saran_aset.
     *
     * @param  string  $kunci  Kata kunci yang sudah dibersihkan
     * @return array<string, mixed>
     */
    private function saranPerangkat(string $kunci): array
    {
        $dasar = ItAsset::query();

        $this->terapkan($dasar, $kunci);

        $jumlah = (clone $dasar)->count();

        $query = (clone $dasar)->with('functionalLocation:id,code,name');

        $this->terapkanUrutan($query, $kunci);

        $perangkat = $query
            ->orderBy('tag_name')
            ->limit((int) config('pencarian_entitas.batas_saran_aset', 5))
            ->get();

        $items = $perangkat->map(function (ItAsset $aset) {
            $meta = array_filter([
                $this->labelTipe($aset->device_type),
                $aset->ip_address,
                $aset->plant,
                $aset->location,
            ], fn ($nilai) => $nilai !== null && $nilai !== '');

            return [
                'kunci' => 'perangkat-'.$aset->id,
                'judul' => (string) ($aset->tag_name ?: $aset->name ?: ('#'.$aset->id)),
                'subjudul' => (string) ($aset->name ?: '(tanpa nama)'),
                'meta' => implode(' · ', array_map('strval', $meta)),
                'url' => route('it-assets.show', $aset->id),
            ];
        })->values()->all();

        return [
            'tipe' => 'perangkat',
            'label' => 'Perangkat',
            'jumlah' => $jumlah,
            'items' => $items,
        ];
    }

    /**
     * Saran tipe perangkat. Pencocokan memakai kode DAN label tipe (mis.
     * "kamera" -> CCTV, "laptop" -> LAPTOP) dengan semantik AND antar kata.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranTipe(array $token): array
    {
        $label = (array) config('pencarian_entitas.label_tipe_it', []);
        $sinonim = (array) config('pencarian_entitas.sinonim_it', []);
        $kandidat = null;

        foreach ($token as $kata) {
            $varian = array_map('mb_strtolower', PencarianKataKunci::variasi($kata, $sinonim));
            $cocok = [];

            foreach (ItAsset::DEVICE_TYPES as $kode) {
                $teksKode = mb_strtolower($kode);
                $teksLabel = mb_strtolower((string) ($label[$kode] ?? $kode));

                foreach ($varian as $teks) {
                    if (str_contains($teksKode, $teks) || str_contains($teksLabel, $teks)) {
                        $cocok[$kode] = true;
                        break;
                    }
                }
            }

            $kandidat = $kandidat === null ? $cocok : array_intersect_key($kandidat, $cocok);

            if ($kandidat === []) {
                break;
            }
        }

        $kandidat = array_keys($kandidat ?? []);
        sort($kandidat);

        $items = [];

        foreach (array_slice($kandidat, 0, (int) config('pencarian_entitas.batas_saran_entitas', 3)) as $kode) {
            $items[] = [
                'kunci' => 'tipe-'.$kode,
                'judul' => $this->labelTipe($kode),
                'subjudul' => 'Tipe perangkat',
                'meta' => number_format(ItAsset::where('device_type', $kode)->count()).' perangkat',
                'url' => route('it-assets.index', ['device_type' => $kode]),
            ];
        }

        return [
            'tipe' => 'tipe',
            'label' => 'Tipe',
            'jumlah' => count($kandidat),
            'items' => $items,
        ];
    }

    /**
     * Saran plant perangkat.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranPlant(array $token): array
    {
        $query = ItAsset::query()->whereNotNull('plant')->where('plant', '!=', '');

        $this->terapkanTokenEntitas($query, ['plant'], $token);

        $jumlah = (clone $query)->distinct()->count('plant');

        $items = $query
            ->distinct()
            ->orderBy('plant')
            ->limit((int) config('pencarian_entitas.batas_saran_entitas', 3))
            ->pluck('plant')
            ->map(fn (string $plant) => [
                'kunci' => 'plant-'.$plant,
                'judul' => $plant,
                'subjudul' => 'Plant',
                'meta' => number_format(ItAsset::where('plant', $plant)->count()).' perangkat',
                'url' => route('it-assets.index', ['plant' => $plant]),
            ])
            ->values()
            ->all();

        return [
            'tipe' => 'plant',
            'label' => 'Plant',
            'jumlah' => $jumlah,
            'items' => $items,
        ];
    }

    /**
     * Saran lokasi teks perangkat. Klik item mengisi kata kunci lokasi.
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranLokasi(array $token): array
    {
        $query = ItAsset::query()->whereNotNull('location')->where('location', '!=', '');

        $this->terapkanTokenEntitas($query, ['location'], $token);

        $jumlah = (clone $query)->distinct()->count('location');

        $items = $query
            ->distinct()
            ->orderBy('location')
            ->limit((int) config('pencarian_entitas.batas_saran_entitas', 3))
            ->pluck('location')
            ->map(fn (string $lokasi) => [
                'kunci' => 'lokasi-'.$lokasi,
                'judul' => $lokasi,
                'subjudul' => 'Lokasi perangkat',
                'meta' => number_format(ItAsset::where('location', $lokasi)->count()).' perangkat',
                'url' => route('it-assets.index', ['search' => $lokasi]),
            ])
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
     * Saran VLAN bila kata kunci memuat angka (mis. "41" atau "vlan 41").
     *
     * @param  array<int, string>  $token  Token pencarian
     * @return array<string, mixed>
     */
    private function saranVlan(array $token): array
    {
        $angka = collect($token)->first(fn (string $kata) => ctype_digit($kata));

        if ($angka === null) {
            return ['tipe' => 'vlan', 'label' => 'VLAN', 'jumlah' => 0, 'items' => []];
        }

        $vlanList = IpNetwork::query()
            ->where('vlan', 'like', '%'.$angka.'%')
            ->orderBy('vlan')
            ->limit((int) config('pencarian_entitas.batas_saran_entitas', 3))
            ->pluck('vlan')
            ->map(fn ($vlan) => (int) $vlan)
            ->unique()
            ->values();

        $items = $vlanList->map(function (int $vlan) {
            $jumlah = ItAsset::where('ip_address', 'like', '192.168.'.$vlan.'.%')->count();

            return [
                'kunci' => 'vlan-'.$vlan,
                'judul' => 'VLAN '.$vlan,
                'subjudul' => 'Rentang IP 192.168.'.$vlan.'.x',
                'meta' => number_format($jumlah).' perangkat',
                'url' => route('it-assets.index', ['vlan' => $vlan]),
            ];
        })->values()->all();

        return [
            'tipe' => 'vlan',
            'label' => 'VLAN',
            'jumlah' => $vlanList->count(),
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
     * Label tampilan satu tipe perangkat.
     *
     * @param  string|null  $tipe  Kode device_type
     */
    private function labelTipe(?string $tipe): string
    {
        $label = (array) config('pencarian_entitas.label_tipe_it', []);

        return (string) ($label[$tipe] ?? $tipe ?? '');
    }
}
