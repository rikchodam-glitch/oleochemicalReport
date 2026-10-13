{{-- Tampilan jam kerja MODE LIHAT pada halaman detail laporan.

     Angka durasi dihitung ULANG dari jam laporan memakai aturan server
     (JamKerjaService::ringkasJamKerja()) -- bukan dibaca dari kolom durasi
     tersimpan -- supaya laporan lama yang kolom durasinya masih memuat jam
     istirahat atau jam di luar jam kerja tidak terhitung dua kali bersama
     lembur (mis. laporan 6 jam pada hari libur tidak tampil 12 jam).

     Lembur hanya ditampilkan bila ada; jam istirahat bukan lembur (keputusan
     user 13/10/2026), menitnya sudah terpotong dari durasi.

     Parameter: $report = model Report yang sedang dibuka.

     Dipisah jadi partial supaya blade halaman detail yang besar tidak perlu
     ditulis ulang; pemanggilnya cukup satu baris @include. --}}

@php
    $layananJamLihat = app(\App\Services\Shift\JamKerjaService::class);
    $tanggalJamLihat = $report->report_date ?? \Illuminate\Support\Carbon::now();

    $ringkasJamLihat = $layananJamLihat->ringkasJamKerja(
        $layananJamLihat->jadwalHari($report->technician, $tanggalJamLihat),
        $report->technician?->group,
        $tanggalJamLihat,
        $report->start_time,
        $report->end_time
    );

    // Label menit dengan format yang sama seperti Report::duration_label.
    $labelMenitLihat = function (int $menit): string {
        $jam   = intdiv($menit, 60);
        $sisa  = $menit % 60;
        $label = [];

        if ($jam > 0) {
            $label[] = $jam . ' Jam';
        }

        if ($sisa > 0) {
            $label[] = $sisa . ' Menit';
        }

        return implode(' ', $label) ?: '0 Menit';
    };

    $durasiLihat = $ringkasJamLihat['normal'] !== null
        ? $labelMenitLihat((int) $ringkasJamLihat['normal'])
        : $report->duration_label;
    $lemburLihat = $ringkasJamLihat['normal'] !== null ? (int) $ringkasJamLihat['lembur'] : 0;
@endphp

<div x-show="!editMode" class="flex flex-wrap items-center gap-1.5 text-slate-600">
    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    @if($report->time_range)
        <span class="font-medium text-slate-700">{{ $report->time_range }}</span>
        <span class="text-slate-300">&middot;</span>
    @endif

    <span class="font-medium text-slate-700">{{ $durasiLihat ?? '-' }}</span>

    @if($lemburLihat > 0)
        <span class="text-slate-300">&middot;</span>
        <span class="text-amber-600">lembur {{ $labelMenitLihat($lemburLihat) }} (di luar jam kerja)</span>
    @endif
</div>
