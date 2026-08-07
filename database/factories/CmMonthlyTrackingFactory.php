<?php

namespace Database\Factories;

use App\Models\CmEquipment;
use App\Models\CmMonthlyTracking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CmMonthlyTracking>
 */
class CmMonthlyTrackingFactory extends Factory
{
    protected $model = CmMonthlyTracking::class;

    public function definition(): array
    {
        return [
            'cm_equipment_id' => CmEquipment::factory(),
            'tahun'           => $this->faker->randomElement([now()->year, now()->subYear()->year]),
            'bulan'           => $this->faker->numberBetween(1, 12),
            'status'          => $this->faker->randomElement(['sudah', 'belum', 'sudah', 'sudah']),
        ];
    }
}
