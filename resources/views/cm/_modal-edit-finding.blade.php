{{-- Modal Edit Finding --}}
<div id="editFindingModal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center p-4"
     onclick="if(event.target===this)tutupEditFinding()">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="sticky top-0 z-10 flex items-start justify-between gap-4 px-6 py-4 border-b border-slate-100 bg-white">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">
                    Edit Finding <span id="ef_kode" class="font-mono text-teal-700"></span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Perbarui status, tindakan, PIC, dan remark. Perubahan tampil langsung di tabel Temuan.
                    Field hasil sinkronisasi dapat diperbarui lagi oleh sync berikutnya; remark manual dipertahankan.
                </p>
            </div>
            <button type="button" onclick="tutupEditFinding()"
                    class="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
                    title="Tutup">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form id="editFindingForm" method="POST" action="#" class="p-6 space-y-5">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Severity</label>
                    <select id="ef_severity" name="severity" required
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                    <select id="ef_status" name="status" required
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Tanggal Temuan</label>
                    <input type="date" id="ef_tanggal_temuan" name="tanggal_temuan" required
                           class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Kategori</label>
                    <input type="text" id="ef_kategori" name="kategori" required maxlength="255"
                           class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">PIC</label>
                    <input type="text" id="ef_pic" name="pic" maxlength="100" placeholder="Nama / regu"
                           class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Tanggal Tindakan</label>
                    <input type="date" id="ef_date_action" name="date_action"
                           class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Deskripsi</label>
                <textarea id="ef_deskripsi" name="deskripsi" rows="3" placeholder="Uraian temuan"
                          class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Analisa</label>
                    <textarea id="ef_analysis" name="analysis" rows="4" placeholder="Penyebab / analisa"
                              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Tindakan</label>
                    <textarea id="ef_action" name="action" rows="4" placeholder="Tindakan perbaikan"
                              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Remark</label>
                <textarea id="ef_remark" name="remark" rows="3" maxlength="2000"
                          placeholder="Catatan tambahan, kondisi lapangan, atau hasil tindak lanjut"
                          class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
                <p class="text-[11px] text-slate-400 mt-1">
                    Remark manual disimpan terpisah dan tidak dikosongkan saat sinkronisasi bila kolom Remark di sheet kosong.
                </p>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" onclick="tutupEditFinding()"
                        class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Batal
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

