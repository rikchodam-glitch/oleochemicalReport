{{--
    Identitas teknisi pemohon pada tabel request.
    Dipakai dengan $row = App\Models\SpRequest.
--}}
@php
    $teknisi = $row->technician;
@endphp

<span class="block text-xs font-medium text-slate-800">{{ $teknisi?->name ?? 'Teknisi tidak dikenal' }}</span>
@if($teknisi?->nik)
    <span class="block text-[11px] text-slate-400 font-mono">{{ $teknisi->nik }}</span>
@endif
