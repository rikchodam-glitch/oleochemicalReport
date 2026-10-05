{{-- Tautan menuju HALAMAN EDIT LENGKAP laporan (rute reports.edit).

     Halaman itu yang memuat pengelolaan kolaborator teknisi, alat/lokasi
     tambahan, foto dokumentasi, serta tipe & status laporan. Baris pada daftar
     laporan hanya membuka halaman detail, jadi tautan inilah jalan masuknya.

     Untuk laporan SALINAN (kolaborasi / alat tambahan) yang dibuka adalah
     halaman edit laporan INDUKNYA, karena salinan selalu dikelola dari induk
     (lihat ReportController::edit()).

     Tautan ini otomatis disembunyikan pada akun hanya baca karena URL-nya
     memuat "/edit" (lihat layouts/partials/mode-hanya-baca.blade.php).

     Parameter: $report = model Report yang sedang dibuka. --}}
<div class="bg-white rounded-xl border border-teal-200 p-4">
    <h3 class="text-sm font-medium text-slate-900">Edit Lengkap Laporan</h3>
    <p class="text-xs text-slate-500 mt-1 mb-3">
        Kolaborasi teknisi, alat/lokasi tambahan, dan foto dokumentasi diubah dari halaman edit lengkap.
    </p>
    <a href="{{ route('reports.edit', $report->collaborator_of ?? $report->multi_of ?? $report) }}"
       class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 border border-teal-300 hover:bg-teal-50 text-teal-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3 3 3 0 013 3z" />
        </svg>
        Edit Lengkap (Kolaborasi, Alat, Foto)
    </a>
</div>
