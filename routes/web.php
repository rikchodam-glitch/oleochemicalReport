<?php

use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BagianAccessController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\CmController;
use App\Http\Controllers\CmImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FunctionalLocationController;
use App\Http\Controllers\HariLiburController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IpAddressController;
use App\Http\Controllers\ItAssetController;
use App\Http\Controllers\ItLayoutController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PanelRuanganController;
use App\Http\Controllers\ReliabilityController;
use App\Http\Controllers\CollaborationController;
use App\Http\Controllers\CutiAdminController;
use App\Http\Controllers\CutiSayaController;
use App\Http\Controllers\CutiSemuaController;
use App\Http\Controllers\ReportCollaborationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportFormController;
use App\Http\Controllers\ReportMultiItemController;
use App\Http\Controllers\SparepartController;
use App\Http\Controllers\SparepartPantauController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\ShiftScheduleController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Login routes (no auth)
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Telegram webhook (no auth, called by Telegram)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

// Form laporan publik dari token /laporan (tanpa login panel).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/laporan/form/{token}', [ReportFormController::class, 'form'])->name('laporan.form');
    Route::post('/laporan/form/{token}', [ReportFormController::class, 'simpan'])->name('laporan.simpan');
    Route::get('/laporan/selesai', [ReportFormController::class, 'selesai'])->name('laporan.selesai');
    Route::get('/laporan/{token}/tipe-alat', [ReportFormController::class, 'tipeAlat'])->name('laporan.api.tipe');
    Route::get('/laporan/{token}/cari-alat', [ReportFormController::class, 'cariAlat'])->name('laporan.api.alat');
});

// Cuti teknisi:
// - teknisi login hanya MELIHAT cuti miliknya;
// - Admin Reguler/Administrator mencatat cuti dari halaman teknisi.
Route::middleware('admin')->group(function () {
    Route::get('/cuti-saya', [CutiSayaController::class, 'index'])->name('cuti.saya');
    Route::get('/cuti-semua', [CutiSemuaController::class, 'index'])->name('cuti.semua');

    // Form laporan web untuk teknisi login (di luar hanya_baca & akses modul;
    // controller memastikan akun terhubung ke teknisi aktif).
    Route::get('/laporan-saya/buat', [ReportFormController::class, 'create'])->name('laporan.saya.create');

    Route::get('/cuti-kelola', [CutiAdminController::class, 'index'])->name('cuti.kelola.index');
    Route::post('/cuti-kelola', [CutiAdminController::class, 'store'])->name('cuti.kelola.store');
    Route::get('/cuti-kelola/{cuti}/edit', [CutiAdminController::class, 'edit'])->name('cuti.kelola.edit');
    Route::put('/cuti-kelola/{cuti}', [CutiAdminController::class, 'update'])->name('cuti.kelola.update');
    Route::delete('/cuti-kelola/{cuti}', [CutiAdminController::class, 'destroy'])->name('cuti.kelola.destroy');
});

// DEBUG ONLY — auto-login untuk pengembangan lokal (hapus sebelum production!)
if (app()->environment('local')) {
    Route::get('/debug-login', function () {
        Auth::loginUsingId(1);
        return redirect('/dashboard');
    })->name('debug.login');
}

