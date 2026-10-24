{{--
    Tab Kejar PO: tabel ringkas PR yang menunggu PO di bagian atas (bisa
    di-collapse), lalu daftar lengkap dengan pencarian dan pagination.
--}}
<details open class="overflow-hidden rounded-xl border border-amber-200 bg-white">
    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 border-b border-amber-100 bg-amber-50/60 px-4 py-3">
        <span class="text-sm font-semibold text-amber-900">Daftar PR Menunggu PO</span>
        <span class="inline-flex items-center gap-2 text-xs text-amber-700">
            {{ $prMenungguPo->count() }} request
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </span>
    </summary>

    <div class="max-h-80 overflow-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50">
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Kode</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Teknisi</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Keterangan</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-slate-500">No PR</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($prMenungguPo as $row)
                    <tr class="align-top hover:bg-slate-50/70">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold text-slate-800">{{ $row->kode }}</td>
                        <td class="px-4 py-2.5">@include('sparepart.request.partials._teknisi', ['row' => $row])</td>
                        <td class="max-w-[20rem] px-4 py-2.5 text-xs text-slate-700">
                            {{ $row->keterangan }}
                            <span class="mt-0.5 block text-[11px] text-slate-400">
                                {{ $angka($row->jumlah) }}{{ $row->satuan ? ' ' . $row->satuan : '' }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-slate-700">
                            <span class="block font-mono">{{ $row->pr_no }}</span>
                            <span class="block text-[11px] text-slate-400">{{ $row->pr_at?->format('d M Y') }}</span>
                        </td>
                        <td class="px-4 py-2.5">
                            @if($bolehKelola)
                                <form method="POST" action="{{ route('sparepart.request.po', $row) }}"
                                      class="flex flex-wrap items-center gap-1.5">
                                    @csrf
                                    <input name="po_no" required maxlength="50" placeholder="Nomor PO"
                                           class="w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-teal-400">
                                    <button type="submit"
                                            class="rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-teal-700">
                                        Simpan PO
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-slate-400">Lihat saja</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-400">
                            Tidak ada PR yang menunggu PO.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</details>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-100 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Semua Request di Kejar PO</h2>
        <p class="mt-0.5 text-xs text-slate-400">Gunakan pencarian dan filter tanggal di atas untuk mempersempit daftar.</p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50">
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Kode / Tanggal</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Teknisi</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Keterangan</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Jumlah</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">No PR</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($daftar as $row)
                    <tr class="align-top hover:bg-slate-50/70">
                        <td class="px-4 py-3">
                            <span class="block font-mono text-xs font-semibold text-slate-800">{{ $row->kode }}</span>
                            <span class="block text-[11px] text-slate-400">{{ $row->created_at?->format('d M Y H:i') }}</span>
                        </td>
                        <td class="px-4 py-3">@include('sparepart.request.partials._teknisi', ['row' => $row])</td>
                        <td class="max-w-[22rem] px-4 py-3">
                            <span class="block text-xs text-slate-700">{{ $row->keterangan }}</span>
                            @if($row->deskripsi_ukuran)
                                <span class="mt-0.5 block text-[11px] text-slate-400">Ukuran: {{ $row->deskripsi_ukuran }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700 whitespace-nowrap">
                            {{ $angka($row->jumlah) }}{{ $row->satuan ? ' ' . $row->satuan : '' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700">
                            <span class="block font-mono">{{ $row->pr_no }}</span>
                            <span class="block text-[11px] text-slate-400">{{ $row->pr_at?->format('d M Y') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if($bolehKelola)
                                <form method="POST" action="{{ route('sparepart.request.po', $row) }}"
                                      class="flex flex-wrap items-center gap-1.5">
                                    @csrf
                                    <input name="po_no" required maxlength="50" placeholder="Nomor PO"
                                           class="w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-teal-400">
                                    <button type="submit"
                                            class="rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-teal-700">
                                        Simpan PO
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-slate-400">Lihat saja</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-400">
                            Tidak ada request pada Kejar PO.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($daftar->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $daftar->links() }}</div>
    @endif
</div>
