<?php

namespace Database\Factories;

use App\Models\CmEquipment;
use App\Models\CmFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CmFinding>
 */
class CmFindingFactory extends Factory
{
    protected $model = CmFinding::class;

    private static array $kategoriList = [
        'Leak', 'Corrosion', 'High Vibration', 'Safety', 'Coupling Looseness',
    ];

    private static array $deskripsiPool = [
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

    public function definition(): array
    {
        $status = $this->faker->randomElement(['open', 'open', 'open', 'closed']);
        $tanggalTemuan = $this->faker->dateTimeBetween('-120 days', 'now');
        $hariIni = now();
        $hariOpen = (int) $hariIni->diffInDays($tanggalTemuan);

        return [
            'cm_equipment_id' => CmEquipment::factory(),
            'severity'        => $this->faker->randomElement(['low', 'medium', 'high']),
            'kategori'        => $this->faker->randomElement(self::$kategoriList),
            'deskripsi'       => $this->faker->randomElement(self::$deskripsiPool),
            'status'          => $status,
            'pic'             => $this->faker->name(),
            'tanggal_temuan'  => $tanggalTemuan->format('Y-m-d'),
            'foto_url'        => $this->faker->optional(0.3)->imageUrl(640, 480, 'equipment', true),
            'hari_open'       => $status === 'closed' ? $this->faker->numberBetween(1, 30) : $hariOpen,
        ];
    }
}
