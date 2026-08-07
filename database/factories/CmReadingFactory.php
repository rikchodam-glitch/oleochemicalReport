<?php

namespace Database\Factories;

use App\Models\CmEquipment;
use App\Models\CmReading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CmReading>
 */
class CmReadingFactory extends Factory
{
    protected $model = CmReading::class;

    /** Proporsi kondisi: ~75% good, ~5% alarm, ~5% danger, ~15% visual_bad */
    private static array $kondisiPool = [
        'good', 'good', 'good', 'good', 'good',
        'good', 'good', 'good', 'good', 'good',
        'good', 'good', 'good', 'good', 'good',
        'alarm', 'alarm', 'alarm',
        'danger', 'danger',
        'visual_bad', 'visual_bad', 'visual_bad', 'visual_bad', 'visual_bad',
        'visual_bad', 'visual_bad',
    ];

    public function definition(): array
    {
        $kondisi = $this->faker->randomElement(self::$kondisiPool);

        // Nilai normal untuk vibrasi (mm/s RMS)
        $normalVib = $this->faker->randomFloat(2, 0.5, 4.5);

        // Nilai berdasarkan kondisi
        $ndevM  = $normalVib;
        $ndehM  = $normalVib;
        $ndeaM  = $normalVib;
        $tempM  = $this->faker->randomFloat(1, 30, 60);
        $devM   = $normalVib;
        $dehM   = $normalVib;
        $deaM   = $normalVib;

        $ndevP  = $normalVib;
        $ndehP  = $normalVib;
        $ndeaP  = $normalVib;
        $tempP  = $this->faker->randomFloat(1, 30, 60);
        $devP   = $normalVib;
        $dehP   = $normalVib;
        $deaP   = $normalVib;

        if ($kondisi === 'alarm') {
            $ndevM = $this->faker->randomFloat(2, 4.5, 7.0);
            $ndehM = $this->faker->randomFloat(2, 4.5, 7.0);
        } elseif ($kondisi === 'danger') {
            $ndevM = $this->faker->randomFloat(2, 7.1, 15.0);
            $ndehM = $this->faker->randomFloat(2, 7.1, 15.0);
            $devM  = $this->faker->randomFloat(2, 7.1, 15.0);
            $ndevP = $this->faker->randomFloat(2, 7.1, 15.0);
            $tempM = $this->faker->randomFloat(1, 65, 95);
            $tempP = $this->faker->randomFloat(1, 65, 95);
        } elseif ($kondisi === 'visual_bad') {
            // visual_bad: nilai tidak terlalu ekstrim tapi ada temuan visual
            $ndevM = $this->faker->randomFloat(2, 2.0, 5.0);
            $tempM = $this->faker->randomFloat(1, 55, 80);
        }

        return [
            'cm_equipment_id' => CmEquipment::factory(),
            'tanggal'         => $this->faker->dateTimeBetween('-12 months', 'now')->format('Y-m-d'),
            'status'          => $this->faker->randomElement(['start', 'no_vib', 'running', 'stop']),
            'ndev_motor'      => $ndevM,
            'ndeh_motor'      => $ndehM,
            'ndea_motor'      => $ndeaM,
            'temp_de_motor'   => $tempM,
            'dev_motor'       => $devM,
            'deh_motor'       => $dehM,
            'dea_motor'       => $deaM,
            'ndev_pompa'      => $ndevP,
            'ndeh_pompa'      => $ndehP,
            'ndea_pompa'      => $ndeaP,
            'temp_de_pompa'   => $tempP,
            'dev_pompa'       => $devP,
            'deh_pompa'       => $dehP,
            'dea_pompa'       => $deaP,
            'kondisi'         => $kondisi,
        ];
    }

    /**
     * Set kondisi tertentu untuk reading.
     */
    public function withKondisi(string $kondisi): static
    {
        return $this->state(fn(array $attrs) => [
            'kondisi' => $kondisi,
        ]);
    }
}
