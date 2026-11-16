@extends('layouts.app')

@section('title', $finding->kode_finding . ' — Detail Finding')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-slate-700">Condition Monitoring</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.findings') }}" class="hover:text-slate-700">Finding CM</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $finding->kode_finding }}</span>
@endsection

@section('content')
    @include('cm._tabs')

    @php
        $equipment = $finding->equipment;
        $reading   = $finding->reading;
        $wo        = $finding->workOrder;

        $sev = strtolower(trim((string) $finding->severity));
        $sevMeta = [
            'high'   => ['label' => 'High', 'badge' => 'bg-red-100 text-red-700', 'dot' => 'bg-red-500'],
            'medium' => ['label' => 'Medium', 'badge' => 'bg-amber-100 text-amber-700', 'dot' => 'bg-amber-500'],
            'low'    => ['label' => 'Low', 'badge' => 'bg-blue-100 text-blue-700', 'dot' => 'bg-blue-500'],
        ][$sev] ?? ['label' => $sev !== '' ? ucfirst($sev) : '—', 'badge' => 'bg-slate-100 text-slate-600', 'dot' => 'bg-slate-400'];

        $isOpen = $finding->status === 'open';
        $pic = trim((string) $finding->pic);
        $equipmentUrl = $equipment ? route('cm.equipment-show', $equipment->equipment_tag) : null;

        $kondisiMeta = [
            'good'       => ['label' => 'Normal', 'badge' => 'bg-green-100 text-green-700'],
            'alarm'      => ['label' => 'Alarm', 'badge' => 'bg-amber-100 text-amber-700'],
            'danger'     => ['label' => 'Danger', 'badge' => 'bg-red-100 text-red-700'],
            'visual_bad' => ['label' => 'Visual Bad', 'badge' => 'bg-blue-100 text-blue-700'],
        ];
        $kondisi = $reading ? ($kondisiMeta[strtolower((string) $reading->kondisi)] ?? null) : null;

        // Foto bisa tersimpan di kolom tunggal lama (foto_url) dan/atau di
        // kolom JSON (foto_urls). Gabungkan tanpa duplikat.
        $fotoUrls = $finding->foto_urls;
        if (is_string($fotoUrls)) {
            $fotoUrls = json_decode($fotoUrls, true) ?: [];
        }
        $allPhotos = [];
        if ($finding->foto_url) {
            $allPhotos[] = $finding->foto_url;
        }
        if (is_array($fotoUrls)) {
            foreach ($fotoUrls as $url) {
                if ($url && !in_array($url, $allPhotos, true)) {
                    $allPhotos[] = $url;
                }
            }
        }
        $photoCount = count($allPhotos);

        $editPayload = [
            'update_url'     => route('cm.findings.update', $finding),
            'kode'           => $finding->kode_finding,
            'severity'       => $sev,
            'kategori'       => (string) $finding->kategori,
            'deskripsi'      => (string) $finding->deskripsi,
            'analysis'       => (string) $finding->analysis,
            'action'         => (string) $finding->action,
            'remark'         => (string) $finding->remark,
            'status'         => (string) $finding->status,
            'pic'            => (string) $finding->pic,
            'tanggal_temuan' => $finding->tanggal_temuan?->format('Y-m-d') ?? '',
            'date_action'    => $finding->date_action?->format('Y-m-d') ?? '',
        ];

        $remarkFinding = trim((string) ($finding->remark ?? ''));
        $remarkReading = trim((string) ($reading?->remark ?? ''));
    @endphp

    {{-- Header Finding --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
            <div class="min-w-0">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h2 class="text-2xl font-bold font-mono" style="color: #0E9E8E;">{{ $finding->kode_finding }}</h2>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 text-xs font-medium rounded-full {{ $sevMeta['badge'] }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $sevMeta['dot'] }}"></span>
                        {{ $sevMeta['label'] }}
                    </span>
                    @if($isOpen)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                            Open
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                            Closed
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        PIC: {{ $pic !== '' ? $pic : 'Belum ditentukan' }}
                    </span>
                </div>
                <p class="text-sm text-slate-500 mt-2">
                    {{ $finding->kategori ?: 'Tanpa kategori' }}
                    @if($equipment)
                        &middot;
                        <a href="{{ $equipmentUrl }}"
                           class="font-mono font-semibold text-teal-600 hover:text-teal-800 hover:underline">
                            {{ $equipment->equipment_tag }}
                        </a>
                        &middot; {{ $equipment->pt_location }}
                    @endif
                </p>
                <p class="text-xs text-slate-400 mt-1">
                    Dibuat {{ $finding->created_at?->format('d M Y H:i') ?? '—' }}
                    @if($finding->updated_at && $finding->updated_at->ne($finding->created_at))
                        &middot; diperbarui {{ $finding->updated_at->format('d M Y H:i') }}
                    @endif
                </p>
            </div>
            <div class="shrink-0 flex flex-wrap items-center gap-2">
                <button type="button"
                        onclick="bukaEditFinding(this)"
                        data-finding-edit
                        data-finding="{{ json_encode($editPayload, JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    Edit Finding
                </button>
                @if($equipmentUrl)
                    <a href="{{ $equipmentUrl }}"
                       class="px-4 py-2 border border-teal-300 hover:bg-teal-50 text-teal-700 text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h10"/>
                        </svg>
                        Status Equipment
                    </a>
                @endif
                <a href="{{ route('cm.findings') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Kembali
                </a>
                @if(!$wo && $isOpen)
                    <a href="{{ route('work-orders.create', ['equipment_tag' => $equipment?->equipment_tag, 'linked_finding_id' => $finding->id]) }}"
                       class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        + Work Order
                    </a>
                @elseif($wo)
                    <a href="{{ route('work-orders.show', $wo) }}"
                       class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Lihat WO
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">PIC / Tim</p>
            <p class="mt-1.5 text-base font-semibold text-slate-900">{{ $pic !== '' ? $pic : 'Belum ditentukan' }}</p>
            <p class="mt-1 text-xs text-slate-400">Sesuai sheet Finding CM</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Tanggal Temuan</p>
            <p class="mt-1.5 text-base font-semibold text-slate-900">{{ $finding->tanggal_temuan?->format('d M Y') ?? '—' }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ $finding->tanggal_temuan?->translatedFormat('l') ?? '' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Hari Open</p>
            <p class="mt-1.5 text-base font-semibold {{ $isOpen && $finding->hari_open > 7 ? 'text-red-600' : 'text-slate-900' }}">
                {{ $isOpen ? ($finding->hari_open . ' hari') : '—' }}
            </p>
            <p class="mt-1 text-xs text-slate-400">{{ $isOpen ? 'Sejak tanggal temuan' : 'Finding sudah ditutup' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Tanggal Tindakan</p>
            <p class="mt-1.5 text-base font-semibold text-slate-900">{{ $finding->date_action?->format('d M Y') ?? '—' }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ $finding->date_action ? $finding->date_action->translatedFormat('l') : 'Belum ada tindakan' }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Kolom kiri: informasi --}}
        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-4">Informasi Finding</h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Kategori</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ $finding->kategori ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">PIC</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ $pic !== '' ? $pic : 'Belum ditentukan' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Status</dt>
                        <dd class="mt-0.5">
                            @if($isOpen)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Open
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Closed
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Kode Finding</dt>
                        <dd class="text-sm font-mono text-slate-700">{{ $finding->kode_finding ?: '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-4">Equipment</h3>
                @if($equipment)
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Tag</dt>
                            <dd class="text-sm font-mono font-semibold">
                                <a href="{{ $equipmentUrl }}" class="text-teal-600 hover:text-teal-800 hover:underline">{{ $equipment->equipment_tag }}</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Nama Equipment</dt>
                            <dd class="text-sm text-slate-800">{{ $equipment->asset?->description ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Model</dt>
                            <dd class="text-sm text-slate-800">{{ $equipment->asset?->model_number ?: '—' }}</dd>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">PT</dt>
                                <dd class="text-sm text-slate-800">{{ $equipment->pt_location ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Plant</dt>
                                <dd class="text-sm text-slate-800">{{ $equipment->plant ?: '—' }}</dd>
                            </div>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Tipe Lubrikasi</dt>
                            <dd class="text-sm text-slate-800">{{ $equipment->tipe_lubrikasi ?: '—' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-slate-400">Equipment sudah dihapus.</p>
                @endif
            </div>

            @if($reading)
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="text-sm font-semibold text-slate-900 mb-4">Konteks Pembacaan</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Tanggal</dt>
                            <dd class="text-sm text-slate-800">{{ $reading->tanggal?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Kondisi</dt>
                            <dd class="mt-0.5">
                                @if($kondisi)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $kondisi['badge'] }}">{{ $kondisi['label'] }}</span>
                                @else
                                    <span class="text-sm text-slate-800">{{ $reading->kondisi ?: '—' }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Vibrasi Maks</dt>
                                <dd class="text-sm font-mono text-slate-800">{{ $reading->max_vibration ? $reading->max_vibration . ' mm/s' : '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Temp Maks</dt>
                                <dd class="text-sm font-mono text-slate-800">{{ $reading->max_temp ? $reading->max_temp . ' °C' : '—' }}</dd>
                            </div>
                        </div>
                    </dl>
                </div>
            @endif
        </div>

        {{-- Kolom kanan: uraian --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Deskripsi</h3>
                <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line">{{ $finding->deskripsi ?: '—' }}</p>
            </div>

            @if(trim((string) $finding->analysis) !== '')
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="text-sm font-semibold text-slate-900 mb-3">Analisis</h3>
                    <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line">{{ $finding->analysis }}</p>
                </div>
            @endif

            @if(trim((string) $finding->action) !== '')
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="text-sm font-semibold text-slate-900 mb-3">Tindakan</h3>
                    <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line">{{ $finding->action }}</p>
                </div>
            @endif

            @if($remarkFinding !== '' || $remarkReading !== '')
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h3 class="text-sm font-semibold text-slate-900 mb-3">Remark</h3>
                    @if($remarkFinding !== '')
                        <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line">{{ $remarkFinding }}</p>
                    @endif
                    @if($remarkReading !== '' && $remarkReading !== $remarkFinding)
                        <div class="{{ $remarkFinding !== '' ? 'mt-3 pt-3 border-t border-slate-100' : '' }}">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400 mb-1">Dari pembacaan</p>
                            <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line">{{ $remarkReading }}</p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Foto --}}
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-slate-900">
                        Foto
                        @if($photoCount > 0)
                            <span class="text-xs font-normal text-slate-400">({{ $photoCount }} foto)</span>
                        @endif
                    </h3>
                </div>
                @if($photoCount > 0)
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        @foreach($allPhotos as $idx => $photoUrl)
                            <div class="relative group rounded-lg border border-slate-200 overflow-hidden bg-slate-50">
                                <img src="{{ $photoUrl }}"
                                     alt="Foto Finding {{ $idx + 1 }}"
                                     class="w-full aspect-square object-cover cursor-pointer hover:opacity-90 transition-opacity"
                                     loading="lazy"
                                     onclick="openModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')">
                                @if($photoCount > 1)
                                    <div class="absolute top-1.5 left-1.5 bg-black/50 text-white text-[10px] font-medium px-1.5 py-0.5 rounded">
                                        {{ $idx + 1 }}/{{ $photoCount }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center">
                        <svg class="w-8 h-8 mx-auto text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 7h4l2-2h6l2 2h4v12H3V7zm9 3a3.5 3.5 0 100 7 3.5 3.5 0 000-7z"/>
                        </svg>
                        <p class="mt-2 text-sm text-slate-500">Belum ada foto untuk finding ini.</p>
                        <p class="mt-1 text-xs text-slate-400">Foto dapat diisi dari sheet Finding CM saat sinkronisasi.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Modal untuk setiap foto --}}
    @foreach($allPhotos as $idx => $photoUrl)
        <x-modal name="findingDetailPhoto{{ $finding->id }}_{{ $idx }}"
                 title="Foto Finding — {{ $finding->kode_finding }} ({{ $idx + 1 }}/{{ $photoCount }})"
                 maxWidth="lg">
            <div class="space-y-4">
                <img src="{{ $photoUrl }}" alt="Foto Finding {{ $idx + 1 }}" class="w-full rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="text-xs text-slate-400">
                        @if($idx > 0)
                            <button onclick="openModal('findingDetailPhoto{{ $finding->id }}_{{ $idx - 1 }}'); closeModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')"
                                    class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                Sebelumnya
                            </button>
                        @endif
                        @if($idx < $photoCount - 1)
                            <button onclick="openModal('findingDetailPhoto{{ $finding->id }}_{{ $idx + 1 }}'); closeModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')"
                                    class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1">
                                Selanjutnya
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        @endif
                    </div>
                    <button onclick="closeModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')"
                            class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Tutup
                    </button>
                </div>
            </div>
        </x-modal>
    @endforeach

    {{-- Linked Work Order --}}
    @if($wo)
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Work Order Terkait</h3>
            </div>
            <div class="p-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <a href="{{ route('work-orders.show', $wo) }}" class="font-mono font-semibold text-teal-600 hover:text-teal-800 hover:underline">
                            {{ $wo->wo_number ?? '#' . $wo->id }}
                        </a>
                        <p class="text-xs text-slate-500 mt-0.5">{{ $wo->jenis_pekerjaan ?? '—' }}</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium rounded-full
                        {{ $wo->status === 'open' ? 'bg-amber-100 text-amber-700' : ($wo->status === 'closed' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700') }}">
                        {{ ucfirst($wo->status ?? '—') }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    @include('cm._modal-edit-finding', ['redirectTo' => 'finding'])

    @push('scripts')
    <script>
    /**
     * Buka modal edit finding dan isi seluruh field dari data tombol.
     *
     * @param {HTMLElement} button Tombol Edit dengan atribut data-finding (JSON).
     */
    function bukaEditFinding(button) {
        if (!button) return;

        var data = {};
        try {
            data = JSON.parse(button.dataset.finding || '{}');
        } catch (error) {
            return;
        }

        var modal = document.getElementById('editFindingModal');
        var form = document.getElementById('editFindingForm');
        if (!modal || !form) return;

        form.action = data.update_url || '#';

        var isi = function (id, nilai) {
            var field = document.getElementById(id);
            if (field) field.value = nilai || '';
        };

        isi('ef_severity', data.severity || 'low');
        isi('ef_status', data.status || 'open');
        isi('ef_kategori', data.kategori);
        isi('ef_pic', data.pic);
        isi('ef_tanggal_temuan', data.tanggal_temuan);
        isi('ef_date_action', data.date_action);
        isi('ef_deskripsi', data.deskripsi);
        isi('ef_analysis', data.analysis);
        isi('ef_action', data.action);
        isi('ef_remark', data.remark);

        var kode = document.getElementById('ef_kode');
        if (kode) kode.textContent = data.kode || '';

        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        setTimeout(function () {
            var field = document.getElementById('ef_severity');
            if (field) field.focus();
        }, 50);
    }

    /**
     * Tutup modal edit finding dan kembalikan scroll halaman.
     */
    function tutupEditFinding() {
        var modal = document.getElementById('editFindingModal');
        if (modal) modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Dukungan tautan Edit dari halaman daftar: ?edit=1
        var params = new URLSearchParams(window.location.search);
        if (params.get('edit') !== '1') return;

        var tombol = document.querySelector('[data-finding-edit]');
        if (tombol) tombol.click();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') tutupEditFinding();
    });
    </script>
    @endpush
@endsection
