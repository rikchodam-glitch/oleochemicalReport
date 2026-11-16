@forelse($equipment->findings->sortByDesc('tanggal_temuan') as $f)
    @php
        $sev = strtolower(trim((string) $f->severity));
        $metaSeverity = [
            'high' => ['label' => 'High', 'badge' => 'bg-red-100 text-red-700', 'dot' => 'bg-red-500'],
            'medium' => ['label' => 'Medium', 'badge' => 'bg-amber-100 text-amber-800', 'dot' => 'bg-amber-500'],
            'low' => ['label' => 'Low', 'badge' => 'bg-blue-100 text-blue-700', 'dot' => 'bg-blue-500'],
        ][$sev] ?? ['label' => $sev !== '' ? ucfirst($sev) : '—', 'badge' => 'bg-slate-100 text-slate-600', 'dot' => 'bg-slate-400'];

        $isOpen = strtolower(trim((string) $f->status)) === 'open';
        $wo = $f->workOrder;

        $deskripsi = trim((string) $f->deskripsi);
        $analysis = trim((string) $f->analysis);
        $action = trim((string) $f->action);
        $gabunganUraian = trim(implode("\n\n", array_filter([$analysis, $action])));
        $tampilkanDeskripsi = $deskripsi !== ''
            && $deskripsi !== '-'
            && $deskripsi !== $gabunganUraian;

        // Remark finding diutamakan. Bila kosong, tampilkan remark reading
        // yang menjadi induk finding supaya konteks lapangan tidak hilang.
        $remark = trim((string) ($f->remark ?? ''));
        $sumberRemark = 'Remark finding';
        if ($remark === '') {
            $remark = trim((string) ($f->reading?->remark ?? ''));
            $sumberRemark = 'Remark pembacaan';
        }

        $temuanTag = null;
        if ($remark !== '' && preg_match('/\[Temuan:\s*([^\]]+)\]/i', $remark, $cocokTemuan)) {
            $temuanTag = trim($cocokTemuan[1]);
            $remark = trim((string) preg_replace('/\[Temuan:\s*[^\]]+\]/i', '', $remark));
        }

        $payload = [
            'update_url'     => route('cm.findings.update', $f),
            'kode'           => $f->kode_finding,
            'severity'       => $sev,
            'kategori'       => (string) $f->kategori,
            'deskripsi'      => (string) $f->deskripsi,
            'analysis'       => (string) $f->analysis,
            'action'         => (string) $f->action,
            'remark'         => (string) $f->remark,
            'status'         => (string) $f->status,
            'pic'            => (string) $f->pic,
            'tanggal_temuan' => $f->tanggal_temuan?->format('Y-m-d') ?? '',
            'date_action'    => $f->date_action?->format('Y-m-d') ?? '',
        ];
    @endphp
    <tr id="finding-{{ $f->id }}" class="hover:bg-slate-50/70 transition-colors align-top">
        <td class="px-4 py-4 whitespace-nowrap">
            <a href="{{ route('cm.findings.show', $f->kode_finding) }}"
               class="font-mono text-xs font-semibold text-teal-700 hover:text-teal-900 hover:underline transition-colors">
                {{ $f->kode_finding }}
            </a>
            <span class="block mt-1 text-[11px] text-slate-400">
                {{ $f->tanggal_temuan ? $f->tanggal_temuan->format('d M Y') : 'Tanggal belum diisi' }}
            </span>
        </td>
        <td class="px-4 py-4">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 text-xs font-medium rounded-full {{ $metaSeverity['badge'] }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $metaSeverity['dot'] }}"></span>
                {{ $metaSeverity['label'] }}
            </span>
        </td>
        <td class="px-4 py-4 text-xs text-slate-700">
            <span class="block max-w-[160px] whitespace-normal break-words">{{ $f->kategori ?: '—' }}</span>
        </td>
        <td class="px-4 py-4 text-xs text-slate-700">
            <div class="space-y-2 max-w-[420px]">
                @if($tampilkanDeskripsi)
                    <p class="leading-relaxed whitespace-pre-line break-words">
                        <span class="font-medium text-slate-500">Deskripsi:</span>
                        {{ $deskripsi }}
                    </p>
                @endif
                @if($analysis !== '')
                    <p class="leading-relaxed whitespace-pre-line break-words">
                        <span class="font-medium text-slate-500">Analisa:</span>
                        {{ $analysis }}
                    </p>
                @endif
                @if($action !== '')
                    <p class="leading-relaxed whitespace-pre-line break-words">
                        <span class="font-medium text-slate-500">Tindakan:</span>
                        {{ $action }}
                    </p>
                @endif
                @if(! $tampilkanDeskripsi && $analysis === '' && $action === '')
                    <span class="text-slate-400">Belum ada uraian. Klik Edit untuk menambahkan analisa atau tindakan.</span>
                @endif
            </div>
        </td>
        <td class="px-4 py-4 min-w-[220px] max-w-[320px]">
            @if($remark !== '' || $temuanTag !== null)
                @if($remark !== '')
                    <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line break-words">{{ $remark }}</p>
                @endif
                @if($temuanTag !== null)
                    <span class="mt-1 inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">
                        Temuan: {{ $temuanTag }}
                    </span>
                @endif
                <span class="mt-1 block text-[10px] text-slate-400">{{ $sumberRemark }}</span>
            @else
                <span class="text-slate-400">Belum ada remark.</span>
                <span class="mt-0.5 block text-[10px] text-slate-400">Gunakan tombol Edit untuk menambahkan.</span>
            @endif
        </td>

        <td class="px-4 py-4">
            @if($isOpen)
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-700">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    Open
                </span>
            @else
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                    Closed
                </span>
            @endif
            <p class="mt-1 text-[11px] text-slate-500 whitespace-nowrap">PIC: {{ $f->pic !== null && $f->pic !== '' ? $f->pic : '—' }}</p>
        </td>
        <td class="px-4 py-4 text-right whitespace-nowrap">
            @if($isOpen)
                <span class="font-mono font-semibold {{ ($f->hari_open ?? 0) > 7 ? 'text-red-600' : 'text-slate-700' }}">{{ $f->hari_open ?? 0 }} hari</span>
                @if(($f->hari_open ?? 0) > 7)
                    <span class="block text-[10px] text-red-500">di atas 7 hari</span>
                @endif
            @else
                <span class="text-slate-600">{{ $f->date_action?->format('d M Y') ?? '—' }}</span>
                <span class="block text-[10px] text-slate-400">tanggal tindakan</span>
            @endif
        </td>
        <td class="px-4 py-4">
            <div class="flex flex-wrap items-center justify-center gap-1">
                <a href="{{ route('cm.findings.show', $f->kode_finding) }}"
                   class="inline-flex items-center rounded-md px-2 py-1 text-[11px] font-medium text-teal-700 hover:bg-teal-50 transition-colors"
                   title="Buka detail finding">
                    Detail
                </a>
                <button type="button"
                        data-edit-finding="{{ $f->id }}"
                        data-finding="{{ json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                        onclick="bukaEditFinding(this)"
                        class="inline-flex items-center rounded-md px-2 py-1 text-[11px] font-medium text-blue-700 hover:bg-blue-50 transition-colors"
                        title="Edit finding {{ $f->kode_finding }}">
                    Edit
                </button>
                @if($wo)
                    <a href="{{ route('work-orders.show', $wo) }}"
                       class="inline-flex items-center rounded-md px-2 py-1 text-[11px] font-medium text-slate-600 hover:bg-slate-100 transition-colors"
                       title="Work Order terkait">
                        WO
                    </a>
                @elseif($isOpen)
                    <a href="{{ route('work-orders.create', ['equipment_tag' => $equipment->equipment_tag, 'linked_finding_id' => $f->id]) }}"
                       class="inline-flex items-center rounded-md px-2 py-1 text-[11px] font-medium text-amber-700 hover:bg-amber-50 transition-colors"
                       title="Buat Work Order dari finding ini">
                        +WO
                    </a>
                @endif
                <form method="POST" action="{{ route('cm.findings.destroy', $f) }}" class="inline"
                      onsubmit="return confirm('Hapus finding {{ $f->kode_finding }}? Data tidak bisa dikembalikan.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center rounded-md p-1.5 text-red-500 hover:bg-red-50 hover:text-red-700 transition-colors" title="Hapus finding {{ $f->kode_finding }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </form>
            </div>
        </td>
    </tr>
@empty
    <tr><td colspan="8" class="px-5 py-8 text-center text-slate-400 text-sm">Belum ada temuan untuk equipment ini.</td></tr>
@endforelse

