{{--
    Kolom Bagian request: Bagian teknisi dan Group-nya.
    Dipakai dengan $row = App\Models\SpRequest.
--}}
@php
    $teknisi = $row->technician;
    $bagian  = $teknisi?->section
        ? (\App\Models\Technician::SECTIONS[$teknisi->section] ?? ucfirst((string) $teknisi->section))
        : '-';
    $grup    = $teknisi?->group
        ? (\App\Models\Technician::GROUPS[$teknisi->group] ?? ucfirst((string) $teknisi->group))
        : null;
@endphp

<span class="block text-xs font-medium text-slate-700">{{ $bagian }}</span>
@if($grup)
    <span class="block text-[11px] text-slate-400">{{ $grup }}</span>
@endif
