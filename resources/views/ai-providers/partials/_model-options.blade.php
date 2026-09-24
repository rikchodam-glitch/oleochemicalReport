{{--
    Daftar saran nama model AI (datalist).
    Sumbernya konstanta AiModelDiscoveryService::PREFERRED_CHAT_MODELS, jadi
    daftar ini ikut berubah kalau daftar prioritas di service diperbarui.

    Catatan: field "Model" pada form provider bersifat OPSIONAL. Bila
    dikosongkan, sistem mendeteksi sendiri model chat yang tersedia di akun
    lewat endpoint GET /models (tombol Test juga mengoreksi otomatis bila nama
    model yang tersimpan sudah tidak valid).
--}}
<datalist id="daftar-model-ai">
    @foreach (\App\Services\AiModelDiscoveryService::PREFERRED_CHAT_MODELS as $modelAi)
        <option value="{{ $modelAi }}"></option>
    @endforeach
</datalist>
