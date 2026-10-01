<?php

namespace App\Services\Telegram\Traits;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\SubArea;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * WizardFuncLocPickerTrait
 *
 * Menangani pemilihan Functional Location (FuncLoc) hierarki oleh teknisi
 * saat jenis pekerjaan "area/section" dipilih di wizard laporan.
 *
 * Alur pemilihan (diperluas Sesi 4 agar mulai dari PT, bukan langsung
 * Departemen — mengikuti hierarki penuh FunctionalLocation di database):
 *   1. Tampil keyboard PT (LEVEL_SITE) setelah work_type:area dipilih
 *   2. Setelah PT dipilih: tampil keyboard Departemen (LEVEL_DEPARTMENT) + tombol "Konfirmasi di sini"
 *   3. Setelah Departemen dipilih: tampil keyboard Area (LEVEL_AREA) + tombol "Konfirmasi di sini"
 *   4. Setelah Area dipilih: tampil keyboard Sub Area/Section (LEVEL_SECTION) + tombol "Konfirmasi di sini"
 *   5. Setelah Sub Area dipilih ATAU tombol konfirmasi ditekan di level manapun:
 *      state['funcloc_id'] dan state['funcloc_code'] dikunci, lanjut ke Step 4
 *
 * Callback data yang digunakan:
 *   - funcloc_pick:{level}:{id}   : Teknisi memilih node pada level tertentu
 *   - funcloc_confirm:{id}        : Teknisi mengonfirmasi node saat ini tanpa turun lebih dalam
 *   - funcloc_back                : Teknisi naik satu level hierarki (Sesi 5)
 *
 * NAVIGASI NAIK SATU LEVEL (funcloc_back, ditambahkan Sesi 5):
 * State wizard menyimpan 'funcloc_path' — stack berisi node yang pernah
 * dipilih (level, id, code, name) sepanjang perjalanan turun hierarki.
 * Setiap kali teknisi memilih node di PT, Departemen, atau Area (turun ke
 * level berikutnya), node tersebut di-push ke 'funcloc_path' lewat
 * handleFuncLocPick(). Tombol "Naik Satu Level" (callback_data
 * 'funcloc_back') men-pop entri terakhir dan membangun ulang keyboard
 * level tersebut memakai parent hasil pop (atau kembali ke root PT jika
 * stack sudah kosong). Mekanisme ini
 * terpisah dari 'wizard:back' (step_history) milik WizardCallbackHandlerTrait
 * karena sifatnya naik-turun DI DALAM Step 2/3, bukan berpindah antar step
 * utama wizard — sama seperti ClarificationService yang juga punya
 * back internal sendiri (action==='back') terpisah dari 'wizard:back'.
 * Tombol Batalkan Laporan (callback_data 'wizard:cancel_wizard') tetap
 * disertakan konsisten di setiap layar level.
 *
 * PENCARIAN TEKS (ditambahkan Sesi 5):
 * Selama funcloc_picker_active bernilai true, teks apa pun yang diketik
 * teknisi dirutekan oleh ReportWizardService::handleTextInput() ke
 * handleFuncLocTextSearch() alih-alih step switch biasa. Pencarian bersifat
 * global ke seluruh level (PT..Sub Area), sebagai pelengkap navigasi
 * tombol — bukan pengganti. Hasil pencarian memakai callback_data
 * funcloc_pick yang sama dengan tombol level normal, jadi tetap diproses
 * lewat handleFuncLocPick() tanpa kontrak callback baru.
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - saveState(string $chatId, array $state): void
 *   - advanceToWorkDuration(string $chatId, array $state): array
 *   - errorResponse(string $message): array
 */
trait WizardFuncLocPickerTrait
{
    /** Jumlah equipment dalam satu lokasi sebelum dikelompokkan per tipe alat. */
    protected const EQUIPMENT_GROUP_THRESHOLD = 10;

    /** Label pengelompokan untuk alat yang Equipment Type-nya belum diisi. */
    protected const TANPA_TIPE_LABEL = 'TANPA TIPE';

    /**
     * Sisipkan tombol pintasan lokasi ke respons wizard: ditambahkan sebelum
     * baris tombol navigasi (Kembali/Batalkan) supaya mudah dijangkau, dan
     * pesannya diberi keterangan singkat.
     *
     * @param  array   $response  Respons wizard (message + keyboard)
     * @param  string  $text      Teks laporan mentah dari teknisi
     * @return array   Respons dengan tombol pintasan (tidak berubah bila lokasi tidak disebut)
     */
    protected function appendLocationShortcuts(array $response, string $text): array
    {
        $tombol = $this->buildLocationShortcutButtons($text);

        if ($tombol === []) {
            return $response;
        }

        $keyboard  = $response['keyboard'] ?? [];
        $posisiNav = null;

        foreach ($keyboard as $indeks => $baris) {
            if (str_starts_with((string) ($baris['callback_data'] ?? ''), 'wizard:')) {
                $posisiNav = $indeks;
                break;
            }
        }

        if ($posisiNav === null) {
            $keyboard = array_merge($keyboard, $tombol);
        } else {
            array_splice($keyboard, $posisiNav, 0, $tombol);
        }

        $response['message'] = ($response['message'] ?? '')
            . "\n\nKalau ini pekerjaan AREA (bukan satu alat tertentu), pilih lokasinya langsung:";
        $response['keyboard'] = $keyboard;

        return $response;
    }

    /**
     * Cari node FunctionalLocation yang mewakili kombinasi Departemen/Area/Sub Area.
     *
     * Kode node mengikuti pola PT-DEPARTEMEN-AREA-SUBAREA, jadi kode disusun dari
     * master lokasi yang dipilih. Bila node-nya tidak ada, laporan tetap bisa
     * disimpan sebagai laporan area memakai area_id.
     *
     * @param  int|null  $deptId     ID Departemen (opsional)
     * @param  int|null  $areaId     ID Area (opsional)
     * @param  int|null  $subAreaId  ID Sub Area (opsional)
     * @return FunctionalLocation|null
     */
    protected function resolveFuncLocForLocation(?int $deptId, ?int $areaId, ?int $subAreaId): ?FunctionalLocation
    {
        $dept = $deptId !== null ? Department::with('company')->find($deptId) : null;

        if ($dept === null) {
            return null;
        }

        $segmen = array_filter([
            $dept->company?->code,
            $dept->code,
            $areaId !== null ? Area::where('id', $areaId)->value('code') : null,
            $subAreaId !== null ? SubArea::where('id', $subAreaId)->value('code') : null,
        ]);

        if (count($segmen) < 2) {
            return null;
        }

        return FunctionalLocation::where('code', implode('-', $segmen))->first();
    }

    /**
     * Jumlah alat pada satu level lokasi (untuk label tombol pintasan).
     *
     * @param  string  $level  'sub' | 'area' | 'dept'
     * @param  int     $id     ID lokasi
     * @return int
     */
    protected function assetsCountForLocationLevel(string $level, int $id): int
    {
        return match ($level) {
            'sub'   => Asset::where('sub_area_id', $id)->count(),
            'area'  => Asset::where('area_id', $id)->count(),
            default => Asset::where('department_id', $id)->count(),
        };
    }

    /**
     * Normalisasi teks untuk pencocokan lokasi: huruf besar, semua karakter
     * selain huruf/angka menjadi spasi, spasi ganda dirapikan, dan diberi spasi
     * pengapit supaya pencocokan kata utuh mudah.
     *
     * @param  string  $text
     * @return string
     */
    private function normalizeTextForLocation(string $text): string
    {
        $bersih = preg_replace('/[^A-Za-z0-9]+/', ' ', strtoupper($text)) ?? '';

        return ' ' . trim(preg_replace('/\s+/', ' ', $bersih) ?? '') . ' ';
    }

