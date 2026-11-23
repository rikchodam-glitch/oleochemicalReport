<?php

namespace App\Services;

use App\Models\Tool;
use App\Models\ToolGroup;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolType;
use App\Support\PencarianKataKunci;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu sumber aturan pencarian Management Tools.
 *
 * Kata kunci dipecah menjadi token; SEMUA token harus cocok (AND), satu token
 * boleh cocok pada kode, nama unit, kelompok, jenis, lokasi, atau rak (OR).
 */
class PencarianToolService
{
    /**
     * Panjang kata kunci minimum.
     */
    public function minHuruf(): int
    {
        return (int) config('pencarian_tools.min_huruf', 2);
    }

    /**
     * Terapkan filter kata kunci ke query alat.
     *
     * @param Builder<Tool> $query
     * @return Builder<Tool>
     */
    public function terapkan(Builder $query, string $kataKunci): Builder
    {
        $token = $this->token($kataKunci);

        if ($token === []) {
            return $query;
        }

        $sinonim = (array) config('pencarian_tools.sinonim', []);

        foreach ($token as $kata) {
            $varian = PencarianKataKunci::variasi($kata, $sinonim);

            $query->where(function (Builder $sub) use ($varian): void {
                foreach ($varian as $teks) {
                    $pola = '%' . $teks . '%';

                    $sub->orWhere('tools.code', 'like', $pola)
                        ->orWhere('tools.name', 'like', $pola)
                        ->orWhere('tools.note', 'like', $pola)
                        ->orWhereHas('group', function (Builder $grup) use ($pola): void {
                            $grup->where(function (Builder $kolom) use ($pola): void {
                                $kolom->where('name', 'like', $pola)
                                    ->orWhere('description', 'like', $pola);
                            })->orWhereHas('type', fn (Builder $jenis) => $jenis->where('name', 'like', $pola));
                        })
                        ->orWhereHas('rack', function (Builder $rak) use ($pola): void {
                            $rak->where('name', 'like', $pola)
                                ->orWhereHas('location', function (Builder $lokasi) use ($pola): void {
                                    $lokasi->where('name', 'like', $pola)
                                        ->orWhere('description', 'like', $pola);
                                });
                        });
                }
            });
        }

        return $query;
    }

    /**
     * Urutkan hasil dari yang paling relevan.
     *
     * @param Builder<Tool> $query
     */
    public function terapkanUrutan(Builder $query, string $kataKunci): void
    {
        $kunci = PencarianKataKunci::bersihkan($kataKunci);

        if ($kunci === '') {
            return;
        }

        $utama = $this->token($kataKunci)[0] ?? $kunci;
        $awalan = $kunci . '%';
        $pola = '%' . $kunci . '%';
        $polaUtama = '%' . $utama . '%';

        $ekspresi = <<<'SQL'
CASE
    WHEN LOWER(tools.code) = LOWER(?) THEN 1000
    WHEN tools.code LIKE ? THEN 950
    WHEN LOWER(COALESCE((SELECT name FROM tool_groups WHERE id = tools.tool_group_id), tools.name)) = LOWER(?) THEN 900
    WHEN COALESCE((SELECT name FROM tool_groups WHERE id = tools.tool_group_id), tools.name) LIKE ? THEN 850
    WHEN tools.name LIKE ? THEN 750
    WHEN tools.code LIKE ? THEN 650
    WHEN COALESCE((SELECT name FROM tool_groups WHERE id = tools.tool_group_id), tools.name) LIKE ? THEN 600
    WHEN EXISTS (
        SELECT 1 FROM tool_groups tg
        JOIN tool_types tt ON tt.id = tg.tool_type_id
        WHERE tg.id = tools.tool_group_id AND tt.name LIKE ?
    ) THEN 500
    WHEN EXISTS (
        SELECT 1 FROM tool_racks tr
        JOIN tool_locations tl ON tl.id = tr.tool_location_id
        WHERE tr.id = tools.tool_rack_id AND (tr.name LIKE ? OR tl.name LIKE ?)
    ) THEN 400
    ELSE 100
END as relevansi
SQL;

        $query->select('tools.*')
            ->selectRaw($ekspresi, [
                $kunci,
                $awalan,
                $kunci,
                $awalan,
                $pola,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
                $polaUtama,
            ])
            ->orderByDesc('relevansi')
            ->orderBy('tools.name')
            ->orderBy('tools.code');
    }

    /**
     * Saran pencarian untuk dropdown.
     *
     * @return array{kata_kunci:string,total:int,kelompok:array<int,array<string,mixed>>}
     */
    public function saran(string $kataKunci, string $basis = 'tools'): array
    {
        $kunci = PencarianKataKunci::bersihkan($kataKunci);
        $token = $this->token($kataKunci);

        if ($kunci === '' || $token === []) {
            return ['kata_kunci' => $kunci, 'total' => 0, 'kelompok' => []];
        }

        $kelompok = [];
        $alat = $this->saranAlat($kunci);
        $total = $alat['jumlah'];

        if ($alat['items'] !== []) {
            $kelompok[] = $alat;
        }

        foreach ([
            $this->saranKelompok($token, $basis),
            $this->saranJenis($token, $basis),
            $this->saranLokasi($token, $basis),
            $this->saranRak($token, $basis),
        ] as $grup) {
            if ($grup['items'] !== []) {
                $kelompok[] = $grup;
            }
        }

        return ['kata_kunci' => $kunci, 'total' => $total, 'kelompok' => $kelompok];
    }

