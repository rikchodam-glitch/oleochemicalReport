{{--
    Tombol hapus request yang belum punya nomor PR.
    Dipakai dengan $row = App\Models\SpRequest.
--}}
@php
    $pesanHapus = 'Hapus request ' . $row->kode . '? Foto dan data request ikut dihapus.';
@endphp

<form method="POST" action="{{ route('sparepart.request.destroy', $row) }}" class="inline"
      onsubmit="return confirm('{{ $pesanHapus }}')">
    @csrf
    @method('DELETE')
    <button type="submit" title="Hapus request" aria-label="Hapus request {{ $row->kode }}"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-rose-200 text-rose-500 hover:bg-rose-50">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
        </svg>
    </button>
</form>