    /**
     * Apakah kode/nama lokasi disebut sebagai kata utuh pada teks ternormalisasi.
     *
     * @param  string       $teksPadat  Teks hasil normalizeTextForLocation()
     * @param  string|null  $kata       Kode atau nama lokasi
     * @return bool
     */
    private function textMentionsLocation(string $teksPadat, ?string $kata): bool
    {
        $kata = trim(preg_replace('/[^A-Za-z0-9]+/', ' ', strtoupper((string) $kata)) ?? '');

        if ($kata === '') {
            return false;
        }

        if (str_contains($teksPadat, ' ' . $kata . ' ')) {
            return true;
        }

        // Kode berawalan nol: "BD02" juga cocok dengan penulisan "BD 2" / "BD-2".
        if (preg_match('/^([A-Z]+)0*([0-9]+)$/', $kata, $cocok) === 1) {
            return preg_match('/\b' . $cocok[1] . '\s*0*' . $cocok[2] . '\b/', $teksPadat) === 1;
        }

        return false;
    }

    /** Callback data tombol pintasan laporan area: wizard_loc:{level}:{id}. */
    protected const CALLBACK_LOCATION_SHORTCUT = 'wizard_loc:';

    /** Callback tombol "Selesai Menambah": menutup penelusuran hirarki mode multi. */
    protected const CALLBACK_MULTI_SELESAI = 'multi_hirarki_selesai';

    /** Callback tombol "Hapus Terakhir": buang item tambahan terakhir dari layar hirarki. */
    protected const CALLBACK_MULTI_HAPUS = 'multi_hirarki_hapus';

    /** Callback tombol kembali ke layar hirarki dari layar hasil pencarian. */
    protected const CALLBACK_FUNCLOC_KEMBALI = 'funcloc_kembali';

    /**
     * Apakah picker hirarki sedang dipakai untuk menambah alat/lokasi tambahan
     * pada pekerjaan yang sudah terkunci (mode 'multi').
     *
     * @param  array  $state  State wizard saat ini
     * @return bool
     */
    protected function isModeMulti(array $state): bool
    {
        return ($state['funcloc_picker_mode'] ?? '') === 'multi';
    }

    /**
     * Ringkasan daftar alat/lokasi tambahan untuk ditempel di bawah pesan
     * setiap layar hirarki mode multi, supaya teknisi selalu melihat apa yang
     * sudah dipilih tanpa harus keluar dari penelusuran hierarki.
     *
     * @param  array  $state  State wizard saat ini
     * @return string  Teks ringkasan (diawali dua baris baru)
     */
    protected function ringkasanItemMulti(array $state): string
    {
        $daftar = $state['multi_items'] ?? [];

        if ($daftar === []) {
            return "\n\n_Belum ada alat/lokasi tambahan. Tekan Selesai Menambah bila tidak ada._";
        }

        $teks = "\n\n*Sudah ditambahkan (" . count($daftar) . "):*";

        foreach ($daftar as $index => $item) {
            $teks .= "\n" . ($index + 1) . '. ' . ($item['label'] ?? '-')
                . (!empty($item['sub']) ? ' — ' . $item['sub'] : '');
        }

        return $teks . "\n\nTekan _Selesai Menambah_ bila sudah, atau lanjut pilih alat/lokasi lain (boleh juga ketik kodenya).";
    }

    /**
     * Deteksi lokasi (Sub Area / Area / Departemen) yang disebut EKSPLISIT di
     * teks laporan teknisi.
     *
     * Dipakai saat pencarian equipment tidak bisa dipastikan (confidence < 90%)
     * supaya teknisi bisa langsung membuat LAPORAN AREA untuk lokasi yang dia
     * sebut, tanpa harus menelusuri hierarki FuncLoc dari awal.
     *
     * Pencocokan memakai KODE atau NAMA lokasi sebagai kata utuh (bukan bagian
     * kata lain), sehingga teks seperti "cat pagar area BD02" mendeteksi Area
     * BD02, dan "bersihkan saluran dept PROD" mendeteksi Departemen PROD.
     *
     * @param  string  $text  Teks laporan mentah dari teknisi
     * @param  int     $maks  Jumlah maksimum opsi yang dikembalikan
     * @return array<int, array{level: string, id: int, kode: string, nama: string, jumlah: int}>
     */
    protected function detectLocationOptions(string $text, int $maks = 4): array
    {
        $teks = $this->normalizeTextForLocation($text);

        if ($teks === '') {
            return [];
        }

        $kandidat = [];

        // Sub Area paling spesifik, lalu Area, lalu Departemen. Pencocokan
        // dilakukan di memori (jumlah master lokasi kecil); penghitungan alat
        // hanya untuk kandidat yang benar-benar disebut.
        foreach ([
            'sub'  => SubArea::with('area.department.company')->orderBy('code')->get(),
            'area' => Area::with('department.company')->orderBy('code')->get(),
            'dept' => Department::with('company')->orderBy('code')->get(),
        ] as $level => $daftar) {
            foreach ($daftar as $lokasi) {
                $kode = (string) $lokasi->code;
                $nama = (string) $lokasi->name;

                $cocokKode = $this->textMentionsLocation($teks, $kode);
                $cocokNama = $this->textMentionsLocation($teks, $nama) && mb_strlen(trim($nama)) >= 4;

                if (! $cocokKode && ! $cocokNama) {
                    continue;
                }

                $kandidat[] = $this->buildLocationOption($level, $lokasi);
            }
        }

        // Node FuncLoc: sebagian nama lokasi HANYA ada di hierarki FuncLoc dan
        // tidak ada di tabel master lama (contoh nyata: node "Main Office" pada
        // kode EPE-HRGA-GA01). Tanpa pass ini, teks seperti "Main Office
        // accounting" tidak menghasilkan pilihan area sama sekali.
        foreach ($this->detectFuncLocNodes($teks) as $opsiNode) {
            $kandidat[] = $opsiNode;
        }

        // Urutkan: level paling spesifik dulu, lalu lokasi dengan alat terbanyak.
        usort($kandidat, static function (array $a, array $b) {
            return [$a['bobot'], -$a['jumlah'], $a['kode']] <=> [$b['bobot'], -$b['jumlah'], $b['kode']];
        });

        // Buang duplikat: master lokasi dan node FuncLoc sering mewakili tempat
        // yang sama (mis. Area GA01 vs node EPE-HRGA-GA01).
        $hasil = [];
        $terpakai = [];

        foreach ($kandidat as $opsi) {
            if ($opsi['jumlah'] <= 0) {
                continue;
            }

            $kunci = mb_strtolower($opsi['kode']);

            if (isset($terpakai[$kunci])) {
                continue;
            }

            $terpakai[$kunci] = true;
            $hasil[]          = $opsi;
        }

        return array_slice($hasil, 0, $maks);
    }

    /**
     * Deteksi node FunctionalLocation (Departemen/Area/Seksi) yang nama atau
     * kodenya disebut eksplisit di teks laporan.
     *
     * @param  string  $teksPadat  Teks hasil normalizeTextForLocation()
     * @return array<int, array<string, mixed>>  Opsi lokasi bentuk node FuncLoc
     */
    private function detectFuncLocNodes(string $teksPadat): array
    {
        $opsi = [];

        $nodes = FunctionalLocation::where('level', '!=', FunctionalLocation::LEVEL_SITE)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level', 'area_id']);

        foreach ($nodes as $node) {
            $nama = trim((string) $node->name);

            if (mb_strlen($nama) < 4) {
                continue;
            }

            if (! $this->textMentionsLocation($teksPadat, $nama) && ! $this->textMentionsLocation($teksPadat, (string) $node->code)) {
                continue;
            }

            [$label, $bobot] = match ((string) $node->level) {
                FunctionalLocation::LEVEL_DEPARTMENT => ['Departemen', 3],
                FunctionalLocation::LEVEL_AREA       => ['Area', 2],
                default                              => ['Sub Area/Seksi', 1],
            };

            $opsi[] = [
                'bobot'   => $bobot,
                'label'   => $label,
                'level'   => 'fn',
                'id'      => (int) $node->id,
                'kode'    => (string) $node->code,
                'nama'    => $nama,
                'konteks' => 'hierarki lokasi',
                'jumlah'  => Asset::where('functional_loc', 'like', $node->code . '%')->count(),
            ];
        }

        return $opsi;
    }

