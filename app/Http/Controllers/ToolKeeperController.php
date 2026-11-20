<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Penunjukan tool keeper oleh administrator.
 *
 * Keeper adalah teknisi aktif yang punya telegram_id. Penunjukan hanya boleh
 * dilakukan administrator, terlepas dari hak baca modul Management Tools.
 */
class ToolKeeperController extends Controller
{
    /**
     * Daftar teknisi aktif beserta status keeper.
     */
    public function index(Request $request): View
    {
        $cari = trim((string) $request->query('q', ''));

        $daftar = Technician::query()
            ->active()
            ->whereNotNull('telegram_id')
            ->with('department')
            ->when($cari !== '', function ($query) use ($cari): void {
                $query->where(function ($sub) use ($cari): void {
                    $sub->where('name', 'like', '%' . $cari . '%')
                        ->orWhere('nik', 'like', '%' . $cari . '%')
                        ->orWhere('telegram_username', 'like', '%' . $cari . '%');
                });
            })
            ->orderByDesc('is_tool_keeper')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('tools.keeper', [
            'daftar'     => $daftar,
            'cari'       => $cari,
            'bolehTulis' => (bool) auth()->user()?->bolehMenulis(),
            'bolehAtur'  => (bool) auth()->user()?->isAdmin(),
        ]);
    }

    /**
     * Jadikan/lepas satu teknisi sebagai keeper.
     */
    public function toggle(Request $request, Technician $technician): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Hanya administrator yang boleh menunjuk tool keeper.');

        if ($technician->status !== 'active' || blank($technician->telegram_id)) {
            return back()->withErrors(['keeper' => 'Keeper harus teknisi aktif yang sudah terhubung Telegram.']);
        }

        $technician->update(['is_tool_keeper' => ! $technician->is_tool_keeper]);

        return back()->with(
            'success',
            $technician->is_tool_keeper
                ? $technician->name . ' sekarang menjadi tool keeper.'
                : $technician->name . ' tidak lagi menjadi tool keeper.'
        );
    }
}
