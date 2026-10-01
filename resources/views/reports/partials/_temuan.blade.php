{{-- Field Temuan (finding) — dipakai halaman Tambah & Edit laporan.
     Dipisah jadi partial supaya halaman blade yang besar tidak perlu ditulis
     ulang (lihat aturan edit blade di AGENTS.md).
     Parameter: $nilai = isi awal textarea. --}}
<div>
    <label for="finding" class="block text-sm font-medium text-slate-700 mb-1">Temuan (Opsional)</label>
    <textarea id="finding" name="finding" rows="4"
              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ $nilai }}</textarea>
    <p class="text-xs text-slate-400 mt-1">Catatan temuan di lapangan, boleh dikosongkan.</p>
    @error('finding')
        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
    @enderror
</div>
