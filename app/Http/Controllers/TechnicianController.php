<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Technician;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TechnicianController extends Controller
{
    public function index(Request $request)
    {
        $query = Technician::with(['department', 'approver']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by department
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Filter by group
        if ($request->filled('group')) {
            $query->where('group', $request->group);
        }

        // Filter by section
        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%")
                  ->orWhere('telegram_username', 'like', "%{$search}%");
            });
        }

        $technicians = $query->latest()->paginate(20)->withQueryString();
        $departments = Department::all();

        return view('technicians.index', compact('technicians', 'departments'));
    }

    public function create()
    {
        $departments = Department::all();
        $groups = \App\Models\Technician::GROUPS;
        $sections = \App\Models\Technician::SECTIONS;
        return view('technicians.create', compact('departments', 'groups', 'sections'));
    }

    public function store(Request $request, TelegramService $telegram)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'nik' => 'nullable|string|max:50|unique:technicians,nik',
            'telegram_id' => 'nullable|string|max:50|unique:technicians,telegram_id',
            'telegram_username' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'group' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::GROUPS)),
            'section' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::SECTIONS)),
            'status' => 'required|in:pending,active',
            'send_notification' => 'boolean',
        ]);

        $technician = Technician::create([
            'name' => $validated['name'],
            'nik' => $validated['nik'] ?? null,
            'telegram_id' => $validated['telegram_id'] ?? null,
            'telegram_username' => $validated['telegram_username'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'group' => $validated['group'] ?? null,
            'section' => $validated['section'] ?? null,
            'status' => $validated['status'],
            'approved_by' => $validated['status'] === 'active' ? Auth::id() : null,
            'approved_at' => $validated['status'] === 'active' ? now() : null,
        ]);

        // Broadcast jika diminta dan teknisi punya telegram
        $broadcasted = false;
        if (
            $request->boolean('send_notification') &&
            $technician->telegram_id &&
            $technician->status === 'active'
        ) {
            $message = "🎉 Hai {$technician->name}!\n\n";
            $message .= "Akun teknisi Anda telah berhasil dibuat dan aktif.\n\n";
            $message .= "📋 <b>Identitas:</b>\n";
            $message .= "Nama: {$technician->name}\n";
            if ($technician->nik) $message .= "NIK: {$technician->nik}\n";
            $message .= "\nAnda dapat mulai melaporkan pekerjaan harian melalui bot ini.\n";
            $message .= "Kirim laporan Anda sekarang!";

            $broadcasted = $telegram->sendMessage($technician->telegram_id, $message);
        }

        $message = 'Teknisi berhasil ditambahkan.';
        if ($broadcasted) {
            $message .= ' Notasi Telegram terkirim.';
        } elseif ($request->boolean('send_notification') && !$technician->telegram_id) {
            $message .= ' Notifikasi tidak dikirim (teknisi belum punya Telegram ID).';
        }

        return redirect()->route('technicians.show', $technician)
            ->with('success', $message);
    }

    public function edit(Technician $technician)
    {
        $departments = Department::all();
        $groups = \App\Models\Technician::GROUPS;
        $sections = \App\Models\Technician::SECTIONS;
        return view('technicians.edit', compact('technician', 'departments', 'groups', 'sections'));
    }

    public function update(Request $request, Technician $technician, TelegramService $telegram)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'nik' => 'nullable|string|max:50|unique:technicians,nik,' . $technician->id,
            'telegram_id' => 'nullable|string|max:50|unique:technicians,telegram_id,' . $technician->id,
            'telegram_username' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'group' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::GROUPS)),
            'section' => 'nullable|string|in:' . implode(',', array_keys(\App\Models\Technician::SECTIONS)),
            'status' => 'required|in:pending,active,suspended',
        ]);

        $technician->update($validated);

        // Kirim notifikasi jika diaktifkan dan punya telegram
        if (
            $technician->wasChanged('status') &&
            $technician->status === 'active' &&
            $technician->telegram_id
        ) {
            $message = "✅ Akun teknisi Anda telah diaktifkan!\n\n";
            $message .= "Anda sekarang dapat melaporkan pekerjaan harian melalui bot ini.";
            $telegram->sendMessage($technician->telegram_id, $message);
        }

        return redirect()->route('technicians.show', $technician)
            ->with('success', 'Data teknisi berhasil diperbarui.');
    }

    public function show(Technician $technician)
    {
        $technician->load(['department', 'approver', 'reports' => function ($q) {
            $q->latest()->take(20);
        }]);

        $stats = [
            'total_reports' => $technician->reports()->count(),
            'completed_reports' => $technician->reports()->where('status', 'completed')->count(),
            'pending_reports' => $technician->reports()->where('status', 'draft')->count(),
            'last_report' => $technician->reports()->latest()->first()?->report_date,
            'reports_this_month' => $technician->reports()
                ->whereMonth('report_date', now()->month)
                ->whereYear('report_date', now()->year)
                ->count(),
        ];

        // Statistik koneksi bot untuk seksi Koneksi Bot Telegram
        $botStats = [
            'reports_via_bot'          => $technician->reports()
                ->whereNotNull('telegram_message_id')
                ->count(),
            'laporan_terakhir_via_bot' => $technician->reports()
                ->whereNotNull('telegram_message_id')
                ->latest('report_date')
                ->value('report_date'),
        ];

    return view('technicians.show', compact('technician', 'stats', 'botStats'));
    }

    public function approve(Technician $technician)
    {
        $technician->update([
            'status' => 'active',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Teknisi berhasil diaktifkan.');
    }

    public function suspend(Technician $technician)
    {
        $technician->update(['status' => 'suspended']);
        return back()->with('success', 'Teknisi ditangguhkan.');
    }

    public function reactivate(Technician $technician)
    {
        $technician->update(['status' => 'active']);
        return back()->with('success', 'Teknisi diaktifkan kembali.');
    }

    public function bulkApprove(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:technicians,id',
        ]);

        Technician::whereIn('id', $request->ids)
            ->where('status', 'pending')
            ->update([
                'status' => 'active',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

        return back()->with('success', count($request->ids) . ' teknisi berhasil diaktifkan.');
    }

    public function destroy(Technician $technician)
    {
        $technician->delete();
        return redirect()->route('technicians.index')
            ->with('success', 'Teknisi berhasil dihapus.');
    }

    public function broadcast(Request $request, Technician $technician, TelegramService $telegram)
    {
        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $sent = false;
        if ($technician->telegram_id) {
            $sent = $telegram->sendMessage($technician->telegram_id, $validated['message']);
        }

        if ($sent) {
            return response()->json([
                'success' => true,
                'message' => 'Pesan berhasil dikirim ke ' . $technician->name . '.',
            ]);
        } elseif ($technician->telegram_id) {
            return response()->json([
                'success' => true,
                'message' => 'Pesan dicatat (mock mode — token Telegram belum dikonfigurasi).',
            ]);
        } else {
            return response()->json([
                'success' => true,
                'message' => 'Pesan dicatat (teknisi tidak punya Telegram ID).',
            ]);
        }
    }
}