// Admin routes (with auth)
Route::middleware(['admin', 'akses', 'hanya_baca'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Assets - import routes MUST be before resource to avoid 404
    Route::get('/assets/import', [ImportController::class, 'showImport'])->name('assets.import');
    Route::post('/assets/import/preview', [ImportController::class, 'previewImport'])->name('assets.import.preview');
    Route::post('/assets/import/execute', [ImportController::class, 'executeImport'])->name('assets.import.execute');
    Route::get('/assets/import/template', [ImportController::class, 'downloadTemplate'])->name('assets.import.template');
    Route::get('/assets/export/excel', [AssetController::class, 'exportExcel'])->name('assets.export-excel');
    Route::get('/assets/export/csv', [AssetController::class, 'exportCsv'])->name('assets.export-csv');
    Route::get('/assets/locations/departments', [AssetController::class, 'getDepartments'])->name('assets.locations.departments');
    Route::get('/assets/locations/areas', [AssetController::class, 'getAreas'])->name('assets.locations.areas');
    Route::get('/assets/locations/subareas', [AssetController::class, 'getSubAreas'])->name('assets.locations.subareas');
Route::get('/assets/locations/next-equipment-no', [AssetController::class, 'nextEquipmentNo'])->name('assets.locations.next-equipment-no');

    // Rekonsiliasi manual FuncLoc — WAJIB sebelum resource agar tidak
    // ditimpa wildcard {asset} milik resource (assets.show).
    Route::get('/assets/unlinked-funcloc', [AssetController::class, 'unlinked'])->name('assets.unlinked-funcloc');
    Route::post('/assets/unlinked-funcloc/run-sync', [AssetController::class, 'runAutoSync'])->name('assets.unlinked-funcloc.run-sync');
    Route::post('/assets/bulk-link-funcloc', [AssetController::class, 'bulkLinkFuncLoc'])->name('assets.bulk-link-funcloc');
    Route::patch('/assets/{asset}/link-funcloc', [AssetController::class, 'linkFuncLoc'])->name('assets.link-funcloc');

    Route::get('/assets/{asset}/technicians', [AssetController::class, 'getAssignedTechnicians'])->name('assets.technicians');
    Route::post('/assets/{asset}/technicians/assign', [AssetController::class, 'assignTechnician'])->name('assets.technicians.assign');
    Route::delete('/assets/{asset}/technicians/{technician}', [AssetController::class, 'removeTechnician'])->name('assets.technicians.remove');
    Route::post('/assets/{asset}/technicians/broadcast', [AssetController::class, 'broadcastToTechnicians'])->name('assets.technicians.broadcast');
    Route::get('/assets/{asset}/technicians/list', [AssetController::class, 'listTechnicians'])->name('assets.technicians.list');
    Route::resource('assets', AssetController::class);

    // Functional Location - route pencarian & lazy-load children untuk
    // komponen picker (<x-funcloc-picker>). WAJIB sebelum resource agar
    // tidak ditimpa oleh wildcard {func_loc} milik resource.
    Route::get('/func-locs/search', [FunctionalLocationController::class, 'search'])->name('func-locs.search');
    Route::get('/func-locs/children', [FunctionalLocationController::class, 'children'])->name('func-locs.children');

    // Pindah node (beserta subtree) dan hapus permanen node nonaktif — WAJIB
    // sebelum resource agar tidak ditimpa wildcard {func_loc} milik resource.
    Route::get('/func-locs/{func_loc}/move', [FunctionalLocationController::class, 'move'])->name('func-locs.move');
    Route::put('/func-locs/{func_loc}/move', [FunctionalLocationController::class, 'moveUpdate'])->name('func-locs.move.update');
    Route::delete('/func-locs/{func_loc}/force', [FunctionalLocationController::class, 'forceDestroy'])->name('func-locs.force-destroy');

    // Functional Location
    Route::post('/func-locs/sync/preview', [FunctionalLocationController::class, 'previewSync'])->name('func-locs.sync.preview');
    Route::post('/func-locs/sync/execute', [FunctionalLocationController::class, 'executeSync'])->name('func-locs.sync.execute');
    Route::resource('func-locs', FunctionalLocationController::class)->except(['show']);

    // Halaman detail lokasi: menampilkan alat + seluruh laporan pada satu lokasi
    // (dipakai dari kolom "Lokasi / Alat" halaman Laporan dan halaman detail Asset).
    Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');

    // Technicians
    Route::post('/technicians/shift-schedule', [ShiftScheduleController::class, 'store'])->name('technicians.shift-schedule.store');
    // Hari libur nasional dari kalender teknisi (rute statis WAJIB sebelum
    // resource agar tidak ditimpa wildcard {technician}).
    Route::post('/technicians/hari-libur', [HariLiburController::class, 'store'])->name('technicians.hari-libur.store');
    Route::delete('/technicians/hari-libur/{hariLibur}', [HariLiburController::class, 'destroy'])->name('technicians.hari-libur.destroy');
    Route::resource('technicians', TechnicianController::class);
    Route::post('/technicians/{technician}/approve', [TechnicianController::class, 'approve'])->name('technicians.approve');
    Route::post('/technicians/{technician}/suspend', [TechnicianController::class, 'suspend'])->name('technicians.suspend');
    Route::post('/technicians/{technician}/reactivate', [TechnicianController::class, 'reactivate'])->name('technicians.reactivate');
    Route::post('/technicians/bulk-approve', [TechnicianController::class, 'bulkApprove'])->name('technicians.bulk-approve');
    Route::post('/technicians/{technician}/broadcast', [TechnicianController::class, 'broadcast'])->name('technicians.broadcast');

    // Kolaborasi (kelompok otomatis "ikut andil" saat salah satu anggotanya membuat laporan)
    Route::get('/collaboration', [CollaborationController::class, 'index'])->name('collaboration.index');
    Route::post('/collaboration/groups', [CollaborationController::class, 'store'])->name('collaboration.groups.store');
    Route::put('/collaboration/groups/{group}', [CollaborationController::class, 'update'])->name('collaboration.groups.update');
    Route::delete('/collaboration/groups/{group}', [CollaborationController::class, 'destroy'])->name('collaboration.groups.destroy');
    Route::post('/collaboration/groups/{group}/members', [CollaborationController::class, 'addMember'])->name('collaboration.groups.members.add');
    Route::delete('/collaboration/groups/{group}/members/{technician}', [CollaborationController::class, 'removeMember'])->name('collaboration.groups.members.remove');
    Route::post('/collaboration/groups/{group}/sync', [CollaborationController::class, 'syncMembers'])->name('collaboration.groups.sync');

    // Reports - static dan custom routes WAJIB sebelum resource
    // agar tidak ditimpa oleh wildcard {report} milik resource.
    Route::get('/reports/locations/funclocs', [ReportController::class, 'getFuncLocsByArea'])->name('reports.locations.funclocs');
    Route::get('/reports/locations/assets', [ReportController::class, 'getAssetsByArea'])->name('reports.locations.assets');
    // Pencarian alat lintas area untuk kotak "Cari Alat" pada form edit laporan.
    Route::get('/reports/locations/cari-alat', [ReportController::class, 'cariAsset'])->name('reports.locations.cari-alat');
    // Pemilih lokasi bertingkat (PT -> Departemen -> Area -> Section -> Alat)
    // pada mode edit laporan: satu permintaan mengembalikan node anak + alat.
    Route::get('/reports/locations/hirarki', [ReportController::class, 'anakLokasi'])->name('reports.locations.hirarki');
    // Export laporan: Excel (.xlsx, tiga lembar) dan CSV. Keduanya memakai
    // filter yang sama dengan halaman Laporan plus kunci khusus popup export
    // (jenis_pekerjaan_multi[], tanpa_batas).
    Route::get('/reports/export/excel', [ReportController::class, 'exportExcel'])->name('reports.export-excel');
    Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])->name('reports.export-csv');

    // Hapus satu foto dari laporan — index adalah posisi dalam array JSON (0-based).
    // Constraint [0-9]+ mencegah injeksi nilai non-numerik sebagai index.
    Route::delete('/reports/{report}/photos/{index}', [ReportController::class, 'deletePhoto'])
        ->name('reports.photos.delete')
        ->where('index', '[0-9]+');

    // Halaman edit terpisah (reports.edit) sudah DIHAPUS: seluruh pengeditan
    // laporan dilakukan dari halaman detail (mode Edit pada kartu Aksi Admin).
    Route::resource('reports', ReportController::class)->except(['edit']);
    Route::post('/reports/{report}/update-status', [ReportController::class, 'updateStatus'])->name('reports.update-status');
    Route::post('/reports/{report}/photos', [ReportController::class, 'addPhoto'])->name('reports.add-photo');

    // Kolaborasi laporan: tambah/hapus teknisi kolaborator dari halaman edit.
    // Setiap kolaborator menjadi baris salinan laporan sendiri (collaborator_of).
    Route::post('/reports/{report}/collaborators', [ReportCollaborationController::class, 'store'])->name('reports.collaborators.store');
    Route::delete('/reports/{report}/collaborators/{kolaborator}', [ReportCollaborationController::class, 'destroy'])->name('reports.collaborators.destroy');

    // Alat/lokasi tambahan (pekerjaan multi item) dari halaman edit: setiap item
    // menjadi baris salinan laporan sendiri (multi_of) agar riwayat alat/lokasi
    // tetap lengkap. Baris salinan ini tidak dihitung di man hour.
    Route::post('/reports/{report}/multi-items', [ReportMultiItemController::class, 'store'])->name('reports.multi-items.store');
    Route::delete('/reports/{report}/multi-items/{item}', [ReportMultiItemController::class, 'destroy'])->name('reports.multi-items.destroy');

    // Condition Monitoring
    Route::prefix('cm')->name('cm.')->group(function () {
        Route::get('/overview', [CmController::class, 'overview'])->name('overview');
        Route::get('/findings', [CmController::class, 'findings'])->name('findings');
        Route::get('/findings/{kodeFinding}', [CmController::class, 'findingShow'])->name('findings.show');
        Route::get('/monitoring', [CmController::class, 'monitoring'])->name('monitoring');
        Route::get('/equipment-status', [CmController::class, 'equipmentStatus'])->name('equipment-status');
        Route::get('/equipment/{tag}', [CmController::class, 'equipmentShow'])->name('equipment-show');
        Route::put('/equipment/{tag}', [CmController::class, 'equipmentUpdate'])->name('equipment-update');
        Route::delete('/readings/{reading}', [CmController::class, 'readingDestroy'])->name('readings.destroy');
        Route::delete('/findings/{finding}', [CmController::class, 'findingDestroy'])->name('findings.destroy');
        Route::post('/sync-google-sheets', [CmController::class, 'syncGoogleSheets'])->name('sync-google-sheets');
        Route::get('/report-analysis', [CmController::class, 'reportAnalysis'])->name('report-analysis');
        Route::get('/trend-chart-data', [CmController::class, 'trendChartData'])->name('trend-chart-data');
        Route::get('/donut-data', [CmController::class, 'donutData'])->name('donut-data');
        Route::get('/overview-summary', [CmController::class, 'overviewSummary'])->name('overview-summary');

        // Import Excel
        Route::get('/import', [CmImportController::class, 'showImport'])->name('import.show');
        Route::post('/import/upload', [CmImportController::class, 'uploadImport'])->name('import.upload');
        Route::get('/import/status/{id}', [CmImportController::class, 'checkStatus'])->name('import.status');

        // Export
        Route::get('/export/readings', [CmController::class, 'exportReadings'])->name('export-readings');
        Route::get('/export/findings', [CmController::class, 'exportFindings'])->name('export-findings');
        Route::get('/export/monitoring', [CmController::class, 'exportMonitoring'])->name('export-monitoring');
        Route::get('/export/analysis', [CmController::class, 'exportAnalysis'])->name('export-analysis');
    });

    // AI Providers
    Route::get('/ai-providers', [AiProviderController::class, 'index'])->name('ai-providers.index');
    Route::post('/ai-providers', [AiProviderController::class, 'store'])->name('ai-providers.store');

    // Static action routes — WAJIB sebelum {aiProvider} wildcard
    Route::post('/ai-providers/test-all', [AiProviderController::class, 'testAll'])->name('ai-providers.test-all');
    Route::post('/ai-providers/reset-quota', [AiProviderController::class, 'resetQuota'])->name('ai-providers.reset-quota');

    // BUG PANEL 5 FIX — Route alias baru (belum ada sebelumnya)
    Route::post('/ai-providers/aliases/{alias}/confirm', [AiProviderController::class, 'confirmAlias'])->name('ai-providers.aliases.confirm');
    Route::post('/ai-providers/aliases/{alias}/reject', [AiProviderController::class, 'rejectAlias'])->name('ai-providers.aliases.reject');

    // Wildcard provider routes — setelah static routes
    Route::put('/ai-providers/{aiProvider}', [AiProviderController::class, 'update'])->name('ai-providers.update');
    Route::delete('/ai-providers/{aiProvider}', [AiProviderController::class, 'destroy'])->name('ai-providers.destroy');
    Route::post('/ai-providers/{aiProvider}/test', [AiProviderController::class, 'test'])->name('ai-providers.test');
    Route::post('/ai-providers/{aiProvider}/refresh-models', [AiProviderController::class, 'refreshModels'])->name('ai-providers.refresh-models');

    // Reliability Dashboard
    Route::get('/reliability', [ReliabilityController::class, 'index'])->name('reliability.index');

    // Work Orders
    Route::resource('work-orders', WorkOrderController::class);

    // IT Assets
    Route::get('/it-assets', [ItAssetController::class, 'index'])->name('it-assets.index');
    Route::get('/it-assets/notifications', [ItAssetController::class, 'notifications'])->name('it-assets.notifications');
    Route::post('/it-assets/notifications/read-all', [ItAssetController::class, 'markAllNotificationsRead'])->name('it-assets.notifications.read-all');
    Route::post('/it-assets/notifications/{notification}/read', [ItAssetController::class, 'markNotificationRead'])->name('it-assets.notifications.read');
    Route::get('/it-assets/create', [ItAssetController::class, 'create'])->name('it-assets.create');
    Route::post('/it-assets', [ItAssetController::class, 'store'])->name('it-assets.store');
    Route::get('/it-assets/import', [ItAssetController::class, 'showImport'])->name('it-assets.import');
    Route::post('/it-assets/import', [ItAssetController::class, 'import'])->name('it-assets.import.execute');
    // Denah / Peta Letak — penempatan perangkat IT di atas denah PDF/gambar.
    Route::get('/it-assets/layouts', [ItLayoutController::class, 'index'])->name('it-assets.layouts.index');
    Route::get('/it-assets/layouts/create', [ItLayoutController::class, 'create'])->name('it-assets.layouts.create');
    Route::post('/it-assets/layouts', [ItLayoutController::class, 'store'])->name('it-assets.layouts.store');
    Route::get('/it-assets/layouts/{layout}/preview', [ItLayoutController::class, 'preview'])->name('it-assets.layouts.preview');
    Route::get('/it-assets/layouts/{layout}', [ItLayoutController::class, 'show'])->name('it-assets.layouts.show');
    Route::delete('/it-assets/layouts/{layout}', [ItLayoutController::class, 'destroy'])->name('it-assets.layouts.destroy');
    Route::post('/it-assets/layouts/{layout}/place', [ItLayoutController::class, 'placeAsset'])->name('it-assets.layouts.place');
    Route::post('/it-assets/layouts/{layout}/links', [ItLayoutController::class, 'storeLink'])->name('it-assets.layouts.store-link');
    Route::delete('/it-assets/layout-items/{item}', [ItLayoutController::class, 'removeAsset'])->name('it-assets.layouts.remove-asset');
    Route::patch('/it-assets/layout-items/{item}', [ItLayoutController::class, 'moveAsset'])->name('it-assets.layouts.move-asset');
    Route::patch('/it-assets/layout-items/{item}/lock', [ItLayoutController::class, 'toggleLock'])->name('it-assets.layouts.toggle-lock');
    Route::delete('/it-assets/network-links/{networkLink}', [ItLayoutController::class, 'destroyLink'])->name('it-assets.network-links.destroy');
    // Daftar IP — subnet VLAN + CIDR dengan generate otomatis (ala Netbox).
    Route::get('/it-assets/ip-addresses', [IpAddressController::class, 'index'])->name('it-assets.ip-addresses.index');
    Route::post('/it-assets/ip-addresses', [IpAddressController::class, 'store'])->name('it-assets.ip-addresses.store');
    // Pencarian perangkat & penetapan IP untuk popup Daftar IP. WAJIB sebelum
    // route {network} supaya 'asset-search' tidak ditangkap wildcard tersebut.
    Route::get('/it-assets/ip-addresses/asset-search', [IpAddressController::class, 'assetSearch'])->name('it-assets.ip-addresses.asset-search');
    Route::patch('/it-assets/ip-addresses/address/{ipAddress}', [IpAddressController::class, 'assign'])->name('it-assets.ip-addresses.assign');
    Route::post('/it-assets/ip-addresses/address/{ipAddress}/device', [IpAddressController::class, 'storeDevice'])->name('it-assets.ip-addresses.store-device');
    Route::get('/it-assets/ip-addresses/{network}', [IpAddressController::class, 'show'])->name('it-assets.ip-addresses.show');
    Route::delete('/it-assets/ip-addresses/{network}', [IpAddressController::class, 'destroy'])->name('it-assets.ip-addresses.destroy');
    Route::get('/it-assets/{itAsset}', [ItAssetController::class, 'show'])->name('it-assets.show');
    Route::get('/it-assets/{itAsset}/edit', [ItAssetController::class, 'edit'])->name('it-assets.edit');
    Route::put('/it-assets/{itAsset}', [ItAssetController::class, 'update'])->name('it-assets.update');
    Route::delete('/it-assets/{itAsset}', [ItAssetController::class, 'destroy'])->name('it-assets.destroy');
    Route::post('/it-assets/{itAsset}/ping', [ItAssetController::class, 'ping'])->name('it-assets.ping');
    Route::put('/it-assets/{itAsset}/snapshot-settings', [ItAssetController::class, 'updateSnapshotSettings'])->name('it-assets.snapshot-settings');
    Route::post('/it-assets/{itAsset}/snapshot', [ItAssetController::class, 'snapshot'])->name('it-assets.snapshot');
    // Bot
    Route::get('/bot', [BotController::class, 'index'])->name('bot.index');
    Route::post('/bot/settings', [BotController::class, 'updateSettings'])->name('bot.settings.update');
    Route::post('/bot/test-connection', [BotController::class, 'testConnection'])->name('bot.test-connection');
    Route::post('/bot/set-webhook', [BotController::class, 'setWebhook'])->name('bot.set-webhook');
    Route::post('/bot/delete-webhook', [BotController::class, 'deleteWebhook'])->name('bot.delete-webhook');
    Route::post('/bot/registrations/{registration}/approve', [BotController::class, 'approveRegistration'])->name('bot.registrations.approve');
    Route::post('/bot/registrations/{registration}/approve-with-details', [BotController::class, 'approveWithDetails'])->name('bot.registrations.approve-with-details');
    Route::post('/bot/registrations/{registration}/reject', [BotController::class, 'rejectRegistration'])->name('bot.registrations.reject');
    Route::post('/bot/polling/start', [BotController::class, 'startPolling'])->name('bot.polling.start');
    Route::post('/bot/polling/stop', [BotController::class, 'stopPolling'])->name('bot.polling.stop');
    Route::get('/bot/polling/status', [BotController::class, 'pollingStatus'])->name('bot.polling.status');

    // Panel penyimpanan: deteksi drive/folder/database, pembersih snapshot
    // otomatis, dan pemindahan data ke HDD lewat junction.
    // Catatan: TIDAK memakai path '/storage' karena folder fisik
    // public/storage (symlink ke storage/app/public) lebih dulu ditangani
    // Apache, sehingga rute Laravel di path itu tidak pernah tercapai.
    Route::get('/system/storage', [StorageController::class, 'index'])->name('storage.index');
    Route::post('/system/storage/clean', [StorageController::class, 'clean'])->name('storage.clean');
    Route::post('/system/storage/relocate', [StorageController::class, 'relocate'])->name('storage.relocate');
    Route::post('/system/storage/database', [StorageController::class, 'relocateDatabase'])->name('storage.database.relocate');
    Route::post('/system/storage/restore', [StorageController::class, 'restore'])->name('storage.restore');

    // Monitoring Ruang Panel: pendataan ruangan panel dan pembacaan suhu
    // serta kelembapan dari perangkat sensor (mode tarik / pull, jadi tidak
    // memerlukan aturan port masuk di firewall komputer ini).
    Route::get('/panel', [PanelRuanganController::class, 'index'])->name('panel.index');
    Route::get('/panel/data', [PanelRuanganController::class, 'data'])->name('panel.data');

    // Pengaturan lengkap tiap ruangan (form tambah/ubah dan halaman detail)
    // dipisah dari dashboard supaya halaman monitoring tetap ringan. Route
    // '/panel/ruangan/create' WAJIB ditulis sebelum '/panel/ruangan/{id}'
    // agar kata "create" tidak terbaca sebagai id ruangan.
    Route::get('/panel/ruangan/create', [PanelRuanganController::class, 'create'])->name('panel.ruangan.create');
    Route::get('/panel/ruangan/{panelRuangan}/edit', [PanelRuanganController::class, 'edit'])->name('panel.ruangan.edit');
    Route::post('/panel/ruangan/{panelRuangan}/sinkron-aset', [PanelRuanganController::class, 'sinkronAset'])->name('panel.ruangan.sinkron-aset');
    // Data grafik realtime dan ekspor riwayat harian satu ruangan. Keduanya
    // ditulis sebelum rute '/panel/ruangan/{id}' supaya segmen tambahan tidak
    // pernah tertangkap rute detail.
    Route::get('/panel/ruangan/{panelRuangan}/data', [PanelRuanganController::class, 'dataRuangan'])->name('panel.ruangan.data');
    Route::get('/panel/ruangan/{panelRuangan}/riwayat-harian/export', [PanelRuanganController::class, 'exportHarian'])->name('panel.ruangan.export-harian');
    Route::get('/panel/ruangan/{panelRuangan}', [PanelRuanganController::class, 'show'])->name('panel.ruangan.show');
    Route::post('/panel/ruangan', [PanelRuanganController::class, 'store'])->name('panel.ruangan.store');
    Route::post('/panel/ruangan/{panelRuangan}/kirim-pengingat', [PanelRuanganController::class, 'kirimPengingat'])->name('panel.ruangan.kirim-pengingat');
    Route::post('/panel/ruangan/{panelRuangan}/uji-koneksi', [PanelRuanganController::class, 'ujiKoneksi'])->name('panel.ruangan.uji-koneksi');
    Route::post('/panel/ruangan/{panelRuangan}/tarik', [PanelRuanganController::class, 'tarik'])->name('panel.ruangan.tarik');
    Route::put('/panel/ruangan/{panelRuangan}', [PanelRuanganController::class, 'update'])->name('panel.ruangan.update');
    Route::delete('/panel/ruangan/{panelRuangan}', [PanelRuanganController::class, 'destroy'])->name('panel.ruangan.destroy');

    // Integrasi PR/PO Spare Part: daftar pantau (populasi material), keadaan
    // terkini per kode material (hasil upsert tiap import), import berkas, dan
    // riwayat import. Halaman awal langsung berupa tabel keadaan.
    Route::get('/sparepart', [SparepartController::class, 'index'])->name('sparepart.index');
    Route::get('/sparepart/export', [SparepartController::class, 'export'])->name('sparepart.export');
    Route::get('/sparepart/impor', [SparepartController::class, 'impor'])->name('sparepart.impor');
    Route::post('/sparepart/impor', [SparepartController::class, 'imporProses'])->name('sparepart.impor.proses');
    Route::get('/sparepart/riwayat', [SparepartController::class, 'riwayat'])->name('sparepart.riwayat');

    // Daftar pantau: sumber populasi halaman di atas, dapat diimpor maupun
    // diedit manual (tambah/ubah/hapus).
    Route::get('/sparepart/pantau', [SparepartPantauController::class, 'index'])->name('sparepart.pantau');
    Route::post('/sparepart/pantau', [SparepartPantauController::class, 'store'])->name('sparepart.pantau.store');
    Route::post('/sparepart/pantau/impor', [SparepartPantauController::class, 'impor'])->name('sparepart.pantau.impor');
    Route::put('/sparepart/pantau/{pantau}', [SparepartPantauController::class, 'update'])->name('sparepart.pantau.update');
    Route::delete('/sparepart/pantau/{pantau}', [SparepartPantauController::class, 'destroy'])->name('sparepart.pantau.destroy');

    // Manajemen user (akun teknisi) dan hak akses halaman per Bagian.
    // Route statis WAJIB ditulis sebelum route berparameter /users/{user}
    // supaya tidak tertangkap wildcard.
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::post('/users/generate-teknisi', [UserController::class, 'generateTeknisi'])->name('users.generate-teknisi');
    Route::get('/users/akses', [BagianAccessController::class, 'index'])->name('akses.index');
    Route::post('/users/akses/{bagian}', [BagianAccessController::class, 'update'])->name('akses.update');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
});

// Redirect root to dashboard
Route::get('/', function () {
    return redirect('/dashboard');
});
