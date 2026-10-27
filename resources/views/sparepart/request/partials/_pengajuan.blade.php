{{--
    Tab Pengajuan: request menunggu, disetujui (isi PR), dan ditolak.
--}}
@php
    $chipStatus = [
        \App\Models\SpRequest::STATUS_MENUNGGU  => 'Menunggu',
        \App\Models\SpRequest::STATUS_DISETUJUI => 'Disetujui',
        \App\Models\SpRequest::STATUS_DITOLAK   => 'Ditolak',
        'semua'                                 => 'Semua',
    ];
@endphp

<div class="flex flex-wrap items-center gap-2">
    @foreach($chipStatus as $kunci => $label)
        @php
            $jumlahChip = $kunci === 'semua'
                ? (int) ($jumlahStatus[\App\Models\SpRequest::STATUS_MENUNGGU] ?? 0)
                    + (int) ($jumlahStatus[\App\Models\SpRequest::STATUS_DISETUJUI] ?? 0)
                    + (int) ($jumlahStatus[\App\Models\SpRequest::STATUS_DITOLAK] ?? 0)
                : (int) ($jumlahStatus[$kunci] ?? 0);
        @endphp
        <a href="{{ $tautanStatus($kunci) }}"
           class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors
                  {{ $statusPengajuan === $kunci
                        ? 'border-teal-300 bg-teal-50 text-teal-700'
                        : 'border-slate-200 bg-white text-slate-600 hover:border-teal-200 hover:text-teal-700' }}">
            {{ $label }}
            <span class="text-[10px] opacity-70">{{ $jumlahChip }}</span>
        </a>
    @endforeach
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50">
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Kode / Tanggal</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Teknisi</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Bagian</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Keterangan</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Jumlah</th>
                    <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wide text-slate-500">Foto</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Status</th>
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
                        <td class="px-4 py-3">
                            @include('sparepart.request.partials._teknisi', ['row' => $row])
                        </td>
                        <td class="px-4 py-3">
                            @include('sparepart.request.partials._bagian', ['row' => $row])
                        </td>
                        <td class="max-w-[22rem] px-4 py-3">
                            <span class="block text-xs text-slate-700">{{ $row->keterangan }}</span>
                            @if($row->deskripsi_ukuran)
                                <span class="mt-0.5 block text-[11px] text-slate-400">Ukuran: {{ $row->deskripsi_ukuran }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700 whitespace-nowrap">
                            {{ $angka($row->jumlah) }}{{ $row->satuan ? ' ' . $row->satuan : '' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($row->foto_url)
                                <button type="button" @click="foto = '{{ $row->foto_url }}'"
                                        class="inline-block overflow-hidden rounded-lg border border-slate-200 hover:opacity-80"
                                        title="Lihat foto request">
                                    <img src="{{ $row->foto_url }}" alt="Foto request {{ $row->kode }}"
                                         class="h-12 w-12 object-cover">
                                </button>
                            @else
                                <span class="text-slate-300">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ring-1
                                         {{ $badgeStatus[$row->status] ?? 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                {{ $row->labelStatus() }}
                            </span>
                            @if($row->status === \App\Models\SpRequest::STATUS_DITOLAK && $row->alasan_tolak)
                                <span class="mt-1 block max-w-[14rem] text-[11px] text-rose-500">{{ $row->alasan_tolak }}</span>
                            @endif
                            @if($row->status === \App\Models\SpRequest::STATUS_DISETUJUI && $row->approver)
                                <span class="mt-1 block text-[11px] text-slate-400">Oleh {{ $row->approver->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if(! $bolehKelola)
                                <span class="text-xs text-slate-400">Lihat saja</span>
                            @elseif($row->status === \App\Models\SpRequest::STATUS_MENUNGGU)
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <form method="POST" action="{{ route('sparepart.request.setujui', $row) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-teal-700">
                                            Setujui
                                        </button>
                                    </form>
                                    <button type="button" @click="tolak = {{ $row->id }}"
                                            class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50">
                                        Tolak
                                    </button>
                                    @include('sparepart.request.partials._tombol-hapus', ['row' => $row])
                                </div>
                            @elseif($row->status === \App\Models\SpRequest::STATUS_DISETUJUI)
                                <div class="flex flex-wrap items-start gap-1.5">
                                    <form method="POST" action="{{ route('sparepart.request.pr', $row) }}"
                                          class="flex flex-wrap items-center gap-1.5">
                                        @csrf
                                        <input name="pr_no" required maxlength="50" placeholder="Nomor PR"
                                               value="{{ old('pr_no') }}"
                                               class="w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-teal-400">
                                        <button type="submit"
                                                class="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700">
                                            Simpan PR
                                        </button>
                                    </form>
                                    @include('sparepart.request.partials._tombol-hapus', ['row' => $row])
                                </div>
                            @elseif($row->status === \App\Models\SpRequest::STATUS_DITOLAK)
                                @include('sparepart.request.partials._tombol-hapus', ['row' => $row])
                            @else
                                <span class="text-xs text-slate-400">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-400">
                            Tidak ada request pada filter ini.
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
