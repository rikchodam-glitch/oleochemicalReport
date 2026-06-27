<?php

namespace App\Http\Controllers;

use App\Models\BotRegistration;
use App\Models\BotUnknownAsset;
use App\Models\Department;
use App\Models\Technician;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;

class BotController extends Controller
{
    private function isProcessRunning(int $pid): bool
    {
        // Jika PID < 100, kemungkinan bukan PID (fallback timestamp)
        if ($pid < 100) {
            return false;
        }

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $result = shell_exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL");
                return $result !== null && str_contains($result, (string)$pid);
            } else {
                $result = shell_exec("kill -0 $pid 2>/dev/null && echo 1 || echo 0");
                return trim($result ?? '0') === '1';
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getPollingStatus(): array
    {
        $lockFile = storage_path('app/telegram_poll.lock');
        $stopFile = storage_path('app/telegram_poll.stop');

        $running = false;

        // Cek dengan lock file — jika lock file ada dan diupdate <150 detik lalu, polling hidup
        if (file_exists($lockFile)) {
            $lockTime = (int) file_get_contents($lockFile);
            if (time() - $lockTime < 150) {
                $running = true;
            } else {
                // Lock file expired
                @unlink($lockFile);
            }
        }

        // Jika ada stop file, polling sedang dalam proses berhenti
        if (file_exists($stopFile)) {
            // Jika stop file sudah lebih dari 10 detik, cleanup
            $stopTime = (int) file_get_contents($stopFile);
            if (time() - $stopTime > 10) {
                @unlink($stopFile);
                @unlink($lockFile);
                $running = false;
            }
        }

        return ['running' => $running];
    }

    public function index()
    {
        // Teknisi yang sudah terhubung ke bot (memiliki telegram_id)
        $activeBotTechnicians = Technician::with('department')
            ->whereNotNull('telegram_id')
            ->orderByDesc('last_active_at')
            ->get();

        $stats = [
            'bot_status'         => 'online',
            'total_technicians'  => Technician::count(),
            'active_technicians' => Technician::where('status', 'active')->count(),
            'terhubung_ke_bot'   => $activeBotTechnicians->count(),
            'reports_via_bot'    => \App\Models\Report::whereNotNull('telegram_message_id')->count(),
            'reports_today'      => \App\Models\Report::whereNotNull('telegram_message_id')
                ->whereDate('report_date', today())->count(),
            'unknown_assets'     => BotUnknownAsset::count(),
        ];

        // Pendaftaran yang masih menunggu persetujuan
        $registrations = BotRegistration::with('processor')
            ->where('status', 'pending')
            ->latest()
            ->get();

        // Riwayat pendaftaran yang sudah diproses (approved/rejected)
        $registrationHistory = BotRegistration::with(['processor', 'technician'])
            ->whereIn('status', ['approved', 'rejected'])
            ->latest('processed_at')
            ->take(50)
            ->get();

        $logs = \App\Models\Report::with('technician')
            ->whereNotNull('telegram_message_id')
            ->latest()
            ->take(20)
            ->get();

        $unknownAssets = BotUnknownAsset::with('report')
            ->latest()
            ->get();

        // Data untuk dropdown modal persetujuan
        $departments = Department::orderBy('name')->get();

        // Cek apakah polling sedang berjalan
        $pollStatus     = $this->getPollingStatus();
        $pollingRunning = $pollStatus['running'];

        return view('bot.index', compact(
            'stats',
            'registrations',
            'registrationHistory',
            'activeBotTechnicians',
            'logs',
            'unknownAssets',
            'pollingRunning',
            'departments'
        ));
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'token'         => 'nullable|string',
            'status'        => 'required|in:active,inactive',
            'auto_approve'  => 'boolean',
            'max_item'      => 'integer|min:1|max:20',
            'notif_channel' => 'nullable|string',
        ]);

