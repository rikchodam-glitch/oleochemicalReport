<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Alur Management Tools di bot Telegram.
 *
 * - /pinjam  : keeper mendapat tautan form peminjaman (bisa dipakai berulang).
 * - /pakai   : teknisi mencari alat; hasil menampilkan foto/status dan tautan
 *              dashboard peminjaman sekali pakai.
 * - /selesai : keeper mendapat tautan form pengembalian; teknisi mendapat
 *              daftar pinjamannya dengan tombol kembalikan satu/semua.
 *
 * Tombol inline hanya dilayani jalur polling. Jalur webhook memakai tautan
 * form biasa sebagai fallback.
 */
class ToolTelegramService
{
    public function __construct(
        private TelegramService $telegram,
        private ToolTokenService $token,
        private ToolLendingService $peminjaman
    ) {
    }

    /**
     * Proses perintah /pinjam (khusus keeper).
     */
    public function pinjam(int|string $chatId, Technician $teknisi): void
    {
        if (! $teknisi->toolKeeperAktif()) {
            $this->telegram->sendMessage(
                $chatId,
                'Perintah /pinjam hanya untuk tool keeper yang ditunjuk administrator.'
            );

            return;
        }

        $tautan = $this->token->buatToken($teknisi, ToolToken::PURPOSE_KEEPER_PINJAM);

        $this->telegram->sendMessage(
            $chatId,
            "<b>Form Peminjaman Alat</b>\n"
            . "Anda terdaftar sebagai tool keeper. Buka tautan berikut untuk meminjamkan alat.\n"
            . "Tautan berlaku " . ToolTokenService::MASA_BERLAKU_MENIT . " menit dan bisa dipakai beberapa peminjaman.\n\n"
            . $tautan['url'],
            ['disable_web_page_preview' => true]
        );
    }

    /**
     * Proses perintah /pakai.
     *
     * @param  bool $dukungCallback False pada jalur webhook (tanpa tombol inline)
     */
    public function pakai(int|string $chatId, Technician $teknisi, string $kataKunci, bool $dukungCallback = true): void
    {
        $kataKunci = trim($kataKunci);

        if ($kataKunci === '') {
            if ($dukungCallback) {
                $this->kirimHierarkiLokasi($chatId);

                return;
            }

            $this->kirimTautanDashboard($chatId, $teknisi);

            return;
        }

        $grup = $this->cariGrupAlat($kataKunci);

        if ($grup->isEmpty()) {
            if ($dukungCallback) {
                $this->telegram->sendMessage(
                    $chatId,
                    'Alat "' . e($kataKunci) . '" tidak ditemukan. Pilih lewat hierarki Lokasi > Rak:',
                    ['reply_markup' => $this->keyboardLokasi()]
                );

                return;
            }

            $this->kirimTautanDashboard($chatId, $teknisi);

            return;
        }

        $tautan = $this->token->buatToken($teknisi, ToolToken::PURPOSE_TEKNISI_PINJAM);

        foreach ($grup->take(3) as $unit) {
            $this->kirimRingkasanGrup($chatId, $unit, $tautan['url']);
        }

        if ($grup->count() > 3) {
            $this->telegram->sendMessage(
                $chatId,
                'Masih ada ' . ($grup->count() - 3) . ' hasil lain. Persempit pencarian atau buka dashboard peminjaman: ' . $tautan['url'],
                ['disable_web_page_preview' => true]
            );
        }
    }

