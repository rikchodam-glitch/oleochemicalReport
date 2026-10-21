@extends('layouts.app')

@section('title', 'Ubah Akun')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('users.index') }}" class="hover:text-slate-700">Manajemen User</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Ubah Akun</span>
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

    <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Data Akun</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nama <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Username (NIK) <span class="text-rose-500">*</span></label>
                    <input type="text" name="username" value="{{ old('username', $user->username) }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email (opsional)</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Password Baru (opsional)</label>
                    <input type="text" name="password"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-slate-400 mt-1">
                        Kosongkan bila password tidak ingin diganti. Gunakan tombol Reset Password di daftar user
                        untuk mengembalikan password ke NIK huruf kecil.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Bagian / Role dan Kaitan Teknisi</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Bagian / Role <span class="text-rose-500">*</span></label>
                    <select name="role" required
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <optgroup label="Akses penuh (semua halaman)">
                            @foreach($roleAksesPenuh as $rolePenuh)
                                <option value="{{ $rolePenuh }}" {{ old('role', $user->role) === $rolePenuh ? 'selected' : '' }}>
                                    {{ $rolePenuh === 'admin' ? 'Administrator' : 'Supervisor' }}
                                </option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Khusus">
                            <option value="{{ \App\Models\User::ROLE_ADMIN_REGULER }}" {{ old('role', $user->role) === \App\Models\User::ROLE_ADMIN_REGULER ? 'selected' : '' }}>
                                Admin Reguler (input cuti)
                            </option>
                        </optgroup>
                        <optgroup label="Bagian (dibatasi hak akses)">
                            @foreach($bagian as $kode => $label)
                                <option value="{{ $kode }}" {{ old('role', $user->role) === $kode ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">
                        Hak akses Bagian ini berlaku bila rolenya berisi kode Bagian, bukan Administrator/Supervisor.
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Hubungkan ke Teknisi (opsional)</label>
                    <select name="technician_id"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Tidak dihubungkan</option>
                        @foreach($teknisi as $pilihan)
                            <option value="{{ $pilihan->id }}"
                                    {{ (string) old('technician_id', $user->technician_id) === (string) $pilihan->id ? 'selected' : '' }}>
                                {{ $pilihan->name }} - {{ $pilihan->nik ?? 'NIK kosong' }}
                                ({{ \App\Models\Technician::SECTIONS[$pilihan->section] ?? 'Lainnya' }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">
                        Dipakai untuk reset password ke NIK dan penanda pemilik akun di daftar user.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                Simpan Perubahan
            </button>
            <a href="{{ route('users.index') }}"
               class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Batal
            </a>
        </div>
    </form>
@endsection