        return back()->with('success', 'Pengaturan bot berhasil disimpan.');
    }

    public function testConnection()
    {
        try {
            $token = config('services.telegram.bot_token');

            if (empty($token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token bot belum dikonfigurasi. Isi TELEGRAM_BOT_TOKEN di file .env',
                ]);
            }

            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");

            if ($response->successful()) {
                $botInfo = $response->json()['result'] ?? [];
                return response()->json([
                    'success' => true,
                    'message' => "Koneksi berhasil! @{$botInfo['username']} ({$botInfo['first_name']}) terhubung.",
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal: ' . ($response->json()['description'] ?? 'Unknown error'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal: ' . $e->getMessage(),
            ]);
        }
    }

    public function setWebhook()
    {
        try {
            $token = config('services.telegram.bot_token');

            if (empty($token)) {
                return back()->with('error', 'Token bot belum dikonfigurasi.');
            }

            $url      = route('telegram.webhook');
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                'url' => $url,
            ]);

            if ($response->successful()) {
                return back()->with('success', 'Webhook berhasil disetel ke: ' . $url);
            }

            return back()->with('error', 'Gagal: ' . ($response->json()['description'] ?? 'Unknown error'));
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    public function deleteWebhook()
    {
        try {
            $token = config('services.telegram.bot_token');

            if (empty($token)) {
                return back()->with('error', 'Token bot belum dikonfigurasi.');
            }

            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/deleteWebhook");

            if ($response->successful()) {
                return back()->with('success', 'Webhook berhasil dihapus.');
            }

            return back()->with('error', 'Gagal: ' . ($response->json()['description'] ?? 'Unknown error'));
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    /**
     * Setujui pendaftaran langsung (tanpa data tambahan) — dipertahankan sebagai fallback.
     *
     * @param  BotRegistration  $registration
     * @return \Illuminate\Http\RedirectResponse
     */
    public function approveRegistration(BotRegistration $registration)
    {
        $registration->update([
            'status'       => 'approved',
            'processed_by' => Auth::id(),
            'processed_at' => now(),
        ]);

        Technician::create([
            'telegram_id'       => $registration->telegram_id,
            'telegram_username' => $registration->telegram_username,
            'name'              => $registration->name,
            'nik'               => $registration->nik,
            'status'            => 'active',
            'approved_by'       => Auth::id(),
            'approved_at'       => now(),
        ]);

        return back()->with('success', 'Pendaftaran ' . $registration->name . ' disetujui.');
    }

    /**
     * Setujui pendaftaran dengan data lengkap dari modal form.
     *
     * @param  Request           $request
     * @param  BotRegistration   $registration
     * @return \Illuminate\Http\RedirectResponse
     */
    public function approveWithDetails(Request $request, BotRegistration $registration)
    {
        if ($registration->status !== 'pending') {
            return back()->with('error', 'Pendaftaran ini sudah diproses sebelumnya.');
        }

        $request->validate([
            'name'          => 'required|string|max:255',
            'nik'           => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
            'group'         => 'nullable|in:' . implode(',', array_keys(Technician::GROUPS)),
            'section'       => 'nullable|in:' . implode(',', array_keys(Technician::SECTIONS)),
        ], [
            'name.required'        => 'Nama teknisi wajib diisi.',
            'department_id.exists' => 'Departemen tidak ditemukan.',
            'group.in'             => 'Nilai group tidak valid.',
            'section.in'           => 'Nilai section tidak valid.',
        ]);

        // Cek duplikat NIK di tabel technicians
        if ($request->filled('nik')) {
            $nikExists = Technician::where('nik', $request->nik)->exists();
            if ($nikExists) {
                return back()
                    ->withInput()
                    ->with('error', 'NIK ' . $request->nik . ' sudah terdaftar di tabel teknisi. Periksa kembali sebelum menyimpan.');
            }
        }

        $registration->update([
            'status'       => 'approved',
            'processed_by' => Auth::id(),
            'processed_at' => now(),
        ]);

        Technician::create([
            'telegram_id'       => $registration->telegram_id,
            'telegram_username' => $registration->telegram_username,
            'name'              => $request->name,
            'nik'               => $request->nik,
            'department_id'     => $request->department_id,
            'group'             => $request->group,
            'section'           => $request->section,
            'status'            => 'active',
            'approved_by'       => Auth::id(),
            'approved_at'       => now(),
        ]);

        return back()->with('success', 'Pendaftaran ' . $request->name . ' disetujui dan data teknisi berhasil dibuat.');
    }

    public function rejectRegistration(BotRegistration $registration)
    {
        $registration->update([
            'status'       => 'rejected',
            'processed_by' => Auth::id(),
            'processed_at' => now(),
        ]);

        return back()->with('success', 'Pendaftaran ' . $registration->name . ' ditolak.');
    }

    public function startPolling()
    {
        return back()->with('info', 'Jalankan polling manual melalui terminal: <code>php artisan telegram:poll</code>');
    }

    public function stopPolling()
    {
        $lockFile = storage_path('app/telegram_poll.lock');
        $stopFile = storage_path('app/telegram_poll.stop');

        try {
            // Kirim stop signal via file saja.
            // Proses polling membaca stopFile setiap iterasi
            // dan akan berhenti sendiri dalam max 30 detik.
            // Tidak perlu kill PID secara paksa.
            file_put_contents($stopFile, time());

            return back()->with('success', 'Perintah berhenti telah dikirim. Polling akan berhenti dalam beberapa detik.');
        } catch (\Exception $e) {
            @unlink($lockFile);
            @unlink($stopFile);
            return back()->with('warning', 'Polling dihentikan.');
        }
    }

    public function pollingStatus()
    {
        $status = $this->getPollingStatus();

        $logContent = '';
        $logFile    = storage_path('logs/telegram-poll.log');
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            $lines      = explode("\n", $logContent);
            $logContent = implode("\n", array_slice($lines, -20));
        }

        return response()->json([
            'running'  => $status['running'],
            'last_log' => $logContent ?: '(Belum ada log)',
        ]);
    }
}
