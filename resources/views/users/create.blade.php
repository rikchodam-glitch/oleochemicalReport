@extends('layouts.app')

@section('title', 'Tambah Akun')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('users.index') }}" class="hover:text-slate-700">Manajemen User</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Tambah Akun</span>
@endsection

@section('content')
    @if($errors->any())
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <p class="font-medium">Periksa kembali isian berikut:</p>
            <ul class="mt-1 list-disc list-inside">
                @foreach($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('users.store') }}" class="space-y-6">
        @csrf

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Data Akun</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nama <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" id="inputNama" value="{{ old('name') }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Username (NIK) <span class="text-rose-500">*</span></label>
                    <input type="text" name="username" id="inputUsername" value="{{ old('username') }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-slate-400 mt-1">Akun teknisi memakai NIK tanpa spasi, mis. ARE23070009.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email (opsional)</label>
                    <input type="email" name="email" value="{{ old('email') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Password Awal (opsional)</label>
                    <input type="text" name="password"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-slate-400 mt-1">
                        Kosongkan bila password awal ingin sama dengan username huruf kecil
                        (untuk teknisi berarti sama dengan NIK huruf kecil).
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Bagian / Role dan Kaitan Teknisi</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Bagian / Role <span class="text-rose-500">*</span></label>
                    <select name="role" id="selectBagian" required
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <optgroup label="Akses penuh (semua halaman)">
                            @foreach($roleAksesPenuh as $rolePenuh)
                                <option value="{{ $rolePenuh }}" {{ old('role') === $rolePenuh ? 'selected' : '' }}>
                                    {{ $rolePenuh === 'admin' ? 'Administrator' : 'Supervisor' }}
                                </option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Bagian (dibatasi hak akses)">
                            @foreach($bagian as $kode => $label)
                                <option value="{{ $kode }}" {{ old('role') === $kode ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Pengaturan halaman yang boleh dibuka ada di menu Hak Akses Bagian.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Hubungkan ke Teknisi (opsional)</label>
                    <select name="technician_id" id="selectTeknisi"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Tidak dihubungkan</option>
                        @foreach($teknisiTanpaAkun as $teknisi)
                            <option value="{{ $teknisi->id }}"
                                    data-nama="{{ $teknisi->name }}"
                                    data-nik="{{ $teknisi->nik }}"
                                    data-bagian="{{ $teknisi->section }}"
                                    {{ (string) old('technician_id') === (string) $teknisi->id ? 'selected' : '' }}>
                                {{ $teknisi->name }} - {{ $teknisi->nik ?? 'NIK kosong' }}
                                ({{ \App\Models\Technician::SECTIONS[$teknisi->section] ?? 'Lainnya' }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Memilih teknisi akan mengisi otomatis nama, username, dan bagian.</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                Simpan Akun
            </button>
            <a href="{{ route('users.index') }}"
               class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Batal
            </a>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        /**
         * Isi otomatis nama, username, dan bagian dari data teknisi yang dipilih
         * supaya admin tidak perlu mengetik ulang NIK.
         */
        (function () {
            var pilihTeknisi = document.getElementById('selectTeknisi');
            if (! pilihTeknisi) {
                return;
            }

            pilihTeknisi.addEventListener('change', function () {
                var opsi = pilihTeknisi.options[pilihTeknisi.selectedIndex];
                if (! opsi || opsi.value === '') {
                    return;
                }

                var nama   = opsi.getAttribute('data-nama') || '';
                var nik    = opsi.getAttribute('data-nik') || '';
                var bagian = opsi.getAttribute('data-bagian') || '';

                var inputNama     = document.getElementById('inputNama');
                var inputUsername = document.getElementById('inputUsername');
                var selectBagian  = document.getElementById('selectBagian');

                if (inputNama && nama !== '') {
                    inputNama.value = nama;
                }

                if (inputUsername && nik !== '') {
                    inputUsername.value = nik;
                }

                if (selectBagian && bagian !== '') {
                    selectBagian.value = bagian;
                }
            });
        })();
    </script>
@endpush
