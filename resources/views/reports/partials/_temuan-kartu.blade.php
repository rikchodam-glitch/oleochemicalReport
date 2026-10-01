{{-- Kartu Temuan (finding) pada halaman detail laporan.
     Memakai pola yang sama dengan kartu Root Cause (tampilan + mode edit).
     Parameter: $report = model Report. --}}
<div class="bg-white rounded-xl border border-slate-200 p-6">
    <h3 class="font-medium text-slate-900 mb-3">Temuan (Opsional)</h3>

    {{-- VIEW --}}
    <div x-show="!editMode"
         class="bg-slate-50 rounded-lg p-4 text-sm text-slate-700 whitespace-pre-wrap leading-relaxed">
        {{ $report->finding ?: '-' }}
    </div>

    {{-- EDIT --}}
    <div x-show="editMode" x-cloak>
        <textarea name="finding" form="form-edit-laporan" rows="4"
                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 leading-relaxed">{{ old('finding', $report->finding) }}</textarea>
        @error('finding')
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>
</div>
