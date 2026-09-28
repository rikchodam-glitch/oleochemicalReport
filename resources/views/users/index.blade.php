@extends('layouts.app')

@section('title', 'Manajemen User')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Manajemen User</span>
@endsection

@section('header-actions')
    <div class="flex items-center gap-2">
        <form method="POST" action="{{ route('users.generate-teknisi') }}"
              onsubmit="return confirm('Buat akun untuk semua teknisi yang belum punya akun? Username memakai NIK dan password awal NIK huruf kecil.')">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 border border-teal-200 bg-teal-50 hover:bg-teal-100 text-teal-700 text-sm font-medium rounded-lg transition-colors">
                Buat Akun Semua Teknisi
            </button>
        </form>
        <a href="{{ route('users.create') }}"
           class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
            Tambah Akun
        </a>
    </div>
@endsection

@section('content')
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Total Akun</p>
            <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($totalAkun) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Teknisi Belum Punya Akun</p>
            <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($teknisiTanpaAkun->count()) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Akun Akses Penuh (admin/supervisor)</p>
            <p class="text-2xl font-bold text-slate-900 mt-1">
                {{ number_format(($jumlahPerRole['admin'] ?? 0) + ($jumlahPerRole['supervisor'] ?? 0)) }}
            </p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" action="{{ route('users.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
                <input type="text" name="search" value="{{ request('search') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Cari nama, NIK, atau email...">
            </div>
            <div>
                <select name="bagian" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Semua Bagian</option>
                    <option value="admin" {{ request('bagian') === 'admin' ? 'selected' : '' }}>Administrator</option>
                    <option value="supervisor" {{ request('bagian') === 'supervisor' ? 'selected' : '' }}>Supervisor</option>
                    @foreach($bagian as $kode => $label)
                        <option value="{{ $kode }}" {{ request('bagian') === $kode ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Filter</button>
                <a href="{{ route('users.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">Reset</a>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Nama</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Username</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Bagian / Role</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Terhubung Teknisi</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($users as $user)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-800">{{ $user->name }}</p>
                                @if($user->email)
                                    <p class="text-xs text-slate-400">{{ $user->email }}</p>
                                @endif
                                @if($user->id === auth()->id())
                                    <p class="text-xs text-teal-600">Akun Anda</p>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $user->username ?? '-' }}</td>
                            <td class="px-5 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium border
                                             {{ $user->aksesPenuh() ? 'bg-teal-50 text-teal-700 border-teal-100' : 'bg-slate-50 text-slate-600 border-slate-200' }}">
                                    {{ $user->labelBagian() }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $user->technician?->name ?? '-' }}</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('users.edit', $user) }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">Edit</a>
                                    <form method="POST" action="{{ route('users.reset-password', $user) }}"
                                          onsubmit="return confirm('Reset password {{ $user->name }} ke NIK huruf kecil?')">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-amber-600 hover:text-amber-700">Reset Password</button>
                                    </form>
                                    @if($user->id !== auth()->id())
                                        <form method="POST" action="{{ route('users.destroy', $user) }}"
                                              onsubmit="return confirm('Hapus akun {{ $user->name }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-medium text-rose-600 hover:text-rose-700">Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada akun yang cocok.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">{{ $users->links() }}</div>
        @endif
    </div>
@endsection
