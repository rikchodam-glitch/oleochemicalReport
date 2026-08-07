<?php

namespace Database\Seeders;

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmMonthlyTracking;
use App\Models\CmReading;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CmSeeder extends Seeder
{
    /**
     * Data equipment yang realistis — 40 equipment tersebar di 3 PT dan beberapa plant.
     */
    private array $equipmentData = [
        // PT. EPE — BD 1
        ['tag' => 'PM-0001', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Grease'],
        ['tag' => 'PM-0002', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Oil Bath'],
        ['tag' => 'PU-0001', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Grease'],
        ['tag' => 'PU-0002', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Oil Mist'],
        ['tag' => 'FN-0001', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Grease'],
        ['tag' => 'BL-0001', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Oil Bath'],
        ['tag' => 'CV-0001', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Grease'],
        ['tag' => 'AG-0001', 'pt' => 'PT. EPE', 'plant' => 'BD 1', 'lub' => 'Oil Bath'],

        // PT. EPE — MD
        ['tag' => 'PM-0003', 'pt' => 'PT. EPE', 'plant' => 'MD', 'lub' => 'Grease'],
        ['tag' => 'PU-0003', 'pt' => 'PT. EPE', 'plant' => 'MD', 'lub' => 'Oil Mist'],
        ['tag' => 'MX-0001', 'pt' => 'PT. EPE', 'plant' => 'MD', 'lub' => 'Oil Bath'],
        ['tag' => 'CP-0001', 'pt' => 'PT. EPE', 'plant' => 'MD', 'lub' => 'Grease'],

        // PT. EPE — OSBL 1
        ['tag' => 'PM-0004', 'pt' => 'PT. EPE', 'plant' => 'OSBL 1', 'lub' => 'Oil Bath'],
        ['tag' => 'DR-0001', 'pt' => 'PT. EPE', 'plant' => 'OSBL 1', 'lub' => 'Grease'],
        ['tag' => 'CM-0001', 'pt' => 'PT. EPE', 'plant' => 'OSBL 1', 'lub' => 'Oil Mist'],

        // PT. CES — BD 1
        ['tag' => 'PM-0005', 'pt' => 'PT. CES', 'plant' => 'BD 1', 'lub' => 'Grease'],
        ['tag' => 'PU-0004', 'pt' => 'PT. CES', 'plant' => 'BD 1', 'lub' => 'Oil Bath'],
        ['tag' => 'FN-0002', 'pt' => 'PT. CES', 'plant' => 'BD 1', 'lub' => 'Grease'],
        ['tag' => 'BL-0002', 'pt' => 'PT. CES', 'plant' => 'BD 1', 'lub' => 'Oil Mist'],
        ['tag' => 'CV-0002', 'pt' => 'PT. CES', 'plant' => 'BD 1', 'lub' => 'Grease'],
        ['tag' => 'AG-0002', 'pt' => 'PT. CES', 'plant' => 'BD 1', 'lub' => 'Oil Bath'],
        ['tag' => 'MX-0002', 'pt' => 'PT. CES', 'plant' => 'BD 1', 'lub' => 'Grease'],

        // PT. CES — RG 2
        ['tag' => 'PM-0006', 'pt' => 'PT. CES', 'plant' => 'RG 2', 'lub' => 'Oil Bath'],
        ['tag' => 'PU-0005', 'pt' => 'PT. CES', 'plant' => 'RG 2', 'lub' => 'Grease'],
        ['tag' => 'CP-0002', 'pt' => 'PT. CES', 'plant' => 'RG 2', 'lub' => 'Oil Mist'],
        ['tag' => 'DR-0002', 'pt' => 'PT. CES', 'plant' => 'RG 2', 'lub' => 'Grease'],
        ['tag' => 'CM-0002', 'pt' => 'PT. CES', 'plant' => 'RG 2', 'lub' => 'Oil Bath'],

        // PT. CES — OSBL 2
        ['tag' => 'PM-0007', 'pt' => 'PT. CES', 'plant' => 'OSBL 2', 'lub' => 'Grease'],
        ['tag' => 'PU-0006', 'pt' => 'PT. CES', 'plant' => 'OSBL 2', 'lub' => 'Oil Mist'],
        ['tag' => 'FN-0003', 'pt' => 'PT. CES', 'plant' => 'OSBL 2', 'lub' => 'Grease'],

        // PT. NPA — MD
        ['tag' => 'PM-0008', 'pt' => 'PT. NPA', 'plant' => 'MD', 'lub' => 'Oil Bath'],
        ['tag' => 'PU-0007', 'pt' => 'PT. NPA', 'plant' => 'MD', 'lub' => 'Grease'],
        ['tag' => 'BL-0003', 'pt' => 'PT. NPA', 'plant' => 'MD', 'lub' => 'Oil Mist'],
        ['tag' => 'MX-0003', 'pt' => 'PT. NPA', 'plant' => 'MD', 'lub' => 'Grease'],

        // PT. NPA — OSBL 1
        ['tag' => 'PM-0009', 'pt' => 'PT. NPA', 'plant' => 'OSBL 1', 'lub' => 'Grease'],
        ['tag' => 'PU-0008', 'pt' => 'PT. NPA', 'plant' => 'OSBL 1', 'lub' => 'Oil Bath'],
        ['tag' => 'CV-0003', 'pt' => 'PT. NPA', 'plant' => 'OSBL 1', 'lub' => 'Grease'],
        ['tag' => 'CM-0003', 'pt' => 'PT. NPA', 'plant' => 'OSBL 1', 'lub' => 'Oil Mist'],

        // PT. NPA — RG 2
        ['tag' => 'PM-0010', 'pt' => 'PT. NPA', 'plant' => 'RG 2', 'lub' => 'Oil Bath'],
        ['tag' => 'PU-0009', 'pt' => 'PT. NPA', 'plant' => 'RG 2', 'lub' => 'Grease'],
        ['tag' => 'AG-0003', 'pt' => 'PT. NPA', 'plant' => 'RG 2', 'lub' => 'Grease'],
        ['tag' => 'DR-0003', 'pt' => 'PT. NPA', 'plant' => 'RG 2', 'lub' => 'Oil Bath'],
    ];

    /** Proporsi kondisi: ~75% good, ~5% alarm, ~5% danger, ~15% visual_bad */
    private array $kondisiPool = [
        'good', 'good', 'good', 'good', 'good',
        'good', 'good', 'good', 'good', 'good',
        'good', 'good', 'good', 'good', 'good',
        'alarm', 'alarm', 'alarm',
        'danger', 'danger',
        'visual_bad', 'visual_bad', 'visual_bad', 'visual_bad', 'visual_bad',
        'visual_bad', 'visual_bad',
    ];

    private array $kategoriFinding = [
        'Leak', 'Corrosion', 'High Vibration', 'Safety', 'Coupling Looseness',
    ];

    private array $deskripsiFinding = [
        'Terdeteksi kebocoran pada mechanical seal',
        'Terjadi korosi pada casing pompa bagian bawah',
        'Vibrasi tinggi pada motor drive end melebihi batas',
        'Guard pengaman tidak terpasang dengan benar',
        'Coupling longgar, perlu pengencangan baut',
        'Surface rust pada flange connection',
        'Abnormal noise dari bearing housing',
        'Oil leak pada gearbox',
        'Crack pada foundation bolt',
        'Misalignment antara motor dan pompa',
        'Panel indikator suhu menunjukkan temperatur abnormal',
        'Gasket rusak, perlu penggantian',
        'Bearing temperature melebihi batas operasi',
        'Kebocoran minor pada drain valve',
        'Coupling rubber bush aus',
    ];

    private array $namaPIC = [
        'Ahmad Fauzi', 'Budi Santoso', 'Citra Dewi', 'Dedi Hermawan',
        'Eko Prasetyo', 'Fitriani', 'Gunawan', 'Hendra Lesmana',
        'Indra Setiawan', 'Joko Susilo',
    ];

    public function run(): void
    {
        $this->command->info('Seeding CM Equipment...');
        $equipments = $this->seedEquipments();

        $this->command->info('Seeding CM Readings...');
        $this->seedReadings($equipments);

        $this->command->info('Seeding CM Findings...');
        $this->seedFindings($equipments);

        $this->command->info('Seeding CM Monthly Tracking...');
        $this->seedMonthlyTracking($equipments);

        $this->command->info('CM Seeder selesai.');
    }

    /**
     * Seed tabel cm_equipment.
     *
     * @return \Illuminate\Support\Collection<CmEquipment>
     */
    private function seedEquipments()
    {
        $now = now();
        $inserts = [];

        foreach ($this->equipmentData as $data) {
            $inserts[] = [
                'equipment_tag'  => $data['tag'],
                'pt_location'    => $data['pt'],
                'plant'          => $data['plant'],
                'tipe_lubrikasi' => $data['lub'],
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        CmEquipment::insert($inserts);

        return CmEquipment::all()->keyBy('equipment_tag');
    }

    /**
     * Seed tabel cm_readings — tiap equipment dapat ~8-14 readings
     * dalam 12 bulan terakhir.
     *
     * @param  \Illuminate\Support\Collection  $equipments
     */
    private function seedReadings($equipments): void
    {
        $now = now();
        $inserts = [];

        foreach ($equipments as $tag => $eq) {
            $numReadings = rand(8, 14);
            $dates = [];

            for ($i = 0; $i < $numReadings; $i++) {
                $daysAgo = rand(10, 365);
                $date = $now->copy()->subDays($daysAgo)->format('Y-m-d');

                // Hindari duplikasi tanggal per equipment
                if (in_array($date, $dates)) {
                    continue;
                }
                $dates[] = $date;

                $kondisi = $this->kondisiPool[array_rand($this->kondisiPool)];

                $reading = $this->generateReadingValues($kondisi);

                $inserts[] = [
                    'cm_equipment_id' => $eq->id,
                    'tanggal'         => $date,
                    'status'          => ['start', 'no_vib', 'running', 'stop'][rand(0, 3)],
                    'ndev_motor'      => $reading['ndev_motor'],
                    'ndeh_motor'      => $reading['ndeh_motor'],
                    'ndea_motor'      => $reading['ndea_motor'],
                    'temp_de_motor'   => $reading['temp_de_motor'],
                    'dev_motor'       => $reading['dev_motor'],
                    'deh_motor'       => $reading['deh_motor'],
                    'dea_motor'       => $reading['dea_motor'],
                    'ndev_pompa'      => $reading['ndev_pompa'],
                    'ndeh_pompa'      => $reading['ndeh_pompa'],
                    'ndea_pompa'      => $reading['ndea_pompa'],
                    'temp_de_pompa'   => $reading['temp_de_pompa'],
                    'dev_pompa'       => $reading['dev_pompa'],
                    'deh_pompa'       => $reading['deh_pompa'],
                    'dea_pompa'       => $reading['dea_pompa'],
                    'kondisi'         => $kondisi,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        // Insert dalam batch kecil supaya tidak overload memory
        foreach (array_chunk($inserts, 200) as $chunk) {
            CmReading::insert($chunk);
        }
    }

    /**
     * Generate nilai vibrasi berdasarkan kondisi.
     *
     * @return array<string, float|null>
     */
    private function generateReadingValues(string $kondisi): array
    {
        if ($kondisi === 'good') {
            $ndevM = round(0.5 + mt_rand(0, 350) / 100, 2);  // 0.5 - 4.0
            $tempM = round(30 + mt_rand(0, 250) / 10, 1);    // 30 - 55
        } elseif ($kondisi === 'alarm') {
            $ndevM = round(4.5 + mt_rand(0, 250) / 100, 2);  // 4.5 - 7.0
            $tempM = round(50 + mt_rand(0, 150) / 10, 1);    // 50 - 65
        } elseif ($kondisi === 'danger') {
            $ndevM = round(7.1 + mt_rand(0, 790) / 100, 2);  // 7.1 - 15.0
            $tempM = round(65 + mt_rand(0, 300) / 10, 1);    // 65 - 95
        } else {
            // visual_bad
            $ndevM = round(2.0 + mt_rand(0, 300) / 100, 2);  // 2.0 - 5.0
            $tempM = round(55 + mt_rand(0, 250) / 10, 1);    // 55 - 80
        }

        $ndevP = round($ndevM * (0.8 + mt_rand(0, 40) / 100), 2);
        $tempP = round($tempM * (0.9 + mt_rand(0, 20) / 100), 1);

        return [
            'ndev_motor'    => $ndevM,
            'ndeh_motor'    => round($ndevM * (0.9 + mt_rand(0, 20) / 100), 2),
            'ndea_motor'    => round($ndevM * (0.85 + mt_rand(0, 30) / 100), 2),
            'temp_de_motor' => $tempM,
            'dev_motor'     => round($ndevM * (0.7 + mt_rand(0, 40) / 100), 2),
            'deh_motor'     => round($ndevM * (0.65 + mt_rand(0, 40) / 100), 2),
            'dea_motor'     => round($ndevM * (0.6 + mt_rand(0, 50) / 100), 2),
            'ndev_pompa'    => $ndevP,
            'ndeh_pompa'    => round($ndevP * (0.9 + mt_rand(0, 20) / 100), 2),
            'ndea_pompa'    => round($ndevP * (0.85 + mt_rand(0, 30) / 100), 2),
            'temp_de_pompa' => $tempP,
            'dev_pompa'     => round($ndevP * (0.7 + mt_rand(0, 40) / 100), 2),
            'deh_pompa'     => round($ndevP * (0.65 + mt_rand(0, 40) / 100), 2),
            'dea_pompa'     => round($ndevP * (0.6 + mt_rand(0, 50) / 100), 2),
        ];
    }

    /**
     * Seed tabel cm_findings — beberapa equipment punya finding, beberapa tidak.
     *
     * @param  \Illuminate\Support\Collection  $equipments
     */
    private function seedFindings($equipments): void
    {
        $now = now();
        $inserts = [];

        foreach ($equipments as $tag => $eq) {
            // 60% equipment punya minimal 1 finding
            if (mt_rand(1, 100) > 60) {
                continue;
            }

            $numFindings = rand(1, 3);

            for ($i = 0; $i < $numFindings; $i++) {
                $status = mt_rand(1, 100) <= 70 ? 'open' : 'closed';  // 70% open
                $tanggalTemuan = $now->copy()->subDays(rand(1, 120));
                $hariOpen = (int) $now->diffInDays($tanggalTemuan);

                $inserts[] = [
                    'cm_equipment_id' => $eq->id,
                    'severity'        => ['low', 'medium', 'high'][rand(0, 2)],
                    'kategori'        => $this->kategoriFinding[array_rand($this->kategoriFinding)],
                    'deskripsi'       => $this->deskripsiFinding[array_rand($this->deskripsiFinding)],
                    'status'          => $status,
                    'pic'             => $this->namaPIC[array_rand($this->namaPIC)],
                    'tanggal_temuan'  => $tanggalTemuan->format('Y-m-d'),
                    'foto_url'        => mt_rand(1, 100) <= 30 ? 'https://via.placeholder.com/640x480?text=Finding+Photo' : null,
                    'hari_open'       => $status === 'closed' ? rand(1, 30) : $hariOpen,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        if (!empty($inserts)) {
            foreach (array_chunk($inserts, 100) as $chunk) {
                CmFinding::insert($chunk);
            }
        }
    }

    /**
     * Seed tabel cm_monthly_tracking — per equipment, 12 bulan terakhir.
     *
     * @param  \Illuminate\Support\Collection  $equipments
     */
    private function seedMonthlyTracking($equipments): void
    {
        $now = now();
        $tahunIni = (int) $now->format('Y');
        $bulanIni = (int) $now->format('n');
        $inserts = [];

        foreach ($equipments as $tag => $eq) {
            // Tentukan progress acak per equipment
            $progressLevel = mt_rand(1, 100);

            for ($bulan = 1; $bulan <= 12; $bulan++) {
                $trackTahun = $tahunIni;
                $trackBulan = $bulan;

                // Jangan buat tracking untuk bulan yang belum lewat (tahun berjalan)
                if ($trackTahun === $tahunIni && $trackBulan > $bulanIni) {
                    continue;
                }

                // Tentukan status berdasarkan progress level equipment
                $status = 'belum';
                if ($progressLevel <= 25) {
                    // Low progress: 17-25% selesai
                    $status = mt_rand(1, 100) <= 20 ? 'sudah' : 'belum';
                } elseif ($progressLevel <= 50) {
                    // Medium progress: 40-60% selesai
                    $status = mt_rand(1, 100) <= 50 ? 'sudah' : 'belum';
                } else {
                    // High progress: 80-100% selesai
                    $status = mt_rand(1, 100) <= 90 ? 'sudah' : 'belum';
                }

                $inserts[] = [
                    'cm_equipment_id' => $eq->id,
                    'tahun'           => $trackTahun,
                    'bulan'           => $trackBulan,
                    'status'          => $status,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            CmMonthlyTracking::insert($chunk);
        }
    }
}
