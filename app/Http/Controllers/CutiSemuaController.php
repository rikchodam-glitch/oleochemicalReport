<?php

namespace App\Http\Controllers;

use App\Models\CutiTeknisi;
use App\Models\Technician;
use Illuminate\Http\Request;

/**
 * Halaman read-only seluruh cuti karyawan untuk Administrator, Supervisor,
 * dan Admin General. Pencatatan cuti tetap dilakukan Admin Reguler.
 */
class CutiSemuaController extends Controller
{
    /**
     * Daftar seluruh cuti dengan filter teknisi/group/bagian/tanggal.
     *
     * @param  Request $request Request filter
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        if (! auth()->user()?->bolehLihatSemuaCuti()) {
            abort(403, 'Halaman ini hanya untuk Administrator, Supervisor, atau Admin General.');
        }

        $cari = trim((string) $request->query('q', ''));
        $grup = (string) $request->query('grup', '');
        $bagian = (string) $request->query('bagian', '');
        $dari = $this->tanggalValid((string) $request->query('dari', ''));
        $sampai = $this->tanggalValid((string) $request->query('sampai', ''));

        if (! array_key_exists($grup, Technician::GROUPS)) {
            $grup = '';
        }

        if (! array_key_exists($bagian, Technician::SECTIONS)) {
            $bagian = '';
        }

        $base = CutiTeknisi::query()
            ->when($cari !== '', function ($query) use ($cari): void {
                $query->whereHas('technician', function ($teknisi) use ($cari): void {
                    $teknisi->where('name', 'like', '%' . $cari . '%')
                        ->orWhere('nik', 'like', '%' . $cari . '%');
                });
            })
            ->when($grup !== '', function ($query) use ($grup): void {
                $query->whereHas('technician', fn ($teknisi) => $teknisi->where('group', $grup));
            })
            ->when($bagian !== '', function ($query) use ($bagian): void {
                $query->whereHas('technician', fn ($teknisi) => $teknisi->where('section', $bagian));
            })
            ->when($dari !== '', fn ($query) => $query->whereDate('tanggal_selesai', '>=', $dari))
            ->when($sampai !== '', fn ($query) => $query->whereDate('tanggal_mulai', '<=', $sampai));

        $total        = (clone $base)->count();
        $teknisiUnik  = (clone $base)->distinct('technician_id')->count('technician_id');

        $daftar = $base
            ->with('technician:id,name,nik,group,section')
            ->orderByDesc('tanggal_mulai')
            ->paginate(25)
            ->withQueryString();

        return view('cuti.semua', [
            'daftar'       => $daftar,
            'total'        => $total,
            'teknisiUnik'  => $teknisiUnik,
            'cari'         => $cari,
            'grup'         => $grup,
            'bagian'       => $bagian,
            'dari'         => $dari,
            'sampai'       => $sampai,
            'groups'       => Technician::GROUPS,
            'sections'     => Technician::SECTIONS,
        ]);
    }

    /**
     * Validasi tanggal filter dalam format Y-m-d.
     *
     * @param  string $nilai Nilai query
     * @return string
     */
    private function tanggalValid(string $nilai): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) === 1 ? $nilai : '';
    }
}
