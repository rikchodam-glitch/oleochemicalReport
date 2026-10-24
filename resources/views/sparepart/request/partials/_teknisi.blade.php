{{--
    Identitas teknisi pemohon pada tabel request.
    Dipakai dengan $row = App\Models\SpRequest.
--}}
@php
    $teknisi = $row->technician;
    $grup    = $teknisi?->group
        ? (\App\Models\Technician::GROUPS[$teknisi->group] ?? ucfirst((string) $teknisi->group))
        : null;
    $bagian  = $teknisi?->section
        ? (\App\Models\Technician::SECTIONS[$teknisi->section] ?? ucfirst((string) $teknisi->section))
        : null;
@endphp

<span class="block text-xs font-medium text-slate-800">{{ $teknisi?->name ?? 'Teknisi tidak dikenal' }}</span>
@if($teknisi?->nik)
    <span class="block text-[11px] text-slate-400 font-mono">{{ $teknisi->nik }}</span>
@endif
@if($grup || $bagian)
    <span class="block text-[11px] text-slate-400">
        {{ collect([$bagian, $grup])->filter()->implode(' - ') }}
    </span>
@endif
