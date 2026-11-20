<div id="modal-alat" class="hidden fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-900/50" data-tutup-modal-alat></div>

    <div class="relative max-w-lg mx-auto mt-10 mb-10 bg-white rounded-xl shadow-xl border border-slate-200">
        <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-200">
            <h3 id="judul-modal-alat" class="text-sm font-semibold text-slate-900">Tambah Alat</h3>
            <button type="button" class="text-slate-400 hover:text-slate-600" data-tutup-modal-alat>&times;</button>
        </div>

        <form id="form-alat" method="POST" action="{{ route('tools.items.store') }}" enctype="multipart/form-data" class="p-4 space-y-3">
            @csrf
            <input type="hidden" name="_method" id="alat-method" value="">

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Rak penyimpanan</label>
                <select name="tool_rack_id" id="alat-rack" required
                        class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    @forelse($grup as $lokasi)
                        <optgroup label="{{ $lokasi['name'] }}">
                            @foreach($lokasi['rak'] as $rak)
                                <option value="{{ $rak['id'] }}">{{ $rak['name'] }}</option>
                            @endforeach
                        </optgroup>
                    @empty
                        <option value="">Belum ada rak</option>
                    @endforelse
                </select>
                @if(count($grup) === 0)
                    <p class="text-[11px] text-amber-700 mt-1">Buat Lokasi dan Rak lebih dulu di tab Lokasi &amp; Rak.</p>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Nama alat</label>
                    <input type="text" name="name" id="alat-nama" maxlength="150" required
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                           placeholder="Bor Batrai">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Kode unit</label>
                    <input type="text" name="code" id="alat-kode" maxlength="50" required
                           class="w-full rounded-lg border-slate-300 text-sm uppercase focus:border-teal-500 focus:ring-teal-500"
                           placeholder="BRBT001">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                    <select name="status" id="alat-status"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="tersedia">Tersedia</option>
                        <option value="rusak">Rusak</option>
                    </select>
                    <p id="alat-status-catatan" class="hidden text-[11px] text-amber-700 mt-1">
                        Alat sedang dipinjam; status mengikuti transaksi.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Foto alat</label>
                    <input type="file" name="photo" accept="image/*"
                           class="w-full text-xs rounded-lg border border-slate-300 px-2 py-1.5">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Catatan (opsional)</label>
                <textarea name="note" id="alat-catatan" rows="2" maxlength="2000"
                          class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"></textarea>
            </div>

            <label id="bungkus-hapus-foto" class="hidden items-center gap-2 text-xs text-rose-700">
                <input type="checkbox" name="hapus_foto" value="1" class="rounded border-slate-300">
                Hapus foto lama
            </label>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                <button type="button" class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50" data-tutup-modal-alat>
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                    Simpan
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modal = document.getElementById('modal-alat');
    const form = document.getElementById('form-alat');
    const judul = document.getElementById('judul-modal-alat');
    const method = document.getElementById('alat-method');
    const rack = document.getElementById('alat-rack');
    const nama = document.getElementById('alat-nama');
    const kode = document.getElementById('alat-kode');
    const status = document.getElementById('alat-status');
    const catatan = document.getElementById('alat-catatan');
    const hapusFoto = document.getElementById('bungkus-hapus-foto');
    const catatanStatus = document.getElementById('alat-status-catatan');

    if (!modal || !form) return;

    function buka() {
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function tutup() {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    document.querySelectorAll('[data-tutup-modal-alat]').forEach(function (el) {
        el.addEventListener('click', tutup);
    });

    const tambah = document.getElementById('tombol-tambah-alat');

    if (tambah) {
        tambah.addEventListener('click', function () {
            form.action = '{{ route('tools.items.store') }}';
            method.value = '';
            judul.textContent = 'Tambah Alat';
            nama.value = '';
            kode.value = '';
            status.disabled = false;
            status.value = 'tersedia';
            catatan.value = '';
            hapusFoto.classList.add('hidden');
            hapusFoto.classList.remove('flex');
            const cekHapus = hapusFoto.querySelector('input[name="hapus_foto"]');
            if (cekHapus) cekHapus.checked = false;
            catatanStatus.classList.add('hidden');
            buka();
        });
    }

    document.querySelectorAll('.tombol-edit-alat').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            form.action = '{{ url('tools/alat') }}/' + tombol.dataset.id;
            method.value = 'PUT';
            judul.textContent = 'Edit Alat';
            rack.value = tombol.dataset.rak;
            nama.value = tombol.dataset.nama;
            kode.value = tombol.dataset.kode;
            catatan.value = tombol.dataset.catatan || '';

            const sedangDipinjam = tombol.dataset.status === 'dipakai';
            status.value = sedangDipinjam ? 'tersedia' : tombol.dataset.status;
            status.disabled = sedangDipinjam;
            catatanStatus.classList.toggle('hidden', !sedangDipinjam);

            const adaFoto = tombol.dataset.adaFoto === '1';
            hapusFoto.classList.toggle('hidden', !adaFoto);
            hapusFoto.classList.toggle('flex', adaFoto);

            buka();
        });
    });
})();
</script>
@endpush
