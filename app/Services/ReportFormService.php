<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\ReportToken;
use App\Models\Technician;
use App\Services\CollaborationService;
use App\Services\ReportCopyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Layanan form laporan web berbasis token Telegram.
 *
 * Menggabungkan aturan wizard Telegram: klasifikasi -> jenis, hierarki lokasi,
 * equipment/area, multi-alat, kolaborator otomatis/manual, foto, status, dan
 * penyimpanan beberapa laporan dalam satu transaksi.
 */
class ReportFormService
{
    public const MASA_BERLAKU_MENIT = 30;
    public const MAKS_LAPORAN       = 5;
    public const MAKS_ITEM          = 5;

    /** Peta klasifikasi ZPM -> jenis pekerjaan yang valid. */
    private const JENIS_PER_KLASIFIKASI = [
        'zpm1' => ['EI', 'Mekanik', 'IT'],
        'zpm6' => ['EI', 'Mekanik', 'IT'],
        'zpm3' => ['CM/PM'],
        'zpm5' => ['Fabrikasi'],
        'zpm7' => ['Sipil'],
        'zpm8' => ['Project'],
        'umum' => ['UMUM'],
    ];

    public function __construct(
        private readonly ReportCopyService $salinan,
        private readonly CollaborationService $kolaborasi
    ) {
    }

    /**
     * Membuat token form laporan baru.
     *
     * @return array{token: ReportToken, plain: string, url: string}
     */
    public function buatToken(Technician $teknisi, ?string $deskripsi = null): array
    {
        // Tautan lama yang belum pernah dibuka dibatalkan. Tautan yang sudah
        // dibuka tidak dibatalkan agar form yang sedang diisi tetap hidup.
        ReportToken::query()
            ->where('technician_id', $teknisi->id)
            ->whereNull('used_at')
            ->whereNull('opened_at')
            ->update(['used_at' => now()]);

        $plain = Str::random(64);
        $token = ReportToken::create([
            'technician_id' => $teknisi->id,
            'token_hash'    => hash('sha256', $plain),
            'telegram_id'   => $teknisi->telegram_id,
            'description'   => $this->rapikanTeks($deskripsi, 2000),
            'expires_at'    => now()->addMinutes(self::MASA_BERLAKU_MENIT),
        ]);

        return [
            'token' => $token,
            'plain' => $plain,
            'url'   => route('laporan.form', ['token' => $plain]),
        ];
    }

    /**
     * Cari token yang masih valid.
     */
    public function tokenValid(string $plain): ?ReportToken
    {
        $token = ReportToken::with('technician')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        return $token !== null && $token->masihBerlaku() ? $token : null;
    }

    /**
     * Tandai tautan sudah pernah dibuka.
     */
    public function tandaiDibuka(ReportToken $token): void
    {
        if ($token->opened_at === null) {
            $token->update(['opened_at' => now()]);
        }
    }

    /**
     * Data pendukung form.
     *
     * @return array<string, mixed>
     */
    public function dataForm(ReportToken $token): array
    {
        $teknisi = $token->technician;

        $nodes = FunctionalLocation::query()
            ->where('is_active', true)
            ->orderBy('level')
            ->orderBy('code')
            ->get(['id', 'parent_id', 'level', 'code', 'name'])
            ->map(fn (FunctionalLocation $node): array => [
                'id'        => $node->id,
                'parent_id' => $node->parent_id,
                'level'     => $node->level,
                'code'      => $node->code,
                'name'      => $node->name,
            ])
            ->values()
            ->all();

        $kolaborator = Technician::query()
            ->where('status', 'active')
            ->where('id', '!=', $teknisi?->id)
            ->orderBy('name')
            ->get(['id', 'name', 'nik', 'group', 'section'])
            ->map(fn (Technician $rekan): array => [
                'id'      => $rekan->id,
                'name'    => $rekan->name,
                'nik'     => $rekan->nik,
                'group'   => $rekan->group,
                'section' => $rekan->section,
            ])
            ->values()
            ->all();

        $autoIds = $teknisi !== null
            ? $this->kolaborasi->autoCollaboratorsFor($teknisi)->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : [];

        return [
            'teknisi'       => $teknisi,
            'nodes'         => $nodes,
            'kolaborator'   => $kolaborator,
            'autoIds'       => $autoIds,
            'deskripsiAwal' => $token->description,
        ];
    }

