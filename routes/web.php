<?php

use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FunctionalLocationController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Login routes (no auth)
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Telegram webhook (no auth, called by Telegram)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

// Admin routes (with auth)
Route::middleware('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Assets - import routes MUST be before resource to avoid 404
    Route::get('/assets/import', [ImportController::class, 'showImport'])->name('assets.import');
    Route::post('/assets/import/preview', [ImportController::class, 'previewImport'])->name('assets.import.preview');
    Route::post('/assets/import/execute', [ImportController::class, 'executeImport'])->name('assets.import.execute');
    Route::get('/assets/export/excel', [AssetController::class, 'exportExcel'])->name('assets.export-excel');
    Route::get('/assets/export/csv', [AssetController::class, 'exportCsv'])->name('assets.export-csv');
    Route::get('/assets/locations/departments', [AssetController::class, 'getDepartments'])->name('assets.locations.departments');
    Route::get('/assets/locations/areas', [AssetController::class, 'getAreas'])->name('assets.locations.areas');
    Route::get('/assets/locations/subareas', [AssetController::class, 'getSubAreas'])->name('assets.locations.subareas');
    Route::get('/assets/{asset}/technicians', [AssetController::class, 'getAssignedTechnicians'])->name('assets.technicians');
    Route::post('/assets/{asset}/technicians/assign', [AssetController::class, 'assignTechnician'])->name('assets.technicians.assign');
    Route::delete('/assets/{asset}/technicians/{technician}', [AssetController::class, 'removeTechnician'])->name('assets.technicians.remove');
    Route::post('/assets/{asset}/technicians/broadcast', [AssetController::class, 'broadcastToTechnicians'])->name('assets.technicians.broadcast');
    Route::get('/assets/{asset}/technicians/list', [AssetController::class, 'listTechnicians'])->name('assets.technicians.list');
    Route::resource('assets', AssetController::class);

    // Functional Location
    Route::post('/func-locs/sync/preview', [FunctionalLocationController::class, 'previewSync'])->name('func-locs.sync.preview');
    Route::post('/func-locs/sync/execute', [FunctionalLocationController::class, 'executeSync'])->name('func-locs.sync.execute');
    Route::resource('func-locs', FunctionalLocationController::class)->except(['show']);

    // Technicians
    Route::resource('technicians', TechnicianController::class);
    Route::post('/technicians/{technician}/approve', [TechnicianController::class, 'approve'])->name('technicians.approve');
    Route::post('/technicians/{technician}/suspend', [TechnicianController::class, 'suspend'])->name('technicians.suspend');
    Route::post('/technicians/{technician}/reactivate', [TechnicianController::class, 'reactivate'])->name('technicians.reactivate');
    Route::post('/technicians/bulk-approve', [TechnicianController::class, 'bulkApprove'])->name('technicians.bulk-approve');
    Route::post('/technicians/{technician}/broadcast', [TechnicianController::class, 'broadcast'])->name('technicians.broadcast');

    // Reports - static dan custom routes WAJIB sebelum resource
    // agar tidak ditimpa oleh wildcard {report} milik resource.
    Route::get('/reports/locations/funclocs', [ReportController::class, 'getFuncLocsByArea'])->name('reports.locations.funclocs');
    Route::get('/reports/locations/assets', [ReportController::class, 'getAssetsByArea'])->name('reports.locations.assets');
    Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])->name('reports.export-csv');

    // Hapus satu foto dari laporan — index adalah posisi dalam array JSON (0-based).
    // Constraint [0-9]+ mencegah injeksi nilai non-numerik sebagai index.
    Route::delete('/reports/{report}/photos/{index}', [ReportController::class, 'deletePhoto'])
        ->name('reports.photos.delete')
        ->where('index', '[0-9]+');

    Route::resource('reports', ReportController::class);
    Route::post('/reports/{report}/update-status', [ReportController::class, 'updateStatus'])->name('reports.update-status');
    Route::post('/reports/{report}/photos', [ReportController::class, 'addPhoto'])->name('reports.add-photo');

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
});

// Redirect root to dashboard
Route::get('/', function () {
    return redirect('/dashboard');
});
