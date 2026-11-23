@php
    $adaMenunggu = isset($daftarMenunggu) && $daftarMenunggu->isNotEmpty();
    $adaAktif    = $daftarPinjaman->isNotEmpty();
@endphp

<div class="space-y-4">
    @if($adaMenunggu)
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200">
                <h3 class="text-sm font-semibold text-slate-900">Menunggu Diterima</h3>
                <p class="text-xs text-slate-500">
                    {{ $modeKeeper ? 'Periksa fisik alat, lalu tekan Terima.' : 'Pengajuan Anda sedang diperiksa tool keeper.' }}
                </p>
            </div>

            <div class="divide-y divide-slate-100">
                @foreach($daftarMenunggu as $pinjaman)
                    <div class="p-4 flex flex-wrap items-center gap-3">
                        <div class="w-14 h-14 rounded-lg bg-slate-100 overflow-hidden shrink-0">
                            @if($pinjaman->tool?->foto_url)
                                <img src="{{ $pinjaman->tool->foto_url }}" alt="{{ $pinjaman->tool_name }}" class="w-full h-full object-cover">
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $pinjaman->tool?->group?->name ?? $pinjaman->tool_name }}
                                <span class="text-slate-500">({{ $pinjaman->tool_code }})</span>
                            </p>
                            <p class="text-xs text-slate-500">
                                Peminjam: {{ $pinjaman->peminjamLabel() }} ·
                                Diajukan {{ $pinjaman->return_requested_at?->format('d/m/Y H:i') }} ·
                                {{ $pinjaman->durasiTeks() }}
                            </p>
                            <p class="text-[11px] text-amber-700">Menunggu diterima tool keeper.</p>
                        </div>

                        @if($modeKeeper)
                            <form method="POST" action="{{ route('tools.form.kembali', ['token' => $asli, 'toolLoan' => $pinjaman->id]) }}"
                                  onsubmit="return confirm('Terima kembali {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})?')">
                                @csrf
                                <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                                    Terima
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(! $adaAktif && ! $adaMenunggu)
        <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
            <p class="text-sm text-slate-600">
                {{ $modeKeeper ? 'Tidak ada alat yang sedang dipinjam atau menunggu diterima.' : 'Anda tidak punya pinjaman alat yang aktif.' }}
            </p>
        </div>
    @endif

    @if($adaAktif)
        @if(! $modeKeeper)
            <form method="POST" action="{{ route('tools.form.kembali-semua', ['token' => $asli]) }}"
                  class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-center justify-between gap-3"
                  onsubmit="return confirm('Ajukan pengembalian SEMUA alat yang sedang Anda pinjam?')">
                @csrf
                <div>
                    <p class="text-sm font-semibold text-slate-900">Ajukan kembali semua</p>
                    <p class="text-xs text-slate-500">{{ $daftarPinjaman->count() }} alat akan diajukan ke tool keeper sekaligus.</p>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                    Ajukan Semua
                </button>
            </form>
        @endif

        <div class="space-y-3">
            @foreach($daftarPinjaman as $pinjaman)
                <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-center gap-4">
                    <div class="w-16 h-16 rounded-lg bg-slate-100 overflow-hidden shrink-0">
                        @if($pinjaman->tool?->foto_url)
                            <img src="{{ $pinjaman->tool->foto_url }}" alt="{{ $pinjaman->tool_name }}"
                                 class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center text-slate-300">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                            </div>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-slate-900">
                            {{ $pinjaman->tool?->group?->name ?? $pinjaman->tool_name }}
                            <span class="text-slate-500">({{ $pinjaman->tool_code }})</span>
                        </p>
                        <p class="text-xs text-slate-500">
                            Dipinjam {{ $pinjaman->borrowed_at?->format('d/m/Y H:i') }}
                            @if($modeKeeper)
                                oleh {{ $pinjaman->peminjamLabel() }}
                            @endif
                        </p>
                        <p class="text-[11px] text-slate-400">Kode pinjaman: {{ $pinjaman->kode }}</p>
                    </div>

                    <form method="POST" action="{{ route('tools.form.kembali', ['token' => $asli, 'toolLoan' => $pinjaman->id]) }}"
                          onsubmit="return confirm('{{ $modeKeeper ? 'Kembalikan' : 'Ajukan pengembalian' }} {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})?')">
                        @csrf
                        <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                            {{ $modeKeeper ? 'Kembalikan' : 'Ajukan Kembali' }}
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</div>