    /**
     * Susun satu opsi lokasi (dipakai tombol pintasan): kode, nama, konteks
     * induk (PT/Area) supaya dua lokasi berkode sama di PT berbeda tetap bisa
     * dibedakan, dan jumlah alat pada lokasi tersebut.
     *
     * @param  string  $level   'sub' | 'area' | 'dept'
     * @param  mixed   $lokasi  Model SubArea/Area/Department
     * @return array{level: string, id: int, kode: string, nama: string, konteks: string, jumlah: int}
     */
    private function buildLocationOption(string $level, $lokasi): array
    {
        $deptId = $areaId = $subId = null;
        $konteks = '';

        if ($level === 'sub') {
            $subId    = (int) $lokasi->id;
            $areaId   = $lokasi->area_id !== null ? (int) $lokasi->area_id : null;
            $deptId   = $lokasi->area?->department_id !== null ? (int) $lokasi->area->department_id : null;
            $konteks  = (string) ($lokasi->area?->code ?? '');
        } elseif ($level === 'area') {
            $areaId  = (int) $lokasi->id;
            $deptId  = $lokasi->department_id !== null ? (int) $lokasi->department_id : null;
            $konteks = trim((string) ($lokasi->department?->company?->code ?? '') . '-' . (string) ($lokasi->department?->code ?? ''), '-');
        } else {
            $deptId  = (int) $lokasi->id;
            $konteks = (string) ($lokasi->company?->code ?? '');
        }

        return [
            'bobot'   => ['sub' => 0, 'area' => 2, 'dept' => 3][$level] ?? 9,
            'label'   => ['sub' => 'Sub Area', 'area' => 'Area', 'dept' => 'Departemen'][$level] ?? 'Lokasi',
            'level'   => $level,
            'id'      => (int) $lokasi->id,
            'kode'    => (string) $lokasi->code,
            'nama'    => (string) $lokasi->name,
            'konteks' => $konteks,
            'jumlah'  => $this->hitungAlatLokasi($deptId, $areaId, $subId, $level, (int) $lokasi->id),
        ];
    }

    /**
     * Hitung jumlah alat pada satu lokasi.
     *
     * Prioritas: subtree node FuncLoc (kolom functional_loc) karena kolom id
     * lokasi lama (area_id/sub_area_id) tidak selalu terisi; bila node-nya tidak
     * ada, baru jatuh ke kolom id lama.
     *
     * @param  int|null  $deptId
     * @param  int|null  $areaId
     * @param  int|null  $subId
     * @param  string    $level
     * @param  int       $id
     * @return int
     */
    private function hitungAlatLokasi(?int $deptId, ?int $areaId, ?int $subId, string $level, int $id): int
    {
        // Cadangan 1 (paling akurat): subtree node FuncLoc hasil susunan
        // PT-DEPARTEMEN-AREA-SUBAREA.
        $node = $this->resolveFuncLocForLocation($deptId, $areaId, $subId);

        if ($node !== null) {
            $subtree = Asset::where('functional_loc', 'like', $node->code . '%')->count();

            if ($subtree > 0) {
                return $subtree;
            }
        }

        // Cadangan 2: kolom id lokasi lama (area_id/sub_area_id/department_id).
        return $this->assetsCountForLocationLevel($level, $id);
    }

    /**
     * Bangun tombol pintasan laporan area dari lokasi yang disebut di teks.
     *
     * @param  string  $text  Teks laporan mentah dari teknisi
     * @return array<int, array{text: string, callback_data: string}>  Tombol (kosong bila tidak ada lokasi disebut)
     */
    protected function buildLocationShortcutButtons(string $text): array
    {
        $tombol = [];

        foreach ($this->detectLocationOptions($text) as $opsi) {
            $judul = $opsi['label'] . ': ' . ($opsi['nama'] !== '' ? $opsi['nama'] : $opsi['kode'])
                . ' (' . $opsi['kode'] . ($opsi['konteks'] !== '' ? ' - ' . $opsi['konteks'] : '') . ')';

            $tombol[] = [
                'text'          => mb_substr($judul, 0, 60) . ' - ' . $opsi['jumlah'] . ' alat',
                'callback_data' => self::CALLBACK_LOCATION_SHORTCUT . $opsi['level'] . ':' . $opsi['id'],
            ];
        }

        return $tombol;
    }

    // =========================================================
    // ENTRY POINT — dipanggil dari handleWorkTypeCallback
    // =========================================================

    /**
     * Tampilkan keyboard PT (LEVEL_SITE) sebagai titik masuk FuncLoc picker.
     * Dipanggil setelah teknisi memilih work_type:area.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons dengan keyboard PT
     */
    protected function startFuncLocPicker(string $chatId, array $state, string $mode = 'area'): array
    {
        // Mode 'multi' hanya menambah alat/lokasi pada pekerjaan yang sudah terkunci,
        // jadi jenis pekerjaan laporan (is_area_work) TIDAK diubah.
        if ($mode !== 'multi') {
            $state['is_area_work'] = ($mode === 'area');
        }

        $state['funcloc_picker_active'] = true;
        $state['funcloc_picker_mode']   = $mode;
        $state['funcloc_path']          = [];
        unset($state['multi_layar']);
        $this->saveState($chatId, $state);

        return $this->buildFuncLocScreen($chatId, FunctionalLocation::LEVEL_SITE, null, $state);
    }

    // =========================================================
    // PENCARIAN TEKS (Sesi 5) — pelengkap navigasi tombol
    // =========================================================

