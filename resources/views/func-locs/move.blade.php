@extends('layouts.app')

@section('title', 'Pindah Functional Location')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('func-locs.index') }}" class="hover:text-slate-700">Functional Location</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Pindah</span>
@endsection

@section('content')
    @php
        /** @var \App\Models\FunctionalLocation $funcLoc */
        $subtreeCount = count($funcLoc->getSubtreeIds()) - 1;
    @endphp

    <div class="max-w-xl space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            @if ($errors->any())
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('func-locs.move.update', $funcLoc) }}">
                @csrf
                @method('PUT')

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Node yang Dipindah</label>
                    <input type="text" value="{{ $funcLoc->code }} — {{ $funcLoc->name }}" disabled
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-500">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Level Saat Ini</label>
                    <input type="text" value="L{{ $funcLoc->level }} - {{ $levelLabels[$funcLoc->level] ?? 'Tidak Diketahui' }}" disabled
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-500">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Parent Tujuan</label>
                    <select name="new_parent_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                        <option value="">Root (tanpa parent)</option>
                        @foreach($parentOptions as $parent)
                            <option value="{{ $parent->id }}" {{ old('new_parent_id') === (string) $parent->id ? 'selected' : '' }}>
                                {{ $parent->code }} — L{{ $parent->level }} ({{ $levelLabels[$parent->level] }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">
                        Seluruh subtree ({{ $subtreeCount }} node keturunan) ikut berpindah.
                        Kode full path setiap node di subtree akan ditulis ulang mengikuti jalur baru.
                    </p>
                </div>

                <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg">
                    <p class="text-xs text-amber-800 leading-relaxed">
                        Perhatian: node yang dipindah ke level atau parent berbeda akan mengubah
                        kode (full path) node ini beserta seluruh keturunannya, sekaligus
                        menyinkronkan kembali relasi master lama (PT/Departemen/Area/Section).
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Pindahkan
                    </button>
                    <a href="{{ route('func-locs.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