    /**
     * @param array<int,string> $token
     * @return array<string,mixed>
     */
    private function saranAlat(string $kunci): array
    {
        $dasar = Tool::query();
        $this->terapkan($dasar, $kunci);
        $jumlah = (clone $dasar)->count();

        $query = (clone $dasar)->with(['group.type', 'rack.location', 'pinjamanAktif']);
        $this->terapkanUrutan($query, $kunci);

        $items = $query->limit((int) config('pencarian_tools.batas_saran_alat', 5))
            ->get()
            ->map(function (Tool $alat): array {
                $nama = $alat->group?->name ?? $alat->name;

                return [
                    'kunci'    => 'alat-' . $alat->id,
                    'judul'    => $nama,
                    'subjudul' => $alat->code,
                    'meta'     => implode(' · ', array_filter([
                        $alat->statusLabel(),
                        $alat->rack?->location?->name,
                        $alat->rack?->name,
                        $alat->group?->type?->name,
                    ])),
                    'url'      => route('alat.show', $alat->id),
                ];
            })
            ->values()
            ->all();

        return ['tipe' => 'alat', 'label' => 'Alat', 'jumlah' => $jumlah, 'items' => $items];
    }

    /**
     * @param array<int,string> $token
     * @return array<string,mixed>
     */
    private function saranKelompok(array $token, string $basis): array
    {
        $query = ToolGroup::query();
        $this->terapkanTokenEntitas($query, ['name'], $token);

        $jumlah = (clone $query)->count();

        $items = $query->with('type')
            ->withCount('tools')
            ->orderBy('name')
            ->limit((int) config('pencarian_tools.batas_saran_entitas', 3))
            ->get()
            ->map(fn (ToolGroup $grup): array => [
                'kunci'    => 'kelompok-' . $grup->id,
                'judul'    => $grup->name,
                'subjudul' => 'Kelompok' . ($grup->type?->name ? ' · ' . $grup->type->name : ''),
                'meta'     => $grup->tools_count . ' unit',
                'url'      => route($basis . '.index', ['grup' => $grup->id]),
            ])
            ->values()
            ->all();

        return ['tipe' => 'kelompok', 'label' => 'Kelompok', 'jumlah' => $jumlah, 'items' => $items];
    }

    /**
     * @param array<int,string> $token
     * @return array<string,mixed>
     */
    private function saranJenis(array $token, string $basis): array
    {
        $query = ToolType::query();
        $this->terapkanTokenEntitas($query, ['name'], $token);

        $jumlah = (clone $query)->count();

        $items = $query->withCount('groups')
            ->orderBy('name')
            ->limit((int) config('pencarian_tools.batas_saran_entitas', 3))
            ->get()
            ->map(fn (ToolType $jenis): array => [
                'kunci'    => 'jenis-' . $jenis->id,
                'judul'    => $jenis->name,
                'subjudul' => 'Jenis',
                'meta'     => $jenis->groups_count . ' kelompok',
                'url'      => route($basis . '.index', ['jenis' => $jenis->id]),
            ])
            ->values()
            ->all();

        return ['tipe' => 'jenis', 'label' => 'Jenis', 'jumlah' => $jumlah, 'items' => $items];
    }

    /**
     * @param array<int,string> $token
     * @return array<string,mixed>
     */
    private function saranLokasi(array $token, string $basis): array
    {
        $query = ToolLocation::query();
        $this->terapkanTokenEntitas($query, ['name'], $token);

        $jumlah = (clone $query)->count();

        $items = $query->withCount('racks')
            ->orderBy('name')
            ->limit((int) config('pencarian_tools.batas_saran_entitas', 3))
            ->get()
            ->map(fn (ToolLocation $lokasi): array => [
                'kunci'    => 'lokasi-' . $lokasi->id,
                'judul'    => $lokasi->name,
                'subjudul' => 'Lokasi',
                'meta'     => $lokasi->racks_count . ' rak',
                'url'      => route($basis . '.index', ['lokasi' => $lokasi->id]),
            ])
            ->values()
            ->all();

        return ['tipe' => 'lokasi', 'label' => 'Lokasi', 'jumlah' => $jumlah, 'items' => $items];
    }

    /**
     * @param array<int,string> $token
     * @return array<string,mixed>
     */
    private function saranRak(array $token, string $basis): array
    {
        $query = ToolRack::query()->with('location');
        $this->terapkanTokenEntitas($query, ['name'], $token);

        $jumlah = (clone $query)->count();

        $items = $query->orderBy('name')
            ->limit((int) config('pencarian_tools.batas_saran_entitas', 3))
            ->get()
            ->map(fn (ToolRack $rak): array => [
                'kunci'    => 'rak-' . $rak->id,
                'judul'    => $rak->name,
                'subjudul' => 'Rak' . ($rak->location?->name ? ' · ' . $rak->location->name : ''),
                'meta'     => '',
                'url'      => route($basis . '.index', ['rak' => $rak->id]),
            ])
            ->values()
            ->all();

        return ['tipe' => 'rak', 'label' => 'Rak', 'jumlah' => $jumlah, 'items' => $items];
    }

    /**
     * Terapkan semua token ke beberapa kolom.
     *
     * @param array<int,string> $token
     */
    private function terapkanTokenEntitas(Builder $query, array $kolom, array $token): void
    {
        foreach ($token as $kata) {
            $pola = '%' . $kata . '%';

            $query->where(function (Builder $sub) use ($kolom, $pola): void {
                foreach ($kolom as $nama) {
                    $sub->orWhere($nama, 'like', $pola);
                }
            });
        }
    }

    /**
     * @return array<int,string>
     */
    private function token(string $kataKunci): array
    {
        return PencarianKataKunci::token(
            $kataKunci,
            $this->minHuruf(),
            (int) config('pencarian_tools.maks_token', 8)
        );
    }
}
