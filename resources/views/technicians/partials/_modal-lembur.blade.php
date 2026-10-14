{{-- Popup "Cek Overtime" halaman profil teknisi: rincian lembur per tanggal
     beserta laporan penyebabnya, supaya angka lembur bisa ditelusuri tanpa
     membuka halaman laporan satu per satu.

     Parameter:
       $lemburRincian      = hasil ManHourService::lemburPerTanggal()
       $technician         = teknisi yang sedang dibuka
       $labelPeriodeManHour = label periode man hour (mis. "26 Sep - 25 Okt 2026") --}}

@php
    $totalLembur = collect($lemburRincian)->sum('menit');
@endphp

<div id="modal_lembur" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-slate-900/50" onclick="tutupModalLembur()"></div>

    <div class="relative mx-auto my-8 w-full max-w-3xl rounded-xl bg-white shadow-xl">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div>
                <h3 class="font-medium text-slate-900">Cek Overtime — {{ $technician->name }}</h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    Periode {{ $labelPeriodeManHour }}. Lembur adalah jam kerja di luar jam kerja terjadwal
                    (sebelum jam masuk dan sesudah jam pulang) serta seluruh jam pada hari libur/OFF.
                    Jam istirahat tidak dihitung lembur: menitnya sudah dipotong dari durasi man hour.
                    Total {{ number_format($totalLembur / 60, 1, ',', '.') }} jam dari {{ count($lemburRincian) }} tanggal.
                </p>
            </div>
            <button type="button" onclick="tutupModalLembur()"
                    class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-600 text-xs font-medium rounded-lg transition-colors">
                Tutup
            </button>
        </div>

        <div class="max-h-[70vh] overflow-y-auto px-5 py-4 space-y-4">
            @forelse($lemburRincian as $tanggal => $baris)
                <div class="rounded-lg border border-slate-200 overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2">
                        <p class="text-sm font-medium text-slate-800">
                            {{ \Illuminate\Support\Carbon::parse($tanggal)->locale('id')->translatedFormat('d F Y') }}
                            <span class="text-xs font-normal text-slate-400">
                                {{ \Illuminate\Support\Carbon::parse($tanggal)->locale('id')->translatedFormat('l') }}
                                &middot; {{ $baris['label_shift'] }}
                            </span>
                        </p>
                        <span class="text-xs font-semibold text-amber-600">
                            Lembur {{ number_format($baris['menit'] / 60, 1, ',', '.') }} j
                        </span>
                    </div>
                    <ul class="divide-y divide-slate-50">
                        @foreach($baris['laporan'] as $laporan)
                            <li class="px-4 py-2 flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a href="{{ route('reports.show', $laporan['id']) }}"
                                       class="text-sm text-teal-700 hover:underline font-medium">
                                        {{ $laporan['kode'] }}
                                    </a>
                                    <p class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($laporan['deskripsi'] ?? '-', 90) }}</p>
                                    <p class="text-xs text-slate-400">
                                        Jam {{ $laporan['mulai'] ?? '-' }} - {{ $laporan['selesai'] ?? '-' }}
                                        &middot; jam kerja {{ number_format(($laporan['menit'] ?? 0) / 60, 1, ',', '.') }} j
                                    </p>
                                </div>
                                <span class="text-xs font-semibold text-amber-600 whitespace-nowrap">
                                    lembur {{ number_format($laporan['lembur'] / 60, 1, ',', '.') }} j
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-slate-400">Tidak ada lembur pada periode ini.</p>
            @endforelse
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Popup "Cek Overtime" pada kartu Man Hour profil teknisi.
    function bukaModalLembur() {
        var modal = document.getElementById('modal_lembur');

        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    // Tutup popup (tombol Tutup, klik latar, atau tombol Escape).
    function tutupModalLembur() {
        var modal = document.getElementById('modal_lembur');

        if (modal) {
            modal.classList.add('hidden');
        }
    }

    document.addEventListener('keydown', function (kejadian) {
        if (kejadian.key === 'Escape') {
            tutupModalLembur();
        }
    });
</script>
@endpush