    /**
     * Simpan satu atau beberapa laporan dari form.
     *
     * @param  array<int, array<string, mixed>> $reports
     * @return int Jumlah laporan induk yang dibuat
     */
    public function simpanBanyak(ReportToken $token, array $reports, Request $request): int
    {
        $token->refresh();

        if (! $token->masihBerlaku()) {
            throw ValidationException::withMessages([
                'token' => 'Tautan laporan sudah kedaluwarsa atau sudah dipakai. Kirim /laporan lagi.',
            ]);
        }

        $teknisi = $token->technician;

        if ($teknisi === null) {
            throw ValidationException::withMessages(['token' => 'Teknisi pemilik tautan tidak ditemukan.']);
        }

        $nodes       = FunctionalLocation::query()->get(['id', 'parent_id'])->all();
        $autoIds     = $this->kolaborasi->autoCollaboratorsFor($teknisi)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $disiapkan   = [];
        $fotoTersimpan = [];

        foreach ($reports as $index => $laporan) {
            $disiapkan[] = $this->validasiLaporan($laporan, $teknisi, $nodes, $autoIds, (int) $index);
        }

        $idLaporan = [];

        DB::beginTransaction();

        try {
            // Kunci token selama transaksi agar dua submit bersamaan tidak
            // sama-sama lolos dan membuat laporan ganda.
            $token = ReportToken::query()->lockForUpdate()->find($token->id);

            if ($token === null || ! $token->masihBerlaku()) {
                throw ValidationException::withMessages([
                    'token' => 'Tautan laporan sudah dipakai oleh permintaan lain. Kirim /laporan lagi.',
                ]);
            }

            foreach ($disiapkan as $index => $siap) {
                $laporan = Report::create($siap['report']);
                $idLaporan[] = $laporan->id;

                $foto = $this->simpanFoto($laporan, $request, $index);
                $fotoTersimpan = array_merge(
                    $fotoTersimpan,
                    $foto['documentation'],
                    $foto['hygiene']
                );

                $laporan->update([
                    'photo_documentation'     => $foto['documentation'],
                    'photo_hygiene_clearance' => $foto['hygiene'],
                ]);

                // Salinan alat/lokasi tambahan (multi_of) tidak dihitung man hour.
                foreach (array_slice($siap['items'], 1) as $item) {
                    $this->salinan->buatSalinanMulti($laporan, $item['asset'], $item['funcloc']);
                }

                foreach ($siap['collaborators'] as $kolaborator) {
                    $this->salinan->buatSalinanKolaborasi($laporan, $kolaborator);
                }
            }

            $token->update(['used_at' => now()]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->hapusFoto($fotoTersimpan);
            throw $e;
        }

        // Broadcast setelah commit agar kegagalan Telegram tidak membatalkan laporan.
        if (class_exists(\App\Jobs\KirimBroadcastKolaborasi::class)) {
            foreach ($idLaporan as $id) {
                \App\Jobs\KirimBroadcastKolaborasi::dispatch($id);
            }
        }

        return count($idLaporan);
    }

    /**
     * Tipe equipment yang tersedia pada subtree lokasi.
     *
     * @return array<int, string>
     */
    public function tipeAlat(ReportToken $token, int $funclocId): array
    {
        $ids = $this->descendantIds($funclocId);

        return Asset::query()
            ->whereIn('funcloc_id', $ids)
            ->whereNotNull('equipment_type')
            ->where('equipment_type', '!=', '')
            ->distinct()
            ->orderBy('equipment_type')
            ->pluck('equipment_type')
            ->values()
            ->all();
    }

    /**
     * Cari alat dengan pendekatan exact/prefix/LIKE + skor kemiripan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cariAlat(ReportToken $token, int $funclocId, string $q = '', string $type = '', int $limit = 20): array
    {
        $ids = $this->descendantIds($funclocId);
        $q = trim($q);

        $query = Asset::query()
            ->whereIn('funcloc_id', $ids)
            ->select(['id', 'tech_ident_no', 'description', 'equipment_type', 'funcloc_id', 'area_id']);

        if ($type !== '') {
            $query->where('equipment_type', $type);
        }

        if ($q === '') {
            return $query->orderBy('tech_ident_no')->limit($limit)->get()
                ->map(fn (Asset $aset): array => $this->asetUntukJson($aset))->all();
        }

        $kandidat = $query->where(function ($sub) use ($q): void {
            $sub->where('tech_ident_no', 'like', '%' . $q . '%')
                ->orWhere('description', 'like', '%' . $q . '%');
        })->limit(250)->get();

        $qLower = mb_strtolower($q);

        return $kandidat
            ->map(function (Asset $aset) use ($qLower): array {
                $kode = mb_strtolower((string) $aset->tech_ident_no);
                $desc = mb_strtolower((string) $aset->description);
                $skor = 0.0;

                if ($kode === $qLower) {
                    $skor += 1000;
                } elseif (str_starts_with($kode, $qLower)) {
                    $skor += 500;
                }

                similar_text($qLower, $kode, $persenKode);
                similar_text($qLower, $desc, $persenDesc);
                $skor += ($persenKode * 3) + $persenDesc;

                return $this->asetUntukJson($aset) + ['_skor' => $skor];
            })
            ->sortByDesc('_skor')
            ->take($limit)
            ->map(function (array $baris): array {
                unset($baris['_skor']);

                return $baris;
            })
            ->values()
            ->all();
    }

    /**
     * Validasi satu laporan dan siapkan atribut DB.
     *
     * @param  array<string, mixed> $laporan
     * @param  array<int, FunctionalLocation> $nodes
     * @param  array<int, int> $autoIds
     * @return array<string, mixed>
     */
    private function validasiLaporan(array $laporan, Technician $teknisi, array $nodes, array $autoIds, int $index): array
    {
        $prefix = "reports.{$index}.";

        $klasifikasi = (string) ($laporan['work_classification'] ?? '');
        $jenis       = (string) ($laporan['jenis_pekerjaan'] ?? '');

        if (! array_key_exists($klasifikasi, self::JENIS_PER_KLASIFIKASI)) {
            throw ValidationException::withMessages([$prefix . 'work_classification' => 'Klasifikasi pekerjaan tidak dikenal.']);
        }

        if (! in_array($jenis, self::JENIS_PER_KLASIFIKASI[$klasifikasi], true)) {
            throw ValidationException::withMessages([$prefix . 'jenis_pekerjaan' => 'Jenis pekerjaan tidak sesuai klasifikasi.']);
        }

        $mulai = Carbon::createFromFormat('H:i', (string) ($laporan['start_time'] ?? ''));
        $selesai = Carbon::createFromFormat('H:i', (string) ($laporan['end_time'] ?? ''));

        if ($selesai->lte($mulai)) {
            throw ValidationException::withMessages([$prefix . 'end_time' => 'Jam selesai harus setelah jam mulai.']);
        }

        $menit = (int) $mulai->diffInMinutes($selesai);

        if ($menit <= 0 || $menit > 1440) {
            throw ValidationException::withMessages([$prefix . 'end_time' => 'Durasi harus antara 1 menit dan 24 jam.']);
        }

        $items = $laporan['items'] ?? [];

        if (! is_array($items) || $items === [] || count($items) > self::MAKS_ITEM) {
            throw ValidationException::withMessages([$prefix . 'items' => 'Minimal satu dan maksimal ' . self::MAKS_ITEM . ' alat/lokasi.']);
        }

        $siapItems = [];
        $kunciItem = [];

        foreach ($items as $itemIndex => $item) {
            $funclocId = (int) ($item['funcloc_id'] ?? 0);
            $assetId   = ! empty($item['asset_id']) ? (int) $item['asset_id'] : null;

            $funcloc = FunctionalLocation::find($funclocId);

            if ($funcloc === null) {
                throw ValidationException::withMessages([
                    $prefix . "items.{$itemIndex}.funcloc_id" => 'Lokasi alat/area tidak ditemukan.',
                ]);
            }

            $asset = null;

            if ($assetId !== null) {
                $asset = Asset::find($assetId);

                if ($asset === null) {
                    throw ValidationException::withMessages([
                        $prefix . "items.{$itemIndex}.asset_id" => 'Alat tidak ditemukan.',
                    ]);
                }

                $turunan = $this->descendantIds($funclocId, $nodes);

                if (! in_array((int) $asset->funcloc_id, $turunan, true)) {
                    throw ValidationException::withMessages([
                        $prefix . "items.{$itemIndex}.asset_id" => 'Alat tidak berada pada lokasi yang dipilih.',
                    ]);
                }

                $tipe = trim((string) ($item['equipment_type'] ?? ''));

                if ($tipe !== '' && strcasecmp($tipe, (string) $asset->equipment_type) !== 0) {
                    throw ValidationException::withMessages([
                        $prefix . "items.{$itemIndex}.equipment_type" => 'Tipe alat tidak sesuai dengan alat yang dipilih.',
                    ]);
                }
            }

            $kunci = $asset !== null ? 'a:' . $asset->id : 'f:' . $funcloc->id;

            if (isset($kunciItem[$kunci])) {
                throw ValidationException::withMessages([$prefix . 'items' => 'Alat/lokasi yang sama tidak boleh dipilih dua kali.']);
            }

            $kunciItem[$kunci] = true;
            $siapItems[] = ['asset' => $asset, 'funcloc' => $funcloc];
        }

        $utama = $siapItems[0];
        $collaborators = collect($laporan['collaborator_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->merge($autoIds)
            ->reject(fn ($id) => $id === (int) $teknisi->id)
            ->unique()
            ->values();

        $kolaborator = Technician::query()
            ->whereIn('id', $collaborators)
            ->where('status', 'active')
            ->get(['id'])
            ->all();

        return [
            'report' => [
                'technician_id'         => $teknisi->id,
                'creator_id'            => $teknisi->id,
                'report_date'           => $laporan['report_date'],
                'report_code'           => Report::generateReportCode(),
                'work_description'      => (string) $laporan['work_description'],
                'work_duration_minutes' => $menit,
                'start_time'            => $mulai->format('H:i:s'),
                'end_time'              => $selesai->format('H:i:s'),
                'root_cause'            => $this->rapikanTeks($laporan['root_cause'] ?? null),
                'finding'               => $this->rapikanTeks($laporan['finding'] ?? null),
                'tanggal_kejadian'      => $laporan['tanggal_kejadian'] ?? null,
                'report_type'           => $utama['asset'] !== null ? 'equipment_repair' : 'area_work',
                'work_classification'   => $klasifikasi === 'umum' ? null : $klasifikasi,
                'jenis_pekerjaan'       => $jenis,
                'equipment_tag'         => $utama['asset']?->tech_ident_no,
                'asset_id'              => $utama['asset']?->id,
                'funcloc_id'            => $utama['asset']?->funcloc_id ?? $utama['funcloc']->id,
                'area_id'               => $utama['asset']?->area_id ?? $utama['funcloc']->area_id,
                'status'                => $laporan['final_status'],
                'completed_at'          => $laporan['final_status'] === 'completed' ? now() : null,
                'submitted_at'          => now(),
                'wizard_started_at'     => now(),
                'is_manually_edited'    => true,
                'ai_analyzed'           => false,
                'photo_documentation'   => [],
                'photo_hygiene_clearance' => [],
            ],
            'items'         => $siapItems,
            'collaborators' => $kolaborator,
        ];
    }

    /**
     * Simpan foto satu laporan.
     *
     * @return array<string, array<int, string>>
     */
    private function simpanFoto(Report $laporan, Request $request, int $index): array
    {
        $disk   = config('telegram.photo_disk', 'public');
        $folder = config('telegram.photo_folder', 'reports');
        $hasil  = ['documentation' => [], 'hygiene' => []];

        foreach (['documentation' => 'photos_documentation', 'hygiene' => 'photos_hygiene'] as $kunci => $input) {
            $files = $request->file("reports.{$index}.{$input}", []);

            if (! is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                $hasil[$kunci][] = $file->store(
                    "{$folder}/" . now()->format('Y/m/d') . "/{$laporan->id}",
                    $disk
                );
            }
        }

        return $hasil;
    }

    /**
     * Hapus foto bila transaksi gagal.
     *
     * @param array<int, string> $foto
     */
    private function hapusFoto(array $foto): void
    {
        $disk = Storage::disk(config('telegram.photo_disk', 'public'));

        foreach ($foto as $path) {
            $disk->delete($path);
        }
    }

    /**
     * Semua ID lokasi dalam subtree (termasuk node itu sendiri).
     *
     * @param array<int, FunctionalLocation>|null $nodes
     * @return array<int, int>
     */
    private function descendantIds(int $rootId, ?array $nodes = null): array
    {
        $nodes ??= FunctionalLocation::query()->get(['id', 'parent_id'])->all();
        $anak = [];

        foreach ($nodes as $node) {
            $anak[(int) $node->parent_id][] = (int) $node->id;
        }

        $hasil = [$rootId];
        $antre = [$rootId];

        while ($antre !== []) {
            $id = array_shift($antre);

            foreach ($anak[$id] ?? [] as $child) {
                if (! in_array($child, $hasil, true)) {
                    $hasil[] = $child;
                    $antre[] = $child;
                }
            }
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    private function asetUntukJson(Asset $aset): array
    {
        return [
            'id'              => $aset->id,
            'tech_ident_no'   => $aset->tech_ident_no,
            'description'     => $aset->description,
            'equipment_type'  => $aset->equipment_type,
            'funcloc_id'      => $aset->funcloc_id,
            'area_id'         => $aset->area_id,
        ];
    }

    private function rapikanTeks(?string $teks, int $maks = 2000): ?string
    {
        if ($teks === null) {
            return null;
        }

        $bersih = trim($teks);

        return $bersih === '' ? null : mb_substr($bersih, 0, $maks);
    }
}
