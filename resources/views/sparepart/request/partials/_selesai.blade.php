{{--
    Tab Selesai: arsip request yang barangnya sudah diterima.
--}}
<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-100 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Request Selesai</h2>
        <p class="mt-0.5 text-xs text-slate-400">Arsip request yang barangnya sudah diterima.</p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50">
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Kode / Tanggal</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Teknisi</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Keterangan</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Jumlah</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">PR / PO</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Diterima</th>
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
                        <td class="max-w-[24rem] px-4 py-3">
                            <span class="block text-xs text-slate-700">{{ $row->keterangan }}</span>
                            @if($row->deskripsi_ukuran)
                                <span class="mt-0.5 block text-[11px] text-slate-400">Ukuran: {{ $row->deskripsi_ukuran }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700 whitespace-nowrap">
                            {{ $angka($row->jumlah) }}{{ $row->satuan ? ' ' . $row->satuan : '' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700">
                            <span class="block font-mono">{{ $row->pr_no ?: '-' }}</span>
                            <span class="block font-mono">{{ $row->po_no ?: '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700">
                            <span class="block">{{ $row->received_at?->format('d M Y H:i') ?? '-' }}</span>
                            @if($row->receiver)
                                <span class="block text-[11px] text-slate-400">Oleh {{ $row->receiver->name }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-400">
                            Belum ada request yang selesai.
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