    /**
     * Cari FuncLoc berdasarkan teks yang diketik teknisi selagi picker
     * lokasi aktif. Pencarian bersifat global ke seluruh level (PT sampai
     * Sub Area) memakai FunctionalLocation::scopeSearch() yang sama dengan
     * pencarian di form web, supaya teknisi tidak harus tap berjenjang dari
     * PT jika sudah hafal nama/kode Area atau Sub Area yang dituju.
     *
     * Tiap hasil dipakai sebagai tombol dengan callback_data funcloc_pick,
     * sama seperti tombol level normal — jadi tetap diproses lewat
     * handleFuncLocPick() (auto-lock jika Sub Area, atau turun ke level
     * berikutnya jika bukan). Kontrak callback lama tidak berubah.
     *
     * Catatan: karena node bisa langsung "dilompati" dari root lewat
     * pencarian, funcloc_path hasil pilihan ini hanya berisi node tersebut
     * (bukan seluruh leluhurnya) — tombol "Naik Satu Level" akan kembali
     * langsung ke root PT setelahnya, bukan berhenti di leluhur antara.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Kata kunci yang diketik teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons dengan daftar hasil sebagai keyboard
     */
    protected function handleFuncLocTextSearch(string $chatId, string $text, array $state): array
    {
        $term = trim($text);

        if ($term === '') {
            return $this->errorResponse('Ketik nama atau kode lokasi yang ingin dicari.');
        }

        // Mode multi: kode ALAT yang diketik langsung ditambahkan supaya teknisi
        // yang hanya hafal kode alat tidak perlu tahu kode lokasinya. Kode yang
        // cocok ke beberapa alat ditawarkan sebagai tombol pilihan.
        if ($this->isModeMulti($state)) {
            $langsung = $this->cobaTambahDariKetik($chatId, $term, $state);

            if ($langsung !== null) {
                return $langsung;
            }
        }

        // Dibatasi 8 hasil (lebih kecil dari batas 20 di endpoint web) agar
        // keyboard inline Telegram tetap muat tanpa perlu di-scroll panjang
        // di layar HP.
        $nodes = FunctionalLocation::active()
            ->search($term)
            ->orderBy('code')
            ->limit(8)
            ->get(['id', 'code', 'name', 'level']);

        if ($nodes->isEmpty()) {
            $keyboard = [];

            // Teknisi tidak boleh ditinggal tanpa jalan kembali ke hierarki:
            // layar sebelumnya sudah terhapus dari chat, jadi tombol "Kembali"
            // WAJIB disediakan selain tombol batal (mode multi).
            if ($this->isModeMulti($state)) {
                $keyboard[] = [
                    'text'          => 'Kembali ke Layar Pilih',
                    'callback_data' => self::CALLBACK_FUNCLOC_KEMBALI,
                ];
            }

            $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

            $petunjuk = $this->isModeMulti($state)
                ? 'Coba kata kunci lain, atau kembali ke layar pilihan sebelumnya.'
                : 'Coba kata kunci lain, atau lanjutkan lewat tombol yang sudah tampil sebelumnya.';

            return [
                'message'  => "Lokasi \"{$term}\" tidak ditemukan.\n\n{$petunjuk}",
                'keyboard' => $keyboard,
            ];
        }

        $keyboard = $nodes->map(fn (FunctionalLocation $node) => [
            'text'          => $this->funcLocSearchResultLabel($node),
            'callback_data' => "funcloc_pick:{$node->level}:{$node->id}",
        ])->all();

        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return $this->tambahKendaliMulti([
            'message'  => "Hasil pencarian \"{$term}\":\n\nPilih lokasi yang sesuai, atau ketik kata kunci lain untuk mencari ulang.",
            'keyboard' => $keyboard,
        ], $state);
    }

    /**
     * Bangun label tombol untuk satu hasil pencarian FuncLoc, dipotong jika
     * terlalu panjang agar tetap sesuai batas tombol inline keyboard Telegram.
     *
     * @param  FunctionalLocation $node
     * @return string
     */
    protected function funcLocSearchResultLabel(FunctionalLocation $node): string
    {
        $label = $node->code;

        if ($node->name) {
            $label .= ' — ' . $node->name;
        }

        $label .= ' (' . $node->level_label . ')';

        if (strlen($label) > 64) {
            $label = substr($label, 0, 61) . '...';
        }

        return $label;
    }

    // =========================================================
    // HANDLER CALLBACK — pick, confirm, back
    // =========================================================

    /**
     * Proses callback funcloc_pick:{level}:{id}.
     * Setelah PT, Departemen, atau Area dipilih, node yang dipilih disimpan
     * ke funcloc_path (untuk kebutuhan naik level nanti) lalu level
     * berikutnya ditampilkan. Setelah Sub Area (LEVEL_SECTION) dipilih,
     * langsung kunci FuncLoc dan maju ke Step 4.
     *
     * @param  string $chatId     Chat ID Telegram
     * @param  string $callbackData Data callback penuh, mis. "funcloc_pick:1:5"
     * @param  array  $state      State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocPick(string $chatId, string $callbackData, array $state): array
    {
        // Format: funcloc_pick:{level}:{id}
        $parts     = explode(':', $callbackData);
        $level     = isset($parts[1]) ? (int) $parts[1] : null;
        $funclocId = isset($parts[2]) ? (int) $parts[2] : null;

        if ($level === null || !$funclocId) {
            return $this->errorResponse('Format callback FuncLoc tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);

        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        // Section (LEVEL_SECTION) dipilih:
        // - Mode area: kunci langsung sebagai lokasi utama (Section = level terdalam).
        // - Mode equipment/multi: lanjut ke layar Section yang menampilkan daftar
        //   TechIdentNo/equipment di bawahnya (FuncLoc -> TechIdentNo berkolaborasi),
        //   plus tombol Konfirmasi bila teknisi memang ingin memakai lokasi itu
        //   sendiri (di mode multi lokasi itu DITAMBAHKAN sebagai item pekerjaan).
        if ($level === FunctionalLocation::LEVEL_SECTION
            && !in_array($state['funcloc_picker_mode'] ?? 'area', ['equipment', 'multi'], true)) {
            return $this->lockFuncLocAndAdvance($chatId, $node, $state);
        }

        // PT, Departemen, atau Area dipilih: simpan node ke funcloc_path
        // sebagai riwayat naik-turun level, lalu tampilkan level berikutnya.
        $state['funcloc_path'][] = [
            'level' => $node->level,
            'id'    => $node->id,
            'code'  => $node->code,
            'name'  => $node->name,
        ];
        $this->saveState($chatId, $state);

        $parentEntry = [
            'level' => $node->level,
            'id'    => $node->id,
            'code'  => $node->code,
            'name'  => $node->name,
        ];

        return $this->buildFuncLocScreen($chatId, $level + 1, $parentEntry, $state);
    }

    /**
     * Proses callback funcloc_confirm:{id}.
     * Teknisi menekan tombol "Konfirmasi" tanpa turun ke level berikutnya.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh, mis. "funcloc_confirm:5"
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocConfirm(string $chatId, string $callbackData, array $state): array
    {
        // Format: funcloc_confirm:{id}
        $parts     = explode(':', $callbackData);
        $funclocId = isset($parts[1]) ? (int) $parts[1] : null;

        if (!$funclocId) {
            return $this->errorResponse('ID Functional Location tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);

        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        // Mode multi: tombol Konfirmasi menambahkan lokasi sebagai item pekerjaan.
        if (($state['funcloc_picker_mode'] ?? 'area') === 'multi') {
            return $this->selesaikanPickerMulti($chatId, [
                'asset_id'   => null,
                'funcloc_id' => $node->id,
                'label'      => $node->code,
                'sub'        => $node->name,
            ], $state);
        }

        return $this->lockFuncLocAndAdvance($chatId, $node, $state);
    }

    /**
     * Tambahkan daftar item + tombol kendali mode multi ke satu layar hirarki.
     *
     * Dipakai di SEMUA layar picker saat mode 'multi' aktif supaya teknisi:
     * (a) selalu melihat alat/lokasi yang sudah ditambahkan,
     * (b) bisa menghapus item terakhir tanpa keluar dari penelusuran,
     * (c) bisa menutup penelusuran lewat tombol "Selesai Menambah".
     * Layar yang sudah punya tombol kendali (dipanggil dua kali) tidak diubah,
     * sehingga daftar item tidak pernah dobel.
     *
     * @param  array  $layar  Layar picker (message + keyboard)
     * @param  array  $state  State wizard saat ini
     * @return array  Layar dengan daftar item dan tombol kendali
     */
    protected function tambahKendaliMulti(array $layar, array $state): array
    {
        if (!$this->isModeMulti($state)) {
            return $layar;
        }

        $keyboard = $layar['keyboard'] ?? [];

        foreach ($keyboard as $baris) {
            if ((string) ($baris['callback_data'] ?? '') === self::CALLBACK_MULTI_SELESAI) {
                return $layar;
            }
        }

        $daftar = $state['multi_items'] ?? [];

        $tombol = [];

        if ($daftar !== []) {
            $tombol[] = [
                'text'          => 'Hapus Terakhir (' . count($daftar) . ')',
                'callback_data' => self::CALLBACK_MULTI_HAPUS,
            ];
        }

        $tombol[] = [
            'text'          => 'Selesai Menambah (' . count($daftar) . ')',
            'callback_data' => self::CALLBACK_MULTI_SELESAI,
        ];

        // Sisipkan sebelum baris tombol navigasi (wizard:*) supaya mudah dijangkau.
        $posisiNav = null;

        foreach ($keyboard as $indeks => $baris) {
            if (str_starts_with((string) ($baris['callback_data'] ?? ''), 'wizard:')) {
                $posisiNav = $indeks;
                break;
            }
        }

        $keyboard = $posisiNav === null
            ? array_merge($keyboard, $tombol)
            : array_merge(array_slice($keyboard, 0, $posisiNav), $tombol, array_slice($keyboard, $posisiNav));

        $layar['keyboard'] = $keyboard;
        $layar['message']  = ($layar['message'] ?? '') . $this->ringkasanItemMulti($state);

        return $layar;
    }

