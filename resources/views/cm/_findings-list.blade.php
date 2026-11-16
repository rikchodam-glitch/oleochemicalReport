@if($findings->count())
    <div class="ap-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="ap-table w-full text-sm">
                <thead>
                    <tr class="bg-[#fafafc] border-b border-black/5">
                        <th class="text-left px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">Equipment</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">Deskripsi</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">Severity</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">PIC</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">Tgl Temuan</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">Hari</th>
                        <th class="text-right px-4 py-2.5 text-xs font-medium text-[#6e6e73] uppercase tracking-wider">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black/5">
                    @foreach($findings as $finding)
                        @php
                            $sevColors = ['low' => 'bg-blue-100 text-blue-700', 'medium' => 'bg-amber-100 text-amber-700', 'high' => 'bg-red-100 text-red-700'];
                            $sevColor = $sevColors[$finding->severity] ?? 'bg-[#e8e8ed] text-[#3a3a3c]';
                            $wo = $finding->workOrder()->first();
                            $tag = $finding->equipment->equipment_tag ?? '-';
                            $equipUrl = route('cm.equipment-show', $tag);
                            $detailUrl = $finding->kode_finding
                                ? route('cm.findings.show', $finding->kode_finding)
                                : $equipUrl;
                            $editUrl = $finding->kode_finding
                                ? route('cm.findings.show', ['kodeFinding' => $finding->kode_finding, 'edit' => 1])
                                : $equipUrl;
                            $pic = trim((string) $finding->pic);
                        @endphp
                        <tr class="hover:bg-[#fafafc] transition-colors cursor-pointer"
                            onclick="window.location.href='{{ $detailUrl }}'"
                            title="Buka detail finding {{ $finding->kode_finding }}">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <a href="{{ $detailUrl }}"
                                       class="shrink-0 w-10 h-10 rounded-lg border border-black/5 overflow-hidden bg-[#f5f5f7] flex items-center justify-center hover:opacity-80 transition-opacity"
                                       title="Buka detail finding {{ $finding->kode_finding }}">
                                        @if($finding->foto_url)
                                            <img src="{{ $finding->foto_url }}" alt="Foto {{ $finding->kode_finding }}" class="w-full h-full object-cover">
                                        @else
                                            <svg class="w-4 h-4 text-[#aeaeb2]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 7h4l2-2h6l2 2h4v12H3V7zm9 3a3.5 3.5 0 100 7 3.5 3.5 0 000-7z"/>
                                            </svg>
                                        @endif
                                    </a>
                                    <div>
                                        <div class="font-mono font-semibold">
                                            <a href="{{ $equipUrl }}"
                                               onclick="event.stopPropagation()"
                                               class="text-[#0066cc] hover:text-[#004a99] hover:underline transition-colors"
                                               title="Buka status dan detail equipment {{ $tag }}">
                                                {{ $tag }}
                                            </a>
                                        </div>
                                        <div class="text-xs text-[#6e6e73]">{{ $finding->equipment->pt_location ?? '' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-[#1d1d1f] max-w-[300px]">
                                <p class="whitespace-normal break-words">{{ $finding->deskripsi ?: '—' }}</p>
                                @if($finding->remark)
                                    <p class="mt-1 text-xs text-[#6e6e73] whitespace-pre-line break-words">
                                        <span class="font-medium">Remark:</span> {{ $finding->remark }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium {{ $sevColor }}">
                                    {{ ucfirst($finding->severity) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($finding->status === 'open')
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        Open
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                        Closed
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($pic !== '')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-[#f5f5f7] text-[#3a3a3c] border border-black/5">
                                        {{ $pic }}
                                    </span>
                                @else
                                    <span class="text-xs text-[#aeaeb2]">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-[#6e6e73] text-xs">
                                {{ $finding->tanggal_temuan ? $finding->tanggal_temuan->format('d M Y') : '—' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($finding->status === 'open')
                                    <span class="text-xs {{ $finding->hari_open > 7 ? 'text-red-500 font-medium' : 'text-[#6e6e73]' }}">
                                        {{ $finding->hari_open }} hari
                                    </span>
                                @else
                                    <span class="text-xs text-[#6e6e73]">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right" onclick="event.stopPropagation()">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ $editUrl }}"
                                       class="inline-block px-2.5 py-1 text-xs font-medium text-blue-600 hover:bg-blue-50 rounded-md transition-colors">
                                        Edit
                                    </a>
                                    @if($wo)
                                        <a href="{{ route('work-orders.show', $wo) }}"
                                           class="inline-block px-2.5 py-1 text-xs font-medium text-[#0066cc] hover:bg-[#f5f5f7] rounded-md transition-colors">
                                            Lihat WO
                                        </a>
                                    @elseif($finding->status === 'open')
                                        <a href="{{ route('work-orders.create', ['equipment_tag' => $tag, 'linked_finding_id' => $finding->id]) }}"
                                           class="inline-block px-2.5 py-1 text-xs font-medium text-amber-600 hover:bg-amber-50 rounded-md transition-colors">
                                            + WO
                                        </a>
                                    @endif
                                    <form method="POST" action="{{ route('cm.findings.destroy', $finding) }}" class="inline"
                                          onsubmit="return confirm('Hapus finding {{ $finding->kode_finding }}? Data tidak bisa dikembalikan.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-1 text-red-500 hover:text-red-700 transition-colors" title="Hapus finding {{ $finding->kode_finding }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="ap-card p-8 text-center text-sm text-[#6e6e73]">
        Tidak ada finding ditemukan.
    </div>
@endif

@if(method_exists($findings, 'links'))
    <div class="mt-4">
        {{ $findings->links() }}
    </div>
@endif
