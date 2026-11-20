<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\ToolRack;
use App\Services\ToolLendingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Riwayat peminjaman alat dan aksi Pinjamkan/Kembalikan dari halaman admin.
 */
class ToolLoanController extends Controller
{
    public function __construct(private ToolLendingService $peminjaman)
    {
    }

    /**
     * Daftar riwayat peminjaman dengan filter.
     */
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['aktif', 'selesai'], true)
            ? $request->query('status')
            : 'aktif';

        $cari = trim((string) $request->query('q', ''));

        $daftar = ToolLoan::query()
            ->with(['tool.rack.location', 'technician', 'borrowerDepartment', 'handlerTechnician', 'returnerTechnician'])
            ->when($status === 'aktif', fn ($query) => $query->aktif())
            ->when($status === 'selesai', fn ($query) => $query->selesai())
            ->when($cari !== '', function ($query) use ($cari): void {
                $query->where(function ($sub) use ($cari): void {
                    $sub->where('kode', 'like', '%' . $cari . '%')
                        ->orWhere('tool_name', 'like', '%' . $cari . '%')
                        ->orWhere('tool_code', 'like', '%' . $cari . '%')
                        ->orWhere('borrower_name', 'like', '%' . $cari . '%')
                        ->orWhereHas('technician', function ($t) use ($cari): void {
                            $t->where('name', 'like', '%' . $cari . '%')
                                ->orWhere('nik', 'like', '%' . $cari . '%');
                        });
                });
            })
            ->when($request->filled('lokasi'), function ($query) use ($request): void {
                $query->whereHas('tool.rack', fn ($rak) => $rak->where('tool_location_id', $request->query('lokasi')));
            })
            ->when($request->filled('rak'), function ($query) use ($request): void {
                $query->whereHas('tool', fn ($alat) => $alat->where('tool_rack_id', $request->query('rak')));
            })
            ->orderByDesc('borrowed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('tools.riwayat', [
            'daftar'           => $daftar,
            'status'           => $status,
            'cari'             => $cari,
            'daftarLokasi'     => \App\Models\ToolLocation::query()->orderBy('name')->get(['id', 'name']),
            'daftarRak'        => ToolRack::query()->orderBy('name')->get(['id', 'tool_location_id', 'name']),
            'bolehTulis'       => (bool) auth()->user()?->bolehMenulis(),
        ]);
    }

    /**
     * Pinjamkan alat dari dashboard admin.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tool_id'                 => ['required', 'exists:tools,id'],
            'borrower_type'           => ['required', Rule::in([ToolLoan::BORROWER_TEKNISI, ToolLoan::BORROWER_NON_TEKNISI])],
            'technician_id'           => [
                'required_if:borrower_type,teknisi',
                'nullable',
                Rule::exists('technicians', 'id')->where('status', 'active'),
            ],
            'borrower_name'           => ['required_if:borrower_type,non_teknisi', 'nullable', 'string', 'max:150'],
            'borrower_department_id'  => ['nullable', 'exists:departments,id'],
            'catatan'                 => ['nullable', 'string', 'max:1000'],
        ]);

        $alat = Tool::query()->with('rack.location')->findOrFail($data['tool_id']);

        if ($data['borrower_type'] === ToolLoan::BORROWER_TEKNISI) {
            $teknisi = Technician::query()->active()->with('department')->findOrFail($data['technician_id']);
            $peminjam = $this->peminjaman->dataPeminjamTeknisi($teknisi);
        } else {
            $departemen = isset($data['borrower_department_id'])
                ? Department::query()->find($data['borrower_department_id'])
                : null;

            $peminjam = $this->peminjaman->dataPeminjamNonTeknisi(
                trim($data['borrower_name']),
                $departemen?->id,
                $departemen?->name
            );
        }

        $this->peminjaman->pinjamkan(
            $alat,
            $peminjam,
            ToolLoan::HANDLED_ADMIN,
            null,
            $request->user(),
            $data['catatan'] ?? null
        );

        return back()->with('success', 'Peminjaman alat berhasil dicatat.');
    }

    /**
     * Kembalikan satu pinjaman dari halaman admin.
     */
    public function kembalikan(Request $request, ToolLoan $toolLoan): RedirectResponse
    {
        $this->peminjaman->kembalikan($toolLoan, ToolLoan::HANDLED_ADMIN, null, $request->user());

        return back()->with('success', 'Alat berhasil dikembalikan.');
    }
}