    /**
     * Bangun ulang layar hirarki yang sedang dibuka setelah item ditambahkan
     * atau dihapus, lengkap dengan daftar item dan tombol kendali.
     *
     * @param  string  $chatId   Chat ID Telegram
     * @param  array   $state    State wizard saat ini
     * @param  string  $catatan  Kalimat pembuka (mis. "Ditambahkan dari hirarki: X")
     * @return array   Layar hirarki terbaru
     */
    protected function renderLayarMulti(string $chatId, array $state, string $catatan = ''): array
    {
        $layar = $this->bangunLayarMulti($chatId, $state);

        if ($catatan !== '') {
            $layar['message'] = $catatan . "\n\n" . ($layar['message'] ?? '');
        }

        return $this->tambahKendaliMulti($layar, $state);
    }

    /**
     * Layar dasar yang harus ditampilkan ulang: daftar alat pada lokasi yang
     * barusan dibuka (bila teknisi sedang memilih alat dari daftar itu), atau
     * layar node yang sedang ditelusuri.
     *
     * @param  string  $chatId  Chat ID Telegram
     * @param  array   $state   State wizard saat ini
     * @return array   Layar dasar
     */
    protected function bangunLayarMulti(string $chatId, array $state): array
    {
        $tersimpan = $state['multi_layar'] ?? [];
        $jenis     = $tersimpan['jenis'] ?? 'node';

        if ($jenis === 'assets' && !empty($tersimpan['node_id'])) {
            return $this->handleFuncLocAssets($chatId, 'funcloc_assets:' . (int) $tersimpan['node_id'], $state);
        }

        if ($jenis === 'assets_type' && !empty($tersimpan['node_id']) && !empty($tersimpan['type'])) {
            return $this->handleFuncLocAssetsType(
                $chatId,
                'funcloc_assets_type:' . (int) $tersimpan['node_id'] . ':' . $tersimpan['type'],
                $state
            );
        }

        return $this->layarNodeSaatIni($chatId, $state);
    }

    /**
     * Layar node yang sedang dibuka (satu level di bawah node terakhir
     * funcloc_path). Penyelesaian otomatis dimatikan supaya node tanpa anak
     * dan tanpa alat TIDAK ditambahkan berulang kali saat layar dibangun ulang.
     *
     * @param  string  $chatId  Chat ID Telegram
     * @param  array   $state   State wizard saat ini
     * @return array   Layar node yang sedang dibuka
     */
    protected function layarNodeSaatIni(string $chatId, array $state): array
    {
        $path = $state['funcloc_path'] ?? [];

        if ($path === []) {
            return $this->buildFuncLocScreen($chatId, FunctionalLocation::LEVEL_SITE, null, $state);
        }

        $parentEntry = end($path);

        return $this->buildFuncLocScreen($chatId, (int) $parentEntry['level'] + 1, $parentEntry, $state, false);
    }

    /**
     * Selesaikan picker hirarki dalam mode 'multi': tambahkan alat/lokasi terpilih
     * sebagai item pekerjaan, lalu kembalikan prompt alat/lokasi tambahan.
     *
     * @param  string  $chatId  Chat ID Telegram
     * @param  array   $item    Entri item (asset_id/funcloc_id/label/sub)
     * @param  array   $state   State wizard saat ini
     * @return array   Respons prompt alat/lokasi tambahan
     */
    protected function selesaikanPickerMulti(string $chatId, array $item, array $state): array
    {
        $label          = $item['label'] ?? '-';
        $samaUtama      = $this->multiItemSamaUtama($state, $item);
        $sudahAda       = $this->multiItemSudahAda($state, $item) || $samaUtama;
        $masihDiHirarki = $this->isModeMulti($state) && !empty($state['funcloc_picker_active']);

        $state = $this->tambahMultiItem($state, $item);

        // Mode multi: picker DIBIARKAN aktif dan layar hirarki digambar ulang
        // beserta daftar item, sehingga teknisi bisa langsung menambah
        // alat/lokasi lain tanpa menelusuri ulang PT -> Departemen -> Area ->
        // Section, lalu menutupnya lewat tombol "Selesai Menambah".
        if ($masihDiHirarki) {
            $this->saveState($chatId, $state);

            if ($samaUtama) {
                $catatan = 'Alat/lokasi ini sudah menjadi acuan utama laporan: ' . $label
                    . ' (tidak perlu ditambahkan).';
            } elseif ($sudahAda) {
                $catatan = 'Sudah ada di daftar (tidak ditambahkan lagi): ' . $label;
            } else {
                $catatan = 'Ditambahkan dari hirarki: ' . $label
                    . ' (total ' . count($state['multi_items'] ?? []) . ')';
            }

            return $this->renderLayarMulti($chatId, $state, $catatan);
        }

        unset(
            $state['funcloc_picker_active'],
            $state['funcloc_picker_mode'],
            $state['funcloc_path'],
            $state['multi_layar'],
            $state['multi_kandidat']
        );
        $this->saveState($chatId, $state);

        $respons = $this->buildMultiEquipmentPrompt($state);
        $respons['message'] = 'Ditambahkan dari hirarki: ' . $label . "\n\n" . $respons['message'];

        return $respons;
    }

    /**
     * Tutup penelusuran hirarki mode multi tanpa mengubah daftar item, lalu
     * kembalikan prompt alat/lokasi tambahan (Step 8b). Dipakai tombol
     * "Selesai Menambah" supaya teknisi bisa memilih kapan penelusuran berhenti
     * (tidak dipaksa keluar setiap kali satu alat ditambahkan).
     *
     * @param  string  $chatId  Chat ID Telegram
     * @param  array   $state   State wizard saat ini
     * @return array   Respons prompt alat/lokasi tambahan
     */
    protected function selesaikanHirarkiMulti(string $chatId, array $state): array
    {
        unset(
            $state['funcloc_picker_active'],
            $state['funcloc_picker_mode'],
            $state['funcloc_path'],
            $state['multi_layar'],
            $state['multi_kandidat']
        );
        $this->saveState($chatId, $state);

        $respons = $this->buildMultiEquipmentPrompt($state);
        $respons['message'] = "Selesai menambah alat/lokasi.\n\n" . $respons['message'];

        return $respons;
    }

    /**
     * Hapus item alat/lokasi tambahan TERAKHIR dari layar hirarki, lalu gambar
     * ulang layar yang sedang dibuka supaya teknisi tidak perlu keluar dulu.
     *
     * @param  string  $chatId  Chat ID Telegram
     * @param  array   $state   State wizard saat ini
     * @return array   Layar hirarki terbaru, atau pesan galat bila daftar kosong
     */
    protected function hapusItemMultiTerakhir(string $chatId, array $state): array
    {
        $daftar = $state['multi_items'] ?? [];

        if ($daftar === []) {
            return $this->errorResponse('Belum ada alat/lokasi tambahan yang bisa dihapus.');
        }

        $item = array_pop($daftar);
        $state['multi_items'] = array_values($daftar);
        $this->saveState($chatId, $state);

        return $this->renderLayarMulti($chatId, $state, 'Dihapus: ' . ($item['label'] ?? '-'));
    }

