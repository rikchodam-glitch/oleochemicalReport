@extends('layouts.app')

@section('title', 'Buat Laporan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('reports.index') }}" class="hover:text-slate-700">Laporan</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Form Teknisi</span>
@endsection

@section('content')
    <div class="max-w-3xl space-y-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Buat Laporan</h2>
            <p class="text-sm text-slate-500 mt-1">
                {{ $teknisi->name }} · NIK {{ $teknisi->nik ?? '-' }} ·
                {{ \App\Models\Technician::GROUPS[$teknisi->group] ?? ($teknisi->group ?? '-') }}
            </p>
            <p class="text-xs text-slate-400 mt-1">Laporan dibuat atas nama akun ini, tanpa lewat bot Telegram.</p>
        </div>

        @if($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('laporan.saya.store') }}" enctype="multipart/form-data"
              class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-5">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="report_date" class="block text-xs font-medium text-slate-600 mb-1">Tanggal laporan</label>
                    <input type="date" name="report_date" id="report_date" value="{{ old('report_date', now()->toDateString()) }}" required
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="report_type" class="block text-xs font-medium text-slate-600 mb-1">Tipe laporan</label>
                    <select name="report_type" id="report_type" required class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="equipment_repair" @selected(old('report_type', 'equipment_repair') === 'equipment_repair')>Perbaikan Equipment</option>
                        <option value="area_work" @selected(old('report_type') === 'area_work')>Pekerjaan Area</option>
                        <option value="general" @selected(old('report_type') === 'general')>Umum</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="work_classification" class="block text-xs font-medium text-slate-600 mb-1">Klasifikasi pekerjaan</label>
                    <select name="work_classification" id="work_classification" onchange="syncJenisPekerjaan()"
                            data-value="{{ old('work_classification') }}"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">- Pilih -</option>
                        <option value="zpm1" @selected(old('work_classification') === 'zpm1')>ZPM 1 — Corrective</option>
                        <option value="zpm3" @selected(old('work_classification') === 'zpm3')>ZPM 3 — Preventive</option>
                        <option value="zpm6" @selected(old('work_classification') === 'zpm6')>ZPM 6 — Planned</option>
                        <option value="zpm5" @selected(old('work_classification') === 'zpm5')>ZPM 5 — Fabrikasi</option>
                        <option value="zpm7" @selected(old('work_classification') === 'zpm7')>ZPM 7 — Sipil</option>
                        <option value="zpm8" @selected(old('work_classification') === 'zpm8')>ZPM 8 — Project</option>
                    </select>
                </div>
                <div>
                    <label for="jenis_pekerjaan" class="block text-xs font-medium text-slate-600 mb-1">Jenis pekerjaan</label>
                    <select name="jenis_pekerjaan" id="jenis_pekerjaan" data-value="{{ old('jenis_pekerjaan') }}"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">- Pilih -</option>
                        <option value="CM" @selected(old('jenis_pekerjaan') === 'CM')>CM (Corrective)</option>
                        <option value="dCM" @selected(old('jenis_pekerjaan') === 'dCM')>dCM (Deferred)</option>
                        <option value="PM" @selected(old('jenis_pekerjaan') === 'PM')>PM (Preventive)</option>
                        <option value="Project" @selected(old('jenis_pekerjaan') === 'Project')>Project</option>
                        <option value="UMUM" @selected(old('jenis_pekerjaan') === 'UMUM')>UMUM</option>
                        <option value="IT" @selected(old('jenis_pekerjaan') === 'IT')>IT</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="sesuai_rencana" class="block text-xs font-medium text-slate-600 mb-1">Sesuai rencana (wajib untuk dCM)</label>
                    <select name="sesuai_rencana" id="sesuai_rencana" class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">- Pilih -</option>
                        <option value="ya" @selected(old('sesuai_rencana') === 'ya')>Ya</option>
                        <option value="tidak" @selected(old('sesuai_rencana') === 'tidak')>Tidak</option>
                    </select>
                </div>
                <div>
                    <label for="tanggal_kejadian" class="block text-xs font-medium text-slate-600 mb-1">Tanggal kejadian (opsional)</label>
                    <input type="date" name="tanggal_kejadian" id="tanggal_kejadian" value="{{ old('tanggal_kejadian') }}"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Lokasi / Equipment</label>
                <div>
                    <label for="area_id" class="block text-xs font-medium text-slate-600 mb-1">Area</label>
                    <select name="area_id" id="area_id" class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">- Pilih area -</option>
                        @foreach($areas as $area)
                            <option value="{{ $area->id }}" @selected((int) old('area_id') === (int) $area->id)>{{ $area->code }} — {{ $area->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="cari_aset" class="block text-xs font-medium text-slate-600 mb-1">Cari alat/equipment</label>
                    <input type="text" id="cari_aset" autocomplete="off"
                           placeholder="Ketik minimal 2 huruf: kode alat, nomor equipment, atau deskripsi"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <input type="hidden" name="asset_id" id="asset_id" value="{{ old('asset_id') }}">
                    <p id="aset_terpilih" class="text-[11px] text-slate-500 mt-1">Belum ada alat dipilih.</p>
                    <div id="hasil_aset" class="hidden mt-2 max-h-56 overflow-y-auto divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white"></div>
                </div>

                <div>
                    <label for="equipment_tag" class="block text-xs font-medium text-slate-600 mb-1">Equipment tag (opsional)</label>
                    <input type="text" name="equipment_tag" id="equipment_tag" value="{{ old('equipment_tag') }}" maxlength="100"
                           placeholder="Terisi otomatis bila memilih alat"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
            </div>

            <div>
                <label for="work_description" class="block text-xs font-medium text-slate-600 mb-1">Deskripsi pekerjaan</label>
                <textarea name="work_description" id="work_description" rows="5" required
                          class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('work_description') }}</textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="work_duration_minutes" class="block text-xs font-medium text-slate-600 mb-1">Durasi (menit)</label>
                    <input type="number" name="work_duration_minutes" id="work_duration_minutes" min="0" max="1440"
                           value="{{ old('work_duration_minutes', 0) }}" required
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="final_status" class="block text-xs font-medium text-slate-600 mb-1">Status pekerjaan</label>
                    <select name="final_status" id="final_status" required class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="completed" @selected(old('final_status', 'completed') === 'completed')>Done (Selesai)</option>
                        <option value="continue" @selected(old('final_status') === 'continue')>Continue (Lanjut besok)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="root_cause" class="block text-xs font-medium text-slate-600 mb-1">Root cause</label>
                    <textarea name="root_cause" id="root_cause" rows="3"
                              class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('root_cause') }}</textarea>
                </div>
                <div>
                    <label for="finding" class="block text-xs font-medium text-slate-600 mb-1">Temuan</label>
                    <textarea name="finding" id="finding" rows="3"
                              class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('finding') }}</textarea>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="photos_documentation" class="block text-xs font-medium text-slate-600 mb-1">Foto dokumentasi</label>
                    <input type="file" name="photos_documentation[]" id="photos_documentation" multiple accept="image/*"
                           class="w-full text-xs text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 file:font-medium">
                </div>
                <div>
                    <label for="photos_hygiene" class="block text-xs font-medium text-slate-600 mb-1">Foto hygiene clearance</label>
                    <input type="file" name="photos_hygiene[]" id="photos_hygiene" multiple accept="image/*"
                           class="w-full text-xs text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 file:font-medium">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-2">Kolaborator</label>
                <div class="max-h-56 overflow-y-auto rounded-lg border border-slate-200 divide-y divide-slate-100">
                    @forelse($kolaborator as $rekan)
                        <label class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-slate-50 cursor-pointer">
                            <input type="checkbox" name="collaborator_ids[]" value="{{ $rekan->id }}"
                                   @checked(in_array($rekan->id, old('collaborator_ids', []))) 
                                   class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span class="font-medium text-slate-700">{{ $rekan->name }}</span>
                            <span class="text-xs text-slate-400">{{ $rekan->nik }} · {{ \App\Models\Technician::GROUPS[$rekan->group] ?? $rekan->group }}</span>
                        </label>
                    @empty
                        <p class="px-3 py-4 text-xs text-slate-400">Tidak ada teknisi lain yang aktif.</p>
                    @endforelse
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background-color:#0E9E8E;">Simpan laporan</button>
                <a href="{{ route('reports.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Batal</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
    function syncJenisPekerjaan() {
        const wcSelect = document.getElementById('work_classification');
        const jenisSelect = document.getElementById('jenis_pekerjaan');

        if (!wcSelect || !jenisSelect) return;

        const options = {
            zpm1: [['EI', 'EI'], ['Mekanik', 'Mekanik'], ['IT', 'IT']],
            zpm6: [['EI', 'EI'], ['Mekanik', 'Mekanik'], ['IT', 'IT']],
            zpm3: [['CM/PM', 'CM/PM']],
            zpm5: [['Fabrikasi', 'Fabrikasi']],
            zpm7: [['Sipil', 'Sipil']],
            zpm8: [['Project', 'Project']],
            '': [['CM', 'CM'], ['dCM', 'dCM'], ['PM', 'PM'], ['Project', 'Project'], ['UMUM', 'UMUM'], ['IT', 'IT']],
        };

        const asli = wcSelect.getAttribute('data-value') || '';
        const wc = wcSelect.value;
        let list = options[wc] || options[''];
        let current = jenisSelect.value || jenisSelect.getAttribute('data-value') || '';

        if (wc !== '' && wc !== asli) current = '';
        if (current !== '' && !list.some(pair => pair[0] === current)) list = list.concat([[current, current]]);

        jenisSelect.innerHTML = '';

        if (list.length > 1) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '- Pilih -';
            jenisSelect.appendChild(placeholder);
        }

        list.forEach(function (pair) {
            const opt = document.createElement('option');
            opt.value = pair[0];
            opt.textContent = pair[1];
            if (pair[0] === current) opt.selected = true;
            jenisSelect.appendChild(opt);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncJenisPekerjaan();

        const cari = document.getElementById('cari_aset');
        const hasil = document.getElementById('hasil_aset');
        const assetId = document.getElementById('asset_id');
        const area = document.getElementById('area_id');
        const terpilih = document.getElementById('aset_terpilih');
        const equipmentTag = document.getElementById('equipment_tag');
        const reportType = document.getElementById('report_type');
        let timer = null;

        if (!cari || !hasil || !assetId) return;

        function kosongkan() {
            hasil.innerHTML = '';
            hasil.classList.add('hidden');
        }

        cari.addEventListener('input', function () {
            clearTimeout(timer);
            const q = cari.value.trim();
            if (q.length < 2) { kosongkan(); return; }

            timer = setTimeout(async function () {
                try {
                    const respons = await fetch('{{ route('reports.locations.cari-alat') }}?q=' + encodeURIComponent(q), {
                        headers: { 'Accept': 'application/json' },
                    });
                    const daftar = await respons.json();
                    hasil.innerHTML = '';

                    if (!Array.isArray(daftar) || daftar.length === 0) {
                        hasil.classList.remove('hidden');
                        const kosong = document.createElement('p');
                        kosong.className = 'px-3 py-3 text-xs text-slate-400';
                        kosong.textContent = 'Alat tidak ditemukan.';
                        hasil.appendChild(kosong);
                        return;
                    }

                    daftar.forEach(function (item) {
                        const tombol = document.createElement('button');
                        tombol.type = 'button';
                        tombol.className = 'w-full text-left px-3 py-2 hover:bg-teal-50 text-xs';
                        tombol.textContent = (item.tech_ident_no || '-') + ' — ' + (item.description || '') + (item.area_label ? ' · ' + item.area_label : '');
                        tombol.addEventListener('click', function () {
                            assetId.value = item.id;
                            if (item.area_id) area.value = item.area_id;
                            if (equipmentTag && item.tech_ident_no) equipmentTag.value = item.tech_ident_no;
                            if (reportType) reportType.value = 'equipment_repair';
                            terpilih.textContent = 'Dipilih: ' + (item.tech_ident_no || '-') + ' — ' + (item.description || '');
                            cari.value = '';
                            kosongkan();
                        });
                        hasil.appendChild(tombol);
                    });
                    hasil.classList.remove('hidden');
                } catch (e) {
                    kosongkan();
                }
            }, 250);
        });

        document.addEventListener('click', function (e) {
            if (!hasil.contains(e.target) && e.target !== cari) kosongkan();
        });
    });
</script>
@endpush
