<div id="modal-pinjam" class="hidden fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-900/50" data-tutup-modal-pinjam></div>

    <div class="relative max-w-2xl mx-auto mt-8 mb-8 bg-white rounded-xl shadow-xl border border-slate-200 max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-200">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Pinjamkan Alat</h3>
                <p id="pinjam-nama-alat" class="text-xs text-slate-500"></p>
            </div>
            <button type="button" class="text-slate-400 hover:text-slate-600" data-tutup-modal-pinjam>&times;</button>
        </div>

        <form method="POST" action="{{ $aksiPinjamkan ?? route('tools.loans.store') }}" class="p-4 space-y-4 overflow-y-auto">
            @csrf
            <input type="hidden" name="_form" value="pinjam">
            <input type="hidden" name="tool_id" id="pinjam-tool-id" value="{{ old('tool_id') }}">

            @if($errors->any() && old('_form') === 'pinjam')
                <div class="rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-xs text-rose-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="flex flex-wrap gap-2">
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 text-sm cursor-pointer">
                    <input type="radio" name="borrower_type" value="teknisi" class="text-teal-600"
                           {{ old('borrower_type', 'teknisi') === 'teknisi' ? 'checked' : '' }}>
                    Teknisi
                </label>
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 text-sm cursor-pointer">
                    <input type="radio" name="borrower_type" value="non_teknisi" class="text-teal-600"
                           {{ old('borrower_type') === 'non_teknisi' ? 'checked' : '' }}>
                    Non-Teknisi
                </label>
            </div>

            <div id="bagian-teknisi" class="space-y-2">
                <input type="search" id="cari-teknisi" placeholder="Cari nama atau NIK teknisi..."
                       class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">

                <div id="daftar-teknisi-pilih" class="max-h-64 overflow-y-auto border border-slate-200 rounded-lg p-2 space-y-1">
                    @forelse($daftarTeknisi as $bagian)
                        <details class="grup-teknisi" open>
                            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500 px-1 py-1">
                                {{ $bagian['label'] }}
                            </summary>
                            <div class="mt-1 space-y-1">
                                @foreach($bagian['items'] as $teknisi)
                                    <label class="pemilih-teknisi flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 cursor-pointer"
                                           data-cari="{{ strtolower(($teknisi['name'] ?? '') . ' ' . ($teknisi['nik'] ?? '') . ' ' . ($teknisi['department'] ?? '')) }}">
                                        <input type="radio" name="technician_id" value="{{ $teknisi['id'] }}" class="text-teal-600"
                                               {{ (string) old('technician_id') === (string) $teknisi['id'] ? 'checked' : '' }}>
                                        <span class="text-sm text-slate-800">{{ $teknisi['name'] }}</span>
                                        <span class="text-[11px] text-slate-400">
                                            {{ $teknisi['nik'] ?: '-' }}{{ $teknisi['department'] ? ' / ' . $teknisi['department'] : '' }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <p class="text-xs text-slate-500 px-2 py-2">Belum ada teknisi aktif.</p>
                    @endforelse
                </div>
            </div>

            <div id="bagian-non-teknisi" class="hidden space-y-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Nama peminjam</label>
                    <input type="text" name="borrower_name" value="{{ old('borrower_name') }}" maxlength="150"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                           placeholder="Nama lengkap peminjam">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Departemen</label>
                    <select name="borrower_department_id"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">- Pilih departemen -</option>
                        @foreach($daftarDepartemen as $departemen)
                            <option value="{{ $departemen->id }}" @selected((string) old('borrower_department_id') === (string) $departemen->id)>
                                {{ $departemen->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Catatan (opsional)</label>
                <textarea name="catatan" rows="2" maxlength="1000"
                          class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('catatan') }}</textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                <button type="button" class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50" data-tutup-modal-pinjam>
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                    Pinjamkan
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modal = document.getElementById('modal-pinjam');
    const nama = document.getElementById('pinjam-nama-alat');
    const toolId = document.getElementById('pinjam-tool-id');
    const cari = document.getElementById('cari-teknisi');
    const bagianTeknisi = document.getElementById('bagian-teknisi');
    const bagianNon = document.getElementById('bagian-non-teknisi');

    if (!modal) return;

    function buka() {
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function tutup() {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function aturJenis() {
        const jenis = document.querySelector('input[name="borrower_type"]:checked');
        const teknisi = !jenis || jenis.value === 'teknisi';
        bagianTeknisi.classList.toggle('hidden', !teknisi);
        bagianNon.classList.toggle('hidden', teknisi);
    }

    document.querySelectorAll('[data-tutup-modal-pinjam]').forEach(function (el) {
        el.addEventListener('click', tutup);
    });

    document.querySelectorAll('.tombol-pinjamkan').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            toolId.value = tombol.dataset.id;
            nama.textContent = tombol.dataset.nama;
            buka();
        });
    });

    document.querySelectorAll('input[name="borrower_type"]').forEach(function (radio) {
        radio.addEventListener('change', aturJenis);
    });

    if (cari) {
        cari.addEventListener('input', function () {
            const q = cari.value.toLowerCase().trim();

            document.querySelectorAll('.grup-teknisi').forEach(function (grup) {
                let ada = false;

                grup.querySelectorAll('.pemilih-teknisi').forEach(function (baris) {
                    const cocok = !q || baris.dataset.cari.includes(q);
                    baris.style.display = cocok ? '' : 'none';
                    if (cocok) ada = true;
                });

                grup.style.display = ada ? '' : 'none';
                if (q !== '' && ada) grup.open = true;
            });
        });
    }

    aturJenis();

    @if($errors->any() && old('_form') === 'pinjam')
        buka();
    @endif
})();
</script>
@endpush