    /**
     * Kembali ke layar hirarki terakhir. Dipakai tombol "Kembali" pada layar
     * hasil pencarian supaya teknisi tidak kehilangan tombol hierarki saat
     * kata kunci yang diketik tidak cocok dengan lokasi mana pun.
     *
     * @param  string  $chatId  Chat ID Telegram
     * @param  array   $state   State wizard saat ini
     * @return array   Layar hirarki terbaru, atau pesan galat bila picker tidak aktif
     */
    protected function kembaliKeLayarHirarki(string $chatId, array $state): array
    {
        if (!$this->isModeMulti($state) || empty($state['funcloc_picker_active'])) {
            return $this->errorResponse(
                'Layar hirarki sudah tidak aktif. Tekan Pilih Hirarki untuk memulai penelusuran lagi.'
            );
        }

        return $this->renderLayarMulti($chatId, $state);
    }

    /**
     * Coba perlakukan teks yang diketik teknisi di dalam hirarki mode multi
     * sebagai kode ALAT tambahan, supaya teknisi yang hanya hafal kode alat
     * (tanpa kode lokasi) tetap bisa menambah tanpa keluar dari hierarki.
     *
     * Kode alat persis langsung ditambahkan; kode yang cocok ke beberapa alat
     * ditawarkan sebagai tombol; teks yang bukan milik alat mana pun
     * mengembalikan null agar lanjut dicari sebagai kode/nama lokasi.
     *
     * @param  string  $chatId  Chat ID Telegram
     * @param  string  $term    Teks yang diketik teknisi
     * @param  array   $state   State wizard saat ini
     * @return array|null  Respons bila teks dikenali sebagai alat, null bila bukan
     */
    protected function cobaTambahDariKetik(string $chatId, string $term, array $state): ?array
    {
        $hasil  = $this->resolveMultiItem($term, $state);
        $status = $hasil['status'] ?? 'tidak_ada';

        if ($status === 'ok' && !empty($hasil['item']['asset_id'])) {
            return $this->selesaikanPickerMulti($chatId, $hasil['item'], $state);
        }

        if ($status !== 'kandidat') {
            return null;
        }

        $keyboard = array_map(
            fn (array $calon) => [
                'text'          => mb_substr(
                    (string) ($calon['tech_ident_no'] ?? '-') . ' — ' . (string) ($calon['description'] ?? ''),
                    0,
                    64
                ),
                'callback_data' => 'funcloc_asset_pick:' . (int) ($calon['id'] ?? 0),
            ],
            $hasil['kandidat'] ?? []
        );

        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return $this->tambahKendaliMulti([
            'message'  => "Kode *{$term}* cocok dengan beberapa alat. Pilih alat yang dimaksud:",
            'keyboard' => $keyboard,
        ], $state);
    }

    /**
     * Proses callback funcloc_back.
     * Men-pop entri terakhir dari funcloc_path lalu membangun ulang keyboard
     * level tersebut memakai parent hasil pop (satu level di atas node yang
     * baru saja "dilupakan"). Jika funcloc_path sudah kosong, teknisi memang
     * sedang berada di level PT (root) — layar root ditampilkan ulang.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocBack(string $chatId, array $state): array
    {
        $path = $state['funcloc_path'] ?? [];

        if (empty($path)) {
            return $this->buildFuncLocScreen($chatId, FunctionalLocation::LEVEL_SITE, null, $state);
        }

        array_pop($path);
        $state['funcloc_path'] = $path;
        $this->saveState($chatId, $state);

        $parentEntry = empty($path) ? null : end($path);
        $level       = $parentEntry ? $parentEntry['level'] + 1 : FunctionalLocation::LEVEL_SITE;

        return $this->buildFuncLocScreen($chatId, $level, $parentEntry, $state);
    }

    // =========================================================
    // HELPER INTERNAL
    // =========================================================

    /**
     * Bangun satu layar FuncLoc picker untuk level tertentu, dipakai bersama
     * oleh startFuncLocPicker(), handleFuncLocPick() (turun level), dan
     * handleFuncLocBack() (naik level) agar tidak ada logic keyboard yang
     * terduplikasi antara arah turun dan arah naik.
     *
     * Jika level ini tidak punya anak sama sekali:
     *   - Jika ada $parentEntry (bukan root), kunci parent tersebut langsung
     *     sebagai FuncLoc final (perilaku sama seperti sebelum Sesi 5).
     *   - Jika ini root (PT) dan kosong, kembalikan error konfigurasi.
     *
     * @param  string     $chatId     Chat ID Telegram
     * @param  int        $level      Level FuncLoc yang akan ditampilkan (konstanta LEVEL_*)
     * @param  array|null $parentEntry Node parent terpilih (['level','id','code','name']), null jika root
     * @param  array      $state      State wizard saat ini
     * @return array      Respons dengan message dan keyboard
     */
    protected function buildFuncLocScreen(
        string $chatId,
        int $level,
        ?array $parentEntry,
        array $state,
        bool $selesaikanOtomatis = true
    ): array {
        $parentId = $parentEntry['id'] ?? null;
        $mode     = $state['funcloc_picker_mode'] ?? 'area';
        $options  = $this->buildFuncLocLevelKeyboard($level, $parentId);

        // Mode multi: catat bahwa layar yang sedang dibuka adalah layar node,
        // supaya setelah satu alat/lokasi ditambahkan layar ini bisa digambar
        // ulang apa adanya (hierarki tidak hilang, tidak perlu mulai dari PT).
        if ($mode === 'multi') {
            $state['multi_layar'] = ['jenis' => 'node'];
            $this->saveState($chatId, $state);
        }

        // Daftar equipment di bawah node yang sedang dilihat dipakai oleh mode
        // 'equipment' DAN mode 'multi' (menambah alat/lokasi pada pekerjaan),
        // supaya teknisi bisa langsung memilih alat, bukan hanya lokasi.
        $modeDenganEquipment = in_array($mode, ['equipment', 'multi'], true);

        // Equipment langsung di bawah node yang sedang dilihat.
        $equipmentCount = 0;
        if ($modeDenganEquipment && $parentId) {
            $equipmentCount = $this->countAssetsUnderNode($parentId, $this->isItPool($state));
        }

        if (empty($options) && $equipmentCount === 0) {
            if ($parentEntry) {
                $node = FunctionalLocation::active()->find($parentEntry['id']);
                if ($node) {
                    Log::info("WizardFuncLocPicker: Tidak ada anak L{$level} untuk node {$node->code}, langsung selesai.", [
                        'chat_id'    => $chatId,
                        'funcloc_id' => $node->id,
                        'mode'       => $mode,
                    ]);

                    // Saat layar dibangun ULANG (setelah item ditambah/dihapus),
                    // penyelesaian otomatis dimatikan: node tanpa anak dan tanpa
                    // alat cukup ditampilkan sebagai layar akhir supaya tidak
                    // ditambahkan berulang kali tanpa perintah teknisi.
                    if ($mode === 'multi' && !$selesaikanOtomatis) {
                        $labelNode = $this->funcLocLevelLabel((int) $node->level);

                        return $this->tambahKendaliMulti([
                            'message'  => "*{$labelNode}:* {$node->code} — {$node->name}\n\n"
                                . "Lokasi ini tidak punya anak maupun alat terdaftar.\n"
                                . "Tekan Naik Satu Level untuk memilih lokasi lain, atau "
                                . "Selesai Menambah bila tambahan sudah cukup.",
                            'keyboard' => [
                                ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'],
                                ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'],
                            ],
                        ], $state);
                    }

                    // Mode multi: node ini DITAMBAHKAN sebagai alat/lokasi pekerjaan
                    // (bukan dikunci sebagai lokasi utama laporan).
                    if ($mode === 'multi') {
                        return $this->selesaikanPickerMulti($chatId, [
                            'asset_id'   => null,
                            'funcloc_id' => $node->id,
                            'label'      => $node->code,
                            'sub'        => $node->name,
                        ], $state);
                    }

                    return $this->lockFuncLocAndAdvance($chatId, $node, $state);
                }
            }

            Log::warning("WizardFuncLocPicker: Tidak ada FuncLoc aktif di level {$level} untuk chat {$chatId}");

            return $this->errorResponse(
                "Tidak ada data lokasi yang tersedia di level ini.\n" .
                "Hubungi admin untuk menambahkan data Functional Location."
            );
        }

        $keyboard = [];

        // Mode equipment: tawarkan pemilihan TechIdentNo di lokasi ini bila ada.
        if ($equipmentCount > 0) {
            $keyboard[] = [
                'text'          => "Pilih Equipment di sini ({$equipmentCount})",
                'callback_data' => "funcloc_assets:{$parentId}",
            ];
        }

        // Tombol konfirmasi node yang sedang dilihat (skip turun lebih dalam)
        if ($parentEntry) {
            $keyboard[] = [
                'text'          => "Konfirmasi: {$parentEntry['code']}",
                'callback_data' => "funcloc_confirm:{$parentEntry['id']}",
            ];
        }

        foreach ($options as $option) {
            $keyboard[] = $option;
        }

        // Naik satu level — hanya relevan jika sudah pernah turun (path tidak kosong)
        if (!empty($state['funcloc_path'])) {
            $keyboard[] = ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'];
        }

        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        if ($parentEntry) {
            $currentLabel = $this->funcLocLevelLabel($parentEntry['level']);
            if ($modeDenganEquipment && $equipmentCount > 0) {
                $message = "*{$currentLabel} dipilih:* {$parentEntry['code']} — {$parentEntry['name']}\n\n" .
                           "Pilih equipment di lokasi ini, atau konfirmasi lokasi saja:";
            } else {
                $nextLabel = $this->funcLocLevelLabel($level);
                $message   = "*{$currentLabel} dipilih:* {$parentEntry['code']} — {$parentEntry['name']}\n\n" .
                             "Pilih {$nextLabel} (opsional) atau konfirmasi sekarang:";
            }
        } else {
            $rootLabel = $this->funcLocLevelLabel($level);

            $message = $mode === 'multi'
                ? "*Tambah alat/lokasi* — Pilih {$rootLabel}\n\n"
                    . "Pilih {$rootLabel} tempat alat/lokasi berada, ketik kodenya, "
                    . "atau tekan Selesai Menambah bila tambahan sudah cukup:"
                : "*Step 2* — Pilih {$rootLabel}\n\nPilih {$rootLabel} tempat pekerjaan dilakukan:";
        }

        return $this->tambahKendaliMulti([
            'message'  => $message,
            'keyboard' => $keyboard,
        ], $state);
    }