    /**
     * Proses perintah /selesai.
     *
     * @param  bool $dukungCallback False pada jalur webhook (tanpa tombol inline)
     */
    public function selesai(int|string $chatId, Technician $teknisi, bool $dukungCallback = true): void
    {
        if ($teknisi->toolKeeperAktif()) {
            $tautan = $this->token->buatToken($teknisi, ToolToken::PURPOSE_KEEPER_KEMBALI);

            $this->telegram->sendMessage(
                $chatId,
                "<b>Barang Kembali</b>\n"
                . "Buka tautan berikut untuk memeriksa pengajuan pengembalian dan menekan Terima.\n"
                . "Tautan berlaku " . ToolTokenService::MASA_BERLAKU_MENIT . " menit.\n\n"
                . $tautan['url'],
                ['disable_web_page_preview' => true]
            );

            return;
        }

        $daftar = $this->pinjamanAktifTeknisi($teknisi);

        if ($daftar->isEmpty()) {
            $menunggu = ToolLoan::query()
                ->where('technician_id', $teknisi->id)
                ->menungguKembali()
                ->count();

            $this->telegram->sendMessage(
                $chatId,
                $menunggu > 0
                    ? 'Semua pengembalian Anda (' . $menunggu . ' alat) sedang menunggu diterima tool keeper.'
                    : 'Tidak ada alat yang sedang Anda pinjam.'
            );

            return;
        }

        if (! $dukungCallback || $daftar->count() > 10) {
            $tautan = $this->token->buatToken($teknisi, ToolToken::PURPOSE_TEKNISI_KEMBALI);

            $this->telegram->sendMessage(
                $chatId,
                'Buka tautan berikut untuk mengembalikan alat (berlaku '
                . ToolTokenService::MASA_BERLAKU_MENIT . " menit):\n\n" . $tautan['url'],
                ['disable_web_page_preview' => true]
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            $this->teksDaftarPinjaman($daftar, 'Pilih alat yang akan dikembalikan:'),
            ['reply_markup' => $this->keyboardDaftarPinjaman($daftar)]
        );
    }

    /**
     * Tangani callback inline berprefix alat:.
     */
    public function handleCallback(array $callback, int|string $chatId, int $messageId): void
    {
        $data    = (string) ($callback['data'] ?? '');
        $fromId  = (string) ($callback['from']['id'] ?? '');
        $teknisi = Technician::query()
            ->where('telegram_id', $fromId)
            ->where('status', 'active')
            ->first();

        if ($teknisi === null) {
            $this->telegram->editMessageText($chatId, $messageId, 'Akun Telegram ini belum terdaftar sebagai teknisi aktif.');

            return;
        }

        try {
            if ($data === 'alat:batal') {
                $this->telegram->editMessageText($chatId, $messageId, 'Dibatalkan.');

                return;
            }

            if ($data === 'alat:lok') {
                $this->kirimHierarkiLokasi($chatId, $messageId);

                return;
            }

            if (preg_match('/^alat:l:(\d+)$/', $data, $cocok)) {
                $this->kirimHierarkiRak($chatId, $messageId, (int) $cocok[1]);

                return;
            }

            if (preg_match('/^alat:r:(\d+)(?::(\d+))?$/', $data, $cocok)) {
                $this->kirimHierarkiAlat($chatId, $messageId, (int) $cocok[1], (int) ($cocok[2] ?? 1));

                return;
            }

            if (preg_match('/^alat:g:(\d+)$/', $data, $cocok)) {
                $this->pilihGrupAlat($chatId, $messageId, (int) $cocok[1], $teknisi);

                return;
            }

            if (preg_match('/^alat:ret:(\d+)$/', $data, $cocok)) {
                $this->kembalikanSatu($chatId, $messageId, $teknisi, (int) $cocok[1]);

                return;
            }

            if (preg_match('/^alat:terima:(\d+)$/', $data, $cocok)) {
                $this->terimaKembali($chatId, $messageId, $teknisi, (int) $cocok[1]);

                return;
            }

            if ($data === 'alat:retall') {
                $daftar = $this->pinjamanAktifTeknisi($teknisi);

                $this->telegram->editMessageText(
                    $chatId,
                    $messageId,
                    'Kembalikan SEMUA (' . $daftar->count() . ' alat) yang sedang Anda pinjam?',
                    ['reply_markup' => ['inline_keyboard' => [
                        [['text' => 'Ya, kembalikan semua', 'callback_data' => 'alat:retall:ya']],
                        [['text' => 'Batal', 'callback_data' => 'alat:batal']],
                    ]]]
                );

                return;
            }

            if ($data === 'alat:retall:ya') {
                $teknisi->loadMissing('department');
                $jumlah = $this->peminjaman->mintaKembaliSemua($teknisi, ToolLoan::HANDLED_SELF);

                $this->telegram->editMessageText(
                    $chatId,
                    $messageId,
                    $jumlah > 0
                        ? 'Pengajuan pengembalian ' . $jumlah . ' alat dikirim ke tool keeper untuk diperiksa.'
                        : 'Tidak ada pinjaman aktif yang bisa diajukan kembali.'
                );

                return;
            }

            $this->telegram->editMessageText($chatId, $messageId, 'Tombol ini sudah tidak dikenali. Silakan mulai ulang dengan /pakai atau /selesai.');
        } catch (\Throwable $galat) {
            Log::error('Tool Telegram callback error: ' . $galat->getMessage(), [
                'data' => $data,
            ]);

            $this->telegram->sendMessage($chatId, 'Terjadi kesalahan saat memproses pilihan. Silakan coba lagi.');
        }
    }

    /**
     * Cari alat lalu kelompokkan per nama + rak.
     *
     * @return Collection<int,Collection<int,Tool>>
     */
    private function cariGrupAlat(string $kataKunci): Collection
    {
        $hasil = Tool::query()
            ->with([
                'rack.location',
                'pinjamanAktif.technician',
                'pinjamanAktif.borrowerDepartment',
                'pinjamanTerakhir.technician',
                'pinjamanTerakhir.borrowerDepartment',
            ])
            ->cari($kataKunci)
            ->orderBy('name')
            ->orderBy('code')
            ->limit(100)
            ->get();

        return $hasil
            ->groupBy(fn (Tool $alat): string => mb_strtolower($alat->name) . '|' . $alat->tool_rack_id)
            ->values();
    }

    /**
     * Ambil satu grup unit berdasarkan id alat.
     *
     * @return Collection<int,Tool>
     */
    private function grupDariAlat(Tool $alat): Collection
    {
        return Tool::query()
            ->with([
                'rack.location',
                'pinjamanAktif.technician',
                'pinjamanAktif.borrowerDepartment',
                'pinjamanTerakhir.technician',
                'pinjamanTerakhir.borrowerDepartment',
            ])
            ->where('name', $alat->name)
            ->where('tool_rack_id', $alat->tool_rack_id)
            ->orderBy('code')
            ->get();
    }

    /**
     * Kirim ringkasan satu grup alat beserta tombol tautan dashboard.
     *
     * @param Collection<int,Tool> $unit
     */
    private function kirimRingkasanGrup(int|string $chatId, Collection $unit, string $url): void
    {
        $pertama = $unit->first();

        if ($pertama === null) {
            return;
        }

        $caption  = Str::limit($this->teksRingkasanGrup($unit), 1000, "\n...");
        $keyboard = ['inline_keyboard' => [
            [['text' => 'Buka Dashboard Peminjaman', 'url' => $url]],
        ]];

        $berfoto = $unit->first(fn (Tool $alat): bool => $alat->foto_url !== null);

        if ($berfoto !== null) {
            $terkirim = $this->telegram->sendPhoto(
                $chatId,
                url($berfoto->foto_url),
                $caption,
                ['reply_markup' => $keyboard]
            );

            if ($terkirim) {
                return;
            }
        }

        $this->telegram->sendMessage($chatId, $caption, ['reply_markup' => $keyboard]);
    }

    /**
     * Susun caption ringkasan grup alat.
     *
     * @param Collection<int,Tool> $unit
     */
    private function teksRingkasanGrup(Collection $unit): string
    {
        $pertama   = $unit->first();
        $tersedia  = $unit->filter(fn (Tool $alat): bool => $alat->status === Tool::STATUS_TERSEDIA)->count();
        $total     = $unit->count();
        $rak       = $pertama?->rack;
        $lokasi    = $rak?->location;

        $baris   = [];
        $baris[] = '<b>' . e($pertama?->name ?? 'Alat') . '</b> (' . $tersedia . '/' . $total . ' tersedia)';
        $baris[] = 'Lokasi: ' . e($lokasi?->name ?? '-') . ' / Rak: ' . e($rak?->name ?? '-');
        $baris[] = '';

        foreach ($unit->take(8) as $alat) {
            $baris[] = '<b>' . e($alat->code) . '</b> - ' . e($alat->statusLabel());

            if ($alat->pinjamanAktif !== null) {
                $baris[] = 'Dipakai oleh: ' . e($alat->pinjamanAktif->peminjamLabel());
            } elseif ($alat->pinjamanTerakhir !== null) {
                $baris[] = 'Peminjam terakhir: ' . e($alat->pinjamanTerakhir->peminjamLabel());
            }
        }

        if ($total > 8) {
            $baris[] = '... dan ' . ($total - 8) . ' unit lain.';
        }

        return implode("\n", $baris);
    }

    /**
     * Kirim tautan dashboard peminjaman (fallback tanpa tombol).
     */
    private function kirimTautanDashboard(int|string $chatId, Technician $teknisi): void
    {
        $tautan = $this->token->buatToken($teknisi, ToolToken::PURPOSE_TEKNISI_PINJAM);

        $this->telegram->sendMessage(
            $chatId,
            'Buka dashboard peminjaman alat (berlaku ' . ToolTokenService::MASA_BERLAKU_MENIT . " menit):\n\n" . $tautan['url'],
            ['disable_web_page_preview' => true]
        );
    }

    /**
     * Tampilkan daftar lokasi (hierarki tingkat pertama).
     */
    private function kirimHierarkiLokasi(int|string $chatId, ?int $messageId = null): void
    {
        $lokasi = ToolLocation::query()
            ->whereHas('racks.tools')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        if ($lokasi->isEmpty()) {
            $teks = 'Belum ada alat yang tersimpan. Hubungi administrator.';
            $messageId !== null
                ? $this->telegram->editMessageText($chatId, $messageId, $teks)
                : $this->telegram->sendMessage($chatId, $teks);

            return;
        }

        $teks = 'Pilih lokasi tempat alat disimpan:';
        $keyboard = ['inline_keyboard' => $lokasi
            ->map(fn (ToolLocation $item): array => [[
                'text'          => $item->name,
                'callback_data' => 'alat:l:' . $item->id,
            ]])
            ->values()
            ->all()];

        $keyboard['inline_keyboard'][] = [['text' => 'Batal', 'callback_data' => 'alat:batal']];

        $messageId !== null
            ? $this->telegram->editMessageText($chatId, $messageId, $teks, ['reply_markup' => $keyboard])
            : $this->telegram->sendMessage($chatId, $teks, ['reply_markup' => $keyboard]);
    }

    /**
     * Tampilkan rak pada satu lokasi.
     */
    private function kirimHierarkiRak(int|string $chatId, int $messageId, int $lokasiId): void
    {
        $lokasi = ToolLocation::query()->find($lokasiId);
        $rak    = ToolRack::query()
            ->where('tool_location_id', $lokasiId)
            ->whereHas('tools')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        if ($lokasi === null || $rak->isEmpty()) {
            $this->telegram->editMessageText($chatId, $messageId, 'Belum ada rak berisi alat di lokasi ini.');

            return;
        }

        $keyboard = ['inline_keyboard' => $rak
            ->map(fn (ToolRack $item): array => [[
                'text'          => $item->name,
                'callback_data' => 'alat:r:' . $item->id . ':1',
            ]])
            ->values()
            ->all()];

        $keyboard['inline_keyboard'][] = [['text' => 'Kembali ke lokasi', 'callback_data' => 'alat:lok']];

        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            'Lokasi <b>' . e($lokasi->name) . '</b>. Pilih rak:',
            ['reply_markup' => $keyboard]
        );
    }

