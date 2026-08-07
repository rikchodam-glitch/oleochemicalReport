<?php

namespace App\Console\Commands;

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmMonthlyTracking;
use App\Models\CmReading;
use App\Models\ImportLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetCmData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cm:reset
                            {--force : Jalankan tanpa konfirmasi}
                            {--keep-logs : Pertahankan riwayat import_logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hapus SEMUA data Condition Monitoring (equipment, readings, findings, monthly tracking, import logs) untuk test import dari awal.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $counts = [
            'cm_readings'         => CmReading::count(),
            'cm_findings'         => CmFinding::count(),
            'cm_monthly_tracking' => CmMonthlyTracking::count(),
            'cm_equipment'        => CmEquipment::count(),
            'import_logs (cm_excel)' => ImportLog::where('tipe_import', 'cm_excel')->count(),
        ];

        $total = array_sum($counts);

        $this->info('=== RESET DATA CONDITION MONITORING ===');
        $this->newLine();
        $this->table(
            ['Tabel', 'Jumlah Data'],
            collect($counts)->map(fn($v, $k) => [$k, number_format($v)])->values()
        );
        $this->newLine();
        $this->warn("Total: {$total} baris data akan dihapus.");

        if ($total === 0) {
            $this->info('Tidak ada data CM untuk dihapus. Selesai.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Yakin ingin menghapus SEMUA data CM? Ini tidak bisa dibatalkan!')) {
            $this->warn('Dibatalkan.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Menghapus data...');

        DB::transaction(function () {
            // 1. Hapus work_orders yang terhubung ke cm_findings (agar tidak ada relasi menggantung)
            $deletedWo = DB::table('work_orders')
                ->whereNotNull('linked_finding_id')
                ->whereIn('linked_finding_id', CmFinding::pluck('id'))
                ->delete();
            $this->line("  - work_orders terkait CM: {$deletedWo} dihapus");

            // 2. Hapus findings
            $deletedFindings = CmFinding::count();
            CmFinding::query()->delete();
            $this->line("  - cm_findings: {$deletedFindings} dihapus");

            // 3. Hapus monthly tracking
            $deletedTracking = CmMonthlyTracking::count();
            CmMonthlyTracking::query()->delete();
            $this->line("  - cm_monthly_tracking: {$deletedTracking} dihapus");

            // 4. Hapus readings
            $deletedReadings = CmReading::count();
            CmReading::query()->delete();
            $this->line("  - cm_readings: {$deletedReadings} dihapus");

            // 5. Hapus equipment
            $deletedEquipment = CmEquipment::count();
            CmEquipment::query()->delete();
            $this->line("  - cm_equipment: {$deletedEquipment} dihapus");

            // 6. Hapus import logs CM (kecuali opsi keep-logs)
            if (!$this->option('keep-logs')) {
                $deletedLogs = ImportLog::where('tipe_import', 'cm_excel')->delete();
                $this->line("  - import_logs (cm_excel): {$deletedLogs} dihapus");
            } else {
                $this->line('  - import_logs: dipertahankan (--keep-logs)');
            }
        });

        // 7. Hapus file import yang tersimpan
        $files = Storage::files('cm-imports');
        if (count($files) > 0) {
            Storage::delete($files);
            $this->line('  - file cm-imports: ' . count($files) . ' file dihapus');
        }

        // Reset auto_increment agar ID mulai dari 1 lagi
        $this->resetAutoIncrement('cm_equipment');
        $this->resetAutoIncrement('cm_readings');
        $this->resetAutoIncrement('cm_findings');
        $this->resetAutoIncrement('cm_monthly_tracking');
        if (!$this->option('keep-logs')) {
            $this->resetAutoIncrement('import_logs');
        }

        $this->newLine();
        $this->info('✓ Semua data CM berhasil dihapus. Database siap untuk test import dari awal.');

        return self::SUCCESS;
    }

    /**
     * Reset auto_increment pada tabel.
     */
    private function resetAutoIncrement(string $table): void
    {
        try {
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
        } catch (\Throwable $e) {
            // Abaikan jika gagal (misal tabel tidak ada / tidak support)
        }
    }
}
