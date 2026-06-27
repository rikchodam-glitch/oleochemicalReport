@extends('layouts.app')

@section('title', 'Tambah Teknisi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('technicians.index') }}" class="hover:text-slate-700">Teknisi</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Tambah Teknisi</span>
@endsection

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="text-base font-semibold text-slate-900 mb-6">Form Tambah Teknisi</h3>

            <form method="POST" action="{{ route('technicians.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" required
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Nama teknisi">
                        @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">NIK</label>
                        <input type="text" name="nik" value="{{ old('nik') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Nomor induk karyawan">
                        @error('nik') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Departemen</label>
                        <select name="department_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Departemen --</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" {{ old('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->code }} — {{ $dept->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Group</label>
                        <select name="group" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Group --</option>
                            @foreach($groups as $val => $label)
                                <option value="{{ $val }}" {{ old('group') === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Bagian</label>
                        <select name="section" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Bagian --</option>
                            @foreach($sections as $val => $label)
                                <option value="{{ $val }}" {{ old('section') === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Telegram ID (opsional)</label>
                        <input type="text" name="telegram_id" value="{{ old('telegram_id') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Chat ID Telegram (angka)">
                        @error('telegram_id') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Telegram Username (opsional)</label>
                        <input type="text" name="telegram_username" value="{{ old('telegram_username') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="username_tanpa_@">
                        @error('telegram_username') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Status <span class="text-red-500">*</span></label>
                        <select name="status" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="pending" {{ old('status') === 'pending' ? 'selected' : '' }}>Pending — Perlu persetujuan</option>
                            <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Aktif — Langsung aktif</option>
                        </select>
                    </div>
                </div>

                <!-- Notifikasi Telegram -->
                <div class="border-t border-slate-100 pt-4">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="send_notification" value="1" checked
                               class="mt-0.5 w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                        <div>
                            <p class="text-sm font-medium text-slate-700">Kirim notifikasi Telegram</p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Jika teknisi memiliki Telegram ID dan status Aktif, notifikasi selamat datang akan dikirim otomatis.
                            </p>
                        </div>
                    </label>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        <svg class="w-4 h-4 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Simpan Teknisi
                    </button>
                    <a href="{{ route('technicians.index') }}" class="px-5 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
