@php
    /** @var \App\Models\ItAsset $itAsset */
    $canSnapshot = in_array($itAsset->device_type, \App\Models\ItAsset::SNAPSHOT_CAPABLE_TYPES, true) && !empty($itAsset->snapshot_url);
@endphp

@if($canSnapshot)
    <button type="button"
            id="snapshot-btn-{{ $itAsset->id }}"
            onclick="snapshotDevice({{ $itAsset->id }}, this)"
            class="text-cyan-600 hover:text-cyan-700 text-xs font-medium shrink-0"
            title="Ambil snapshot dari kamera sekarang">
        Snapshot
    </button>
@endif

@once
@push('scripts')
<script>
/**
 * Ambil snapshot CCTV/NVR lewat AJAX tanpa reload halaman, lalu perbarui
 * gambar thumbnail (bila ada) dengan hasil terbaru.
 *
 * @param {number} id
 * @param {HTMLButtonElement} button
 */
window.snapshotDevice = function (id, button) {
    if (!button) return;

    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Mengambil...';

    const url = '{{ route('it-assets.snapshot', ['itAsset' => '__ID__']) }}'.replace('__ID__', id);

    fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(({ ok, data }) => {
        if (!ok || !data.success) {
            alert(data.message || 'Gagal mengambil snapshot.');
            return;
        }

        const img = document.getElementById('snapshot-img-' + id);
        if (img) {
            img.src = data.snapshot_url + '?t=' + Date.now();
        }

        alert('Snapshot berhasil diambil.');
    })
    .catch(err => {
        alert('Error: ' + err.message);
    })
    .finally(() => {
        button.disabled = false;
        button.textContent = originalText;
    });
};
</script>
@endpush
@endonce