    /**
     * Kunci FuncLoc yang dipilih ke state dan maju ke Step 4 (Waktu Pengerjaan).
     * Membersihkan state sementara picker (termasuk riwayat funcloc_path)
     * sebelum maju.
     *
     * @param  string              $chatId Chat ID Telegram
     * @param  FunctionalLocation  $node   Node FuncLoc yang dikunci
     * @param  array               $state  State wizard saat ini
     * @return array               Respons dari advanceToWorkDuration()
     */
    protected function lockFuncLocAndAdvance(string $chatId, FunctionalLocation $node, array $state): array
    {
        // Bersihkan state sementara picker
        unset(
            $state['funcloc_picker_active'],
            $state['funcloc_picker_mode'],
            $state['funcloc_path']
        );

        // Kunci FuncLoc sebagai pekerjaan area (tanpa equipment spesifik).
        // is_area_work dipaksa true karena konfirmasi lokasi = laporan area,
        // apapun mode picker-nya (area maupun equipment).
        $state = $this->lengkapiStateLokasi($state, $node);

        $state['is_area_work']    = true;
        $state['equipment_id']    = null;
        $state['equipment_ident'] = null;

        Log::info("WizardFuncLocPicker: FuncLoc dikunci untuk chat {$chatId}", [
            'funcloc_id'   => $node->id,
            'funcloc_code' => $node->code,
            'level'        => $node->level,
        ]);

        return $this->advanceToMultiEquipment($chatId, $state);
    }