    /**
     * Tampilkan alat pada satu rak (8 nama per halaman).
     */
    private function kirimHierarkiAlat(int|string $chatId, int $messageId, int $rakId, int $halaman): void
    {
        $rak = ToolRack::query()->with('location')->find($rakId);

        if ($rak === null) {
            $this->telegram->editMessageText($chatId, $messageId, 'Rak tidak ditemukan.');

            return;
        }

        $semua = Tool::query()
            ->with(['pinjamanAktif', 'pinjamanTerakhir'])
            ->where('tool_rack_id', $rakId)
            ->orderBy('name')
            ->orderBy('code')
            ->get();

        $grup = $semua
            ->groupBy(fn (Tool $alat): string => mb_strtolower($alat->name))
            ->values();

        $perHalaman = 8;
        $totalHalaman = max(1, (int) ceil($grup->count() / $perHalaman));
        $halaman = max(1, min($halaman, $totalHalaman));
        $potong = $grup->slice(($halaman - 1) * $perHalaman, $perHalaman);

        if ($potong->isEmpty()) {
            $this->telegram->editMessageText($chatId, $messageId, 'Tidak ada alat di rak ini.');

            return;
        }

        $keyboard = ['inline_keyboard' => $potong
            ->map(function (Collection $unit): array {
                /** @var Tool $alat */
                $alat     = $unit->first();
                $tersedia = $unit->filter(fn (Tool $item): bool => $item->status === Tool::STATUS_TERSEDIA)->count();

                return [[
                    'text'          => $alat->name . ' (' . $tersedia . '/' . $unit->count() . ' tersedia)',
                    'callback_data' => 'alat:g:' . $alat->id,
                ]];
            })
            ->values()
            ->all()];

        if ($halaman > 1) {
            $keyboard['inline_keyboard'][] = [[
                'text'          => 'Sebelumnya',
                'callback_data' => 'alat:r:' . $rakId . ':' . ($halaman - 1),
            ]];
        }

        if ($halaman < $totalHalaman) {
            $keyboard['inline_keyboard'][] = [[
                'text'          => 'Berikutnya',
                'callback_data' => 'alat:r:' . $rakId . ':' . ($halaman + 1),
            ]];
        }

        $keyboard['inline_keyboard'][] = [[
            'text'          => 'Kembali ke rak',
            'callback_data' => 'alat:l:' . $rak->tool_location_id,
        ]];

        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            'Rak <b>' . e($rak->name) . '</b> di <b>' . e($rak->location?->name ?? '-') . '</b>.'
            . ' Halaman ' . $halaman . '/' . $totalHalaman . '. Pilih alat:',
            ['reply_markup' => $keyboard]
        );
    }

    /**
     * Tampilkan ringkasan grup untuk alat yang dipilih dari hierarki.
     */
    private function pilihGrupAlat(int|string $chatId, int $messageId, int $alatId, Technician $teknisi): void
    {
        $alat = Tool::query()->with(['rack.location'])->find($alatId);

        if ($alat === null) {
            $this->telegram->editMessageText($chatId, $messageId, 'Alat tidak ditemukan.');

            return;
        }

        $unit  = $this->grupDariAlat($alat);
        $token = $this->token->buatToken($teknisi, ToolToken::PURPOSE_TEKNISI_PINJAM, $alat->id);

        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            'Alat dipilih: <b>' . e($alat->name) . '</b>. Ringkasan dikirim di pesan berikutnya.'
        );

        $this->kirimRingkasanGrup($chatId, $unit, $token['url']);
    }

    /**
     * Kembalikan satu pinjaman lewat tombol Telegram.
     */
    private function kembalikanSatu(int|string $chatId, int $messageId, Technician $teknisi, int $pinjamanId): void
    {
        $pinjaman = ToolLoan::query()
            ->with(['tool.rack.location'])
            ->find($pinjamanId);

        if ($pinjaman === null || (int) $pinjaman->technician_id !== (int) $teknisi->id) {
            $this->telegram->editMessageText($chatId, $messageId, 'Pinjaman ini bukan milik Anda.');

            return;
        }

        if (! $pinjaman->masihDipinjam()) {
            $this->telegram->editMessageText($chatId, $messageId, 'Pinjaman ini sudah dikembalikan sebelumnya.');

            return;
        }

        if ($pinjaman->menungguPenerimaan()) {
            $this->telegram->editMessageText(
                $chatId,
                $messageId,
                'Pengembalian alat ini sudah menunggu diterima tool keeper.'
            );

            return;
        }

        $this->peminjaman->mintaKembali($pinjaman, ToolLoan::HANDLED_SELF, $teknisi);

        $sisa = $this->pinjamanAktifTeknisi($teknisi);

        $pesan = 'Pengajuan pengembalian <b>' . e($pinjaman->tool_name) . '</b> (' . e($pinjaman->tool_code) . ')'
            . ' dikirim ke tool keeper. Alat akan kembali tersedia setelah diperiksa dan diterima keeper.';

        if ($sisa->isNotEmpty()) {
            $pesan .= "\n\n" . $this->teksDaftarPinjaman($sisa, 'Pinjaman lain yang masih bisa diajukan:');
        }

        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            $pesan,
            $sisa->isEmpty() ? [] : ['reply_markup' => $this->keyboardDaftarPinjaman($sisa)]
        );
    }

    /**
     * Keeper menerima pengembalian lewat tombol notifikasi.
     */
    private function terimaKembali(int|string $chatId, int $messageId, Technician $teknisi, int $pinjamanId): void
    {
        if (! $teknisi->toolKeeperAktif()) {
            $this->telegram->editMessageText($chatId, $messageId, 'Hanya tool keeper yang boleh menerima pengembalian alat.');

            return;
        }

        $pinjaman = ToolLoan::query()->find($pinjamanId);

        if ($pinjaman === null) {
            $this->telegram->editMessageText($chatId, $messageId, 'Pinjaman tidak ditemukan.');

            return;
        }

        if ($pinjaman->returned_at !== null) {
            $this->telegram->editMessageText($chatId, $messageId, 'Alat ini sudah diterima sebelumnya.');

            return;
        }

        $this->peminjaman->terimaKembali($pinjaman, ToolLoan::HANDLED_KEEPER, $teknisi);

        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            'Alat <b>' . e($pinjaman->tool_name) . '</b> (' . e($pinjaman->tool_code) . ') diterima kembali dan sudah tersedia.'
        );
    }

    /**
     * Daftar pinjaman aktif milik satu teknisi.
     *
     * @return Collection<int,ToolLoan>
     */
    private function pinjamanAktifTeknisi(Technician $teknisi): Collection
    {
        return ToolLoan::query()
            ->with(['tool.rack.location', 'tool.group'])
            ->where('technician_id', $teknisi->id)
            ->aktif()
            ->whereNull('return_requested_at')
            ->orderByDesc('borrowed_at')
            ->get();
    }

    /**
     * Teks daftar pinjaman untuk pesan Telegram.
     *
     * @param Collection<int,ToolLoan> $daftar
     */
    private function teksDaftarPinjaman(Collection $daftar, string $judul): string
    {
        $baris   = [$judul, ''];

        foreach ($daftar as $pinjaman) {
            $baris[] = '- <b>' . e($pinjaman->tool_name) . '</b> (' . e($pinjaman->tool_code) . ')'
                . ', dipinjam ' . $pinjaman->borrowed_at?->format('d/m/Y H:i');
        }

        return implode("\n", $baris);
    }

    /**
     * Keyboard daftar pinjaman (satu tombol per pinjaman + semua + batal).
     *
     * @param  Collection<int,ToolLoan> $daftar
     * @return array<string,mixed>
     */
    private function keyboardDaftarPinjaman(Collection $daftar): array
    {
        $baris = $daftar
            ->map(fn (ToolLoan $pinjaman): array => [[
                'text'          => 'Kembalikan ' . $pinjaman->tool_name . ' (' . $pinjaman->tool_code . ')',
                'callback_data' => 'alat:ret:' . $pinjaman->id,
            ]])
            ->values()
            ->all();

        if ($daftar->count() > 1) {
            $baris[] = [['text' => 'Kembalikan semua', 'callback_data' => 'alat:retall']];
        }

        $baris[] = [['text' => 'Batal', 'callback_data' => 'alat:batal']];

        return ['inline_keyboard' => $baris];
    }

    /**
     * Keyboard daftar lokasi untuk perintah /pakai tanpa hasil pencarian.
     *
     * @return array<string,mixed>
     */
    private function keyboardLokasi(): array
    {
        $lokasi = ToolLocation::query()
            ->whereHas('racks.tools')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        $baris = $lokasi
            ->map(fn (ToolLocation $item): array => [[
                'text'          => $item->name,
                'callback_data' => 'alat:l:' . $item->id,
            ]])
            ->values()
            ->all();

        $baris[] = [['text' => 'Batal', 'callback_data' => 'alat:batal']];

        return ['inline_keyboard' => $baris];
    }
}
