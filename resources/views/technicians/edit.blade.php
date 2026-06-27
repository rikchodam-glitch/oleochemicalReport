@extends('layouts.app')

@section('title', 'Edit Teknisi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('technicians.index') }}" class="hover:text-slate-700">Teknisi</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('technicians.show', $technician) }}" class="hover:text-slate-700">{{ $technician->name }}</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Edit</span>
@endsection

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="text-base font-semibold text-slate-900 mb-6">Edit Teknisi</h3>

            <form method="POST" action="{{ route('technicians.update', $technician) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $technician->name) }}" required
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">NIK</label>
                        <input type="text" name="nik" value="{{ old('nik', $technician->nik) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        @error('nik') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Departemen</label>
                        <select name="department_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Departemen --</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" {{ old('department_id', $technician->department_id) == $dept->id ? 'selected' : '' }}>{{ $dept->code }} — {{ $dept->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Group</label>
                        <select name="group" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Group --</option>
                            @foreach($groups as $val => $label)
                                <option value="{{ $val }}" {{ old('group', $technician->group) === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Bagian</label>
                        <select name="section" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Bagian --</option>
                            @foreach($sections as $val => $label)
                                <option value="{{ $val }}" {{ old('section', $technician->section) === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Telegram ID</label>
                        <input type="text" name="telegram_id" value="{{ old('telegram_id', $technician->telegram_id) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Kosongkan jika tidak ada">
                        @error('telegram_id') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Telegram Username</label>
                        <input type="text" name="telegram_username" value="{{ old('telegram_username', $technician->telegram_username) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Kosongkan jika tidak ada">
                        @error('telegram_username') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Status <span class="text-red-500">*</span></label>
                        <select name="status" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="pending" {{ old('status', $technician->status) === 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="active" {{ old('status', $technician->status) === 'active' ? 'selected' : '' }}>Aktif</option>
                            <option value="suspended" {{ old('status', $technician->status) === 'suspended' ? 'selected' : '' }}>Suspended</option>
                        </select>
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <p class="text-xs text-slate-400 flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Jika status diubah ke Aktif dan teknisi memiliki Telegram ID, notifikasi akan dikirim otomatis.
                    </p>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Simpan Perubahan</button>
                    <a href="{{ route('technicians.show', $technician) }}" class="px-5 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
