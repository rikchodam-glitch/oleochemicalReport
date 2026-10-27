@forelse($readings as $r)
    @php
        $tanggal = \Carbon\Carbon::parse($r->tanggal);
        $kondisi = strtolower(trim((string) $r->kondisi));
        $metaKondisi = [
            'good' => [
                'label' => 'Good',
                'badge' => 'bg-green-100 text-green-700',
                'dot'   => 'bg-green-500',
                'text'  => 'text-green-700',
            ],
            'alarm' => [
                'label' => 'Alarm',
                'badge' => 'bg-amber-100 text-amber-800',
                'dot'   => 'bg-amber-500',
                'text'  => 'text-amber-700',
            ],
            'danger' => [
                'label' => 'Danger',
                'badge' => 'bg-red-100 text-red-700',
                'dot'   => 'bg-red-500',
                'text'  => 'text-red-700',
            ],
            'visual_bad' => [
                'label' => 'Visual bad',
                'badge' => 'bg-purple-100 text-purple-700',
                'dot'   => 'bg-purple-500',
                'text'  => 'text-purple-700',
            ],
        ][$kondisi] ?? [
            'label' => $kondisi !== '' ? ucfirst(str_replace('_', ' ', $kondisi)) : 'Belum diisi',
            'badge' => 'bg-slate-100 text-slate-600',
            'dot'   => 'bg-slate-400',
            'text'  => 'text-slate-600',
        ];

        // Satu grup = satu unit (Motor/Mesin/Screw). Satu baris = sisi
        // pengukuran NDE/DE. Satu poin = sumbu V/H/A.
        $grupVibrasi = [
            ['label' => 'Motor', 'baris' => [
                ['label' => 'NDE', 'poin' => [
                    ['kode' => 'V', 'nilai' => $r->ndev_motor],
                    ['kode' => 'H', 'nilai' => $r->ndeh_motor],
                    ['kode' => 'A', 'nilai' => $r->ndea_motor],
                ]],
                ['label' => 'DE', 'poin' => [
                    ['kode' => 'V', 'nilai' => $r->dev_motor],
                    ['kode' => 'H', 'nilai' => $r->deh_motor],
                    ['kode' => 'A', 'nilai' => $r->dea_motor],
                ]],
            ]],
            ['label' => 'Mesin', 'baris' => [
                ['label' => 'NDE', 'poin' => [
                    ['kode' => 'V', 'nilai' => $r->ndev_pompa],
                    ['kode' => 'H', 'nilai' => $r->ndeh_pompa],
                    ['kode' => 'A', 'nilai' => $r->ndea_pompa],
                ]],
                ['label' => 'DE', 'poin' => [
                    ['kode' => 'V', 'nilai' => $r->dev_pompa],
                    ['kode' => 'H', 'nilai' => $r->deh_pompa],
                    ['kode' => 'A', 'nilai' => $r->dea_pompa],
                ]],
            ]],
        ];

        if ($adaScrew) {
            $grupVibrasi[] = ['label' => 'Screw', 'baris' => [
                ['label' => 'NDE', 'poin' => [
                    ['kode' => 'V', 'nilai' => $r->ndev_screw],
                    ['kode' => 'H', 'nilai' => $r->ndeh_screw],
                    ['kode' => 'A', 'nilai' => $r->ndea_screw],
                ]],
                ['label' => 'DE', 'poin' => [
                    ['kode' => 'V', 'nilai' => $r->dev_screw],
                    ['kode' => 'H', 'nilai' => $r->deh_screw],
                    ['kode' => 'A', 'nilai' => $r->dea_screw],
                ]],
            ]];
        }

        $poinDatar = [];
        foreach ($grupVibrasi as $grup) {
            foreach ($grup['baris'] as $baris) {
                foreach ($baris['poin'] as $poin) {
                    $poinDatar[] = [
                        'grup'   => $grup['label'],
                        'posisi' => $baris['label'],
                        'kode'   => $poin['kode'],
                        'nilai'  => $poin['nilai'] !== null ? (float) $poin['nilai'] : null,
                    ];
                }
            }
        }

        // Nilai maks memakai kolom max_vibration dari sheet bila tersedia;
        // bila kosong dihitung dari seluruh titik ukur.
        $nilaiMaks = $r->max_vibration !== null ? (float) $r->max_vibration : null;
        if ($nilaiMaks === null) {
            $nilaiMaks = collect($poinDatar)->pluck('nilai')->filter(fn ($v) => $v !== null)->max();
            $nilaiMaks = $nilaiMaks !== null ? (float) $nilaiMaks : null;
        }

        $labelMaks = null;
        if ($nilaiMaks !== null) {
            foreach ($poinDatar as $poin) {
                if ($poin['nilai'] !== null && abs($poin['nilai'] - $nilaiMaks) < 0.011) {
                    $labelMaks = $poin['grup'] . ' ' . $poin['posisi'] . '-' . $poin['kode'];
                    break;
                }
            }
        }

        $tampilkanMaks = $nilaiMaks !== null
            && $nilaiMaks > 0
            && in_array($kondisi, ['alarm', 'danger'], true);
        $kelasNilaiMaks = $tampilkanMaks
            ? $metaKondisi['badge'] . ' font-semibold'
            : 'font-semibold text-slate-900';

        $isRunning = filled($r->status)
            && strtoupper(trim((string) $r->status)) === 'START';
        $tempMotor = collect([$r->temp_de_motor, $r->temp_nde_motor])
            ->filter(fn ($t) => $t !== null)
            ->max();
        $tempMesin = collect([$r->temp_de_pompa, $r->temp_nde_pompa])
            ->filter(fn ($t) => $t !== null)
            ->max();

        $remarkTeks = trim((string) ($r->remark ?? ''));
        $temuanTag = null;
        if ($remarkTeks !== '' && preg_match('/\[Temuan:\s*([^\]]+)\]/i', $remarkTeks, $cocokTemuan)) {
            $temuanTag = trim($cocokTemuan[1]);
            $remarkTeks = trim((string) preg_replace('/\[Temuan:\s*[^\]]+\]/i', '', $remarkTeks));
        }

        $delDate = $tanggal->format('d/m/Y');
    @endphp
    <tr class="hover:bg-slate-50/70 transition-colors align-top">
        <td class="px-4 py-4 text-xs text-slate-700 whitespace-nowrap">
            <span class="block font-medium">{{ $tanggal->format('d M Y') }}</span>
            <span class="block text-[11px] text-slate-400">{{ $tanggal->format('D') }}</span>
        </td>
        <td class="px-4 py-4">
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-medium rounded-full {{ $metaKondisi['badge'] }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $metaKondisi['dot'] }}"></span>
                {{ $metaKondisi['label'] }}
            </span>
            @if($nilaiMaks !== null && $nilaiMaks > 0)
                <p class="mt-1.5 text-[11px] text-slate-500 whitespace-nowrap">
                    Maks
                    <span class="font-mono font-semibold {{ $tampilkanMaks ? $metaKondisi['text'] : 'text-slate-700' }}">{{ number_format($nilaiMaks, 1) }}</span>
                    mm/s
                </p>
                @if($tampilkanMaks && $labelMaks)
                    <p class="text-[11px] font-medium {{ $metaKondisi['text'] }}">{{ $labelMaks }}</p>
                @endif
            @endif
        </td>

        <td class="px-4 py-4 text-center">
            @if(filled($r->status))
                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full {{ $isRunning ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600' }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $isRunning ? 'bg-green-500' : 'bg-slate-400' }}"></span>
                    {{ trim((string) $r->status) }}
                </span>
            @else
                <span class="text-slate-300">—</span>
            @endif
        </td>
        @foreach($grupVibrasi as $grup)
            <td class="px-4 py-4">
                <div class="space-y-1">
                    @foreach($grup['baris'] as $baris)
                        <div class="flex items-center gap-1 whitespace-nowrap">
                            <span class="w-8 shrink-0 text-[10px] uppercase tracking-wide text-slate-400">{{ $baris['label'] }}</span>
                            @foreach($baris['poin'] as $poin)
                                @php
                                    $nilaiPoin = $poin['nilai'] !== null ? (float) $poin['nilai'] : null;
                                    $poinMaks = $tampilkanMaks
                                        && $nilaiPoin !== null
                                        && abs($nilaiPoin - $nilaiMaks) < 0.011;
                                @endphp
                                <span class="inline-flex items-baseline gap-0.5 rounded px-1 py-0.5 font-mono text-xs {{ $poinMaks ? $kelasNilaiMaks : 'text-slate-600' }}"
                                      title="{{ $grup['label'] }} {{ $baris['label'] }}-{{ $poin['kode'] }}: {{ $nilaiPoin !== null ? number_format($nilaiPoin, 1) : 'belum diisi' }} mm/s">
                                    <span class="text-[10px] font-sans {{ $poinMaks ? '' : 'text-slate-400' }}">{{ $poin['kode'] }}</span>
                                    {{ $nilaiPoin !== null ? number_format($nilaiPoin, 1) : '—' }}
                                </span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </td>
        @endforeach
        <td class="px-4 py-4 text-xs text-slate-700 whitespace-nowrap">
            @if($tempMotor !== null || $tempMesin !== null)
                <span class="block font-mono"><span class="font-sans text-[10px] text-slate-400">Mtr</span> {{ $tempMotor !== null ? number_format((float) $tempMotor, 1) : '—' }}</span>
                <span class="block font-mono"><span class="font-sans text-[10px] text-slate-400">Msn</span> {{ $tempMesin !== null ? number_format((float) $tempMesin, 1) : '—' }}</span>
            @else
                <span class="text-slate-300">—</span>
            @endif
        </td>

        <td class="px-4 py-4 text-right font-mono text-xs text-slate-700">
            {{ $r->ampere !== null ? number_format((float) $r->ampere, 1) : '—' }}
            @if($r->ampere !== null)<span class="text-[10px] text-slate-400">A</span>@endif
        </td>
        <td class="px-4 py-4 text-right font-mono text-xs text-slate-700">
            {{ $r->discharge_pressure !== null ? number_format((float) $r->discharge_pressure, 2) : '—' }}
        </td>
        <td class="px-4 py-4 min-w-[240px] max-w-[360px]">
            @if($remarkTeks !== '' || $temuanTag !== null)
                @if($remarkTeks !== '')
                    <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line break-words">{{ $remarkTeks }}</p>
                @endif
                @if($temuanTag !== null)
                    <span class="mt-1 inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">
                        Temuan: {{ $temuanTag }}
                    </span>
                @endif
            @else
                <span class="text-slate-300">—</span>
            @endif
        </td>
        <td class="px-4 py-4 text-center whitespace-nowrap">
            <form method="POST" action="{{ route('cm.readings.destroy', $r) }}" class="inline"
                  onsubmit="return confirm('Hapus reading tanggal {{ $delDate }}? Data tidak bisa dikembalikan.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-red-500 hover:text-red-700 transition-colors" title="Hapus reading tanggal {{ $delDate }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </form>
        </td>
    </tr>
@empty
    <tr><td colspan="{{ $adaScrew ? 11 : 10 }}" class="px-5 py-8 text-center text-slate-400 text-sm">Belum ada data pembacaan untuk equipment ini.</td></tr>
@endforelse