    /**
     * Bangun inline keyboard untuk satu level FuncLoc tertentu.
     * Hanya node aktif yang dimuat. Diurutkan berdasarkan code secara ascending.
     *
     * @param  int      $level    Level FuncLoc (konstanta LEVEL_* dari FunctionalLocation)
     * @param  int|null $parentId ID parent — null untuk root level PT (LEVEL_SITE)
     * @return array    Array tombol inline keyboard Telegram
     */
    protected function buildFuncLocLevelKeyboard(int $level, ?int $parentId): array
    {
        $query = FunctionalLocation::active()
            ->ofLevel($level)
            ->orderBy('code');

        if ($parentId !== null) {
            $query->underParent($parentId);
        }

        $nodes = $query->get(['id', 'code', 'name', 'level']);

        $keyboard = [];
        foreach ($nodes as $node) {
            // Label tombol: code — name, dipotong jika terlalu panjang
            $label = $node->code;
            if ($node->name) {
                $label .= ' — ' . $node->name;
            }
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }

            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "funcloc_pick:{$node->level}:{$node->id}",
            ];
        }

        return $keyboard;
    }

    /**
     * Kembalikan label level FuncLoc dalam Bahasa Indonesia.
     *
     * @param  int    $level Nilai level (0–3)
     * @return string Label level
     */
    protected function funcLocLevelLabel(int $level): string
    {
        return match ($level) {
            FunctionalLocation::LEVEL_SITE       => 'PT',
            FunctionalLocation::LEVEL_DEPARTMENT => 'Departemen',
            FunctionalLocation::LEVEL_AREA       => 'Area',
            FunctionalLocation::LEVEL_SECTION    => 'Section',
            default                              => 'Lokasi',
        };
    }

    // =========================================================
    // MODE EQUIPMENT — PEMILIHAN TECHIDENTNO DI BAWAH FUNCLOC
    // =========================================================

    /**
     * Hitung jumlah asset yang terdaftar langsung di bawah node FuncLoc.
     * Alat tanpa Equipment Type tetap dihitung (lihat assetsUnderNode()).
     *
     * @param  int   $funclocId ID FunctionalLocation
     * @param  bool  $itOnly    true = hanya perangkat IT (data_source 'it_sync')
     * @return int
     */
    protected function countAssetsUnderNode(int $funclocId, bool $itOnly = false): int
    {
        return Asset::where('funcloc_id', $funclocId)
            ->when($itOnly, fn ($query) => $query->where('data_source', 'it_sync'))
            ->whereNotNull('tech_ident_no')
            ->count();
    }

    /**
     * Ambil koleksi asset di bawah node FuncLoc, diurutkan berdasarkan TechIdentNo.
     * Alat tanpa Equipment Type IKUT disertakan (dikelompokkan pada label
     * TANPA TIPE lewat equipmentTypeOf()), karena seluruh equipment harus bisa
     * dipilih teknisi — sebelumnya alat tanpa type tersembunyi dari hierarki.
     *
     * @param  int   $funclocId ID FunctionalLocation
     * @param  bool  $itOnly    true = hanya perangkat IT (data_source 'it_sync')
     * @return Collection
     */
    protected function assetsUnderNode(int $funclocId, bool $itOnly = false): Collection
    {
        return Asset::where('funcloc_id', $funclocId)
            ->when($itOnly, fn ($query) => $query->where('data_source', 'it_sync'))
            ->whereNotNull('tech_ident_no')
            ->orderBy('tech_ident_no')
            ->get(['id', 'tech_ident_no', 'description', 'equipment_type']);
    }

    /**
     * Proses callback funcloc_assets:{funcloc_id} — tampilkan daftar equipment
     * di bawah node. Jika jumlahnya melebihi EQUIPMENT_GROUP_THRESHOLD,
     * kelompokkan dulu per tipe alat.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocAssets(string $chatId, string $callbackData, array $state): array
    {
        $funclocId = (int) (explode(':', $callbackData)[1] ?? 0);

        if (!$funclocId) {
            return $this->errorResponse('ID lokasi tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);
        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        $assets = $this->assetsUnderNode($funclocId, $this->isItPool($state));

        // Mode multi: ingat bahwa layar yang sedang dibuka adalah daftar alat
        // lokasi ini, supaya setelah satu alat ditambahkan layar daftar ini
        // bisa dibuka lagi tanpa menelusuri hierarki dari PT.
        if ($this->isModeMulti($state)) {
            $state['multi_layar'] = ['jenis' => 'assets', 'node_id' => $funclocId];
            $this->saveState($chatId, $state);
        }

        if ($assets->isEmpty()) {
            return $this->tambahKendaliMulti([
                'message'  => "Tidak ada equipment terdaftar di {$node->code} — {$node->name}.",
                'keyboard' => [
                    ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'],
                    ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'],
                ],
            ], $state);
        }

        if ($assets->count() > self::EQUIPMENT_GROUP_THRESHOLD) {
            return $this->tambahKendaliMulti($this->buildAssetTypeKeyboard($node, $assets, $state), $state);
        }

        return $this->tambahKendaliMulti($this->buildAssetListKeyboard($node, $assets, null, $state), $state);
    }

    /**
     * Proses callback funcloc_assets_type:{funcloc_id}:{type} — tampilkan
     * equipment dengan tipe tertentu di bawah node.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocAssetsType(string $chatId, string $callbackData, array $state): array
    {
        $parts     = explode(':', $callbackData);
        $funclocId = (int) ($parts[1] ?? 0);
        $type      = $parts[2] ?? '';

        if (!$funclocId || $type === '') {
            return $this->errorResponse('Data tipe equipment tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);
        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        $assets = $this->assetsUnderNode($funclocId, $this->isItPool($state))
            ->filter(fn (Asset $asset) => $this->equipmentTypeOf($asset) === $type)
            ->values();

        if ($assets->isEmpty()) {
            return $this->errorResponse('Tidak ada equipment dengan tipe tersebut di lokasi ini.');
        }

        // Mode multi: ingat filter tipe yang sedang dibuka supaya layar ini bisa
        // dibuka lagi setelah satu alat dari tipe tersebut ditambahkan.
        if ($this->isModeMulti($state)) {
            $state['multi_layar'] = ['jenis' => 'assets_type', 'node_id' => $funclocId, 'type' => $type];
            $this->saveState($chatId, $state);
        }

        return $this->tambahKendaliMulti($this->buildAssetListKeyboard($node, $assets, $type, $state), $state);
    }

    /**
     * Proses callback funcloc_asset_pick:{asset_id} — kunci equipment yang
     * dipilih dan lanjut ke Step 4.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocAssetPick(string $chatId, string $callbackData, array $state): array
    {
        $assetId = (int) (explode(':', $callbackData)[1] ?? 0);

        $asset = $assetId ? Asset::find($assetId) : null;
        if (!$asset) {
            return $this->errorResponse('Equipment tidak ditemukan. Silakan pilih ulang.');
        }

        // Mode multi: alat yang dipilih ditambahkan sebagai item pekerjaan,
        // bukan dikunci sebagai equipment utama laporan.
        if (($state['funcloc_picker_mode'] ?? 'area') === 'multi') {
            return $this->selesaikanPickerMulti($chatId, $this->itemDariAset($asset), $state);
        }

        // Sinkronkan funcloc dari asset agar laporan menyimpan lokasi numeriknya.
        $state['funcloc_id']   = $asset->funcloc_id ?? null;
        $state['funcloc_code'] = $asset->functional_loc ?? null;

        // Bersihkan state sementara picker sebelum lanjut.
        unset(
            $state['funcloc_picker_active'],
            $state['funcloc_picker_mode'],
            $state['funcloc_path']
        );

        return $this->lockEquipmentAndAdvance($chatId, $asset, $state);
    }

    /**
     * Bangun keyboard pilihan tipe alat saat equipment di satu lokasi terlalu
     * banyak untuk ditampilkan langsung.
     *
     * @param  FunctionalLocation $node   Node lokasi terpilih
     * @param  Collection         $assets Koleksi asset di bawah node
     * @param  array              $state  State wizard saat ini
     * @return array Respons dengan keyboard tipe alat
     */
    protected function buildAssetTypeKeyboard(FunctionalLocation $node, Collection $assets, array $state): array
    {
        $grouped = [];

        foreach ($assets as $asset) {
            $type             = $this->equipmentTypeOf($asset);
            $grouped[$type][] = $asset;
        }

        ksort($grouped);

        $keyboard = [];
        foreach ($grouped as $type => $list) {
            // equipment_type sudah berupa label deskriptif (mis. PUMP, AC),
            // jadi tampilkan langsung tanpa duplikasi "(type)".
            $label = $type . " — " . count($list) . ' alat';
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "funcloc_assets_type:{$node->id}:{$type}",
            ];
        }

        $keyboard[] = ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'];
        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "Equipment di {$node->code} — {$node->name} cukup banyak. Pilih tipe alat dulu:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Bangun keyboard daftar equipment (TechIdentNo) untuk dipilih teknisi.
     *
     * @param  FunctionalLocation $node   Node lokasi terpilih
     * @param  Collection         $assets Koleksi asset yang akan ditampilkan
     * @param  string|null        $type   Tipe alat (jika ini hasil filter tipe)
     * @param  array              $state  State wizard saat ini
     * @return array Respons dengan keyboard daftar equipment
     */
    protected function buildAssetListKeyboard(FunctionalLocation $node, Collection $assets, ?string $type, array $state): array
    {
        $keyboard = [];
        foreach ($assets as $asset) {
            $label = $asset->tech_ident_no;
            if ($asset->description) {
                $label .= ' — ' . $asset->description;
            }
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "funcloc_asset_pick:{$asset->id}",
            ];
        }

        if ($type !== null) {
            $keyboard[] = ['text' => 'Kembali', 'callback_data' => "funcloc_assets:{$node->id}"];
        } else {
            $keyboard[] = ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'];
        }
        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "Pilih equipment di {$node->code} — {$node->name}:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Label tipe alat untuk pengelompokan di hierarki pemilihan equipment.
     *
     * Alat yang belum diisi Equipment Type tetap bisa dipilih dan dikelompokkan
     * pada label cadangan TANPA TIPE (bukan disembunyikan), sesuai keputusan
     * bahwa seluruh equipment harus bisa dipilih teknisi.
     *
     * @param  Asset  $asset
     * @return string  Label tipe, atau TANPA TIPE bila kolomnya kosong
     */
    protected function equipmentTypeOf(Asset $asset): string
    {
        $tipe = trim((string) ($asset->equipment_type ?? ''));

        return $tipe !== '' ? $tipe : self::TANPA_TIPE_LABEL;
    }
}
