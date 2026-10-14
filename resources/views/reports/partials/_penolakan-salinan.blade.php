{{-- Popup penolakan untuk baris SALINAN laporan (kolaborasi / alat tambahan).

     Baris salinan tidak boleh diedit atau dihapus sendiri-sendiri: seluruh
     perubahan pekerjaan dikerjakan dari laporan INDUK, lalu disebarkan ke semua
     baris salinannya (ReportCopyService::sinkronkanSalinan()). Tombol Edit dan
     Hapus pada halaman baris salinan membuka popup ini supaya admin tahu ke
     mana harus pergi, dan penegakan yang sebenarnya ada di sisi server
     (ReportController::penjagaKelolaInduk()).

     Parameter: $report (baris salinan yang dibuka), $laporanInduk (induknya).
     Dipanggil SATU baris dari show.blade.php, di dalam komponen Alpine
     reportDetail() (state penolakan / bukaPenolakan() / tutupPenolakan()). --}}
<template x-teleport="body">
    <div x-show="penolakan" x-cloak
         @click.self="tutupPenolakan()"
         @keydown.escape.window="tutupPenolakan()"
         class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4">
        <div class="mx-auto w-full max-w-lg my-16 bg-white rounded-xl border border-slate-200 p-6 space-y-4">
            <div class="flex items-start gap-3">
                <span class="shrink-0 inline-flex w-9 h-9 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <h3 class="font-medium text-slate-900">Laporan ini tidak bisa diedit atau dihapus</h3>
                    <p class="mt-1 text-sm text-slate-600">
                        Ini baris salinan
                        ({{ $report->collaborator_of !== null ? 'kolaborasi teknisi' : 'alat/lokasi tambahan' }})
                        dari laporan induk
                        <span class="font-medium font-mono text-slate-800">{{ $laporanInduk->report_code ?? ('#' . $laporanInduk->id) }}</span>.
                        Edit dan hapus hanya dilakukan dari laporan INDUK. Perubahan di laporan induk
                        otomatis terpasang pada seluruh baris salinannya.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                <button type="button" @click="tutupPenolakan()"
                        class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Tutup
                </button>
                <a href="{{ route('reports.show', $laporanInduk) }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Cek Laporan Induk
                </a>
            </div>
        </div>
    </div>
</template>
