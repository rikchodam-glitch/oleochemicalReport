<?php

namespace Database\Factories;

use App\Models\CmEquipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CmEquipment>
 */
class CmEquipmentFactory extends Factory
{
    protected $model = CmEquipment::class;

    /** Daftar PT dan plant yang tersedia */
    private static array $ptPlants = [
        'PT. EPE' => ['BD 1', 'MD', 'OSBL 1'],
        'PT. CES' => ['BD 1', 'RG 2', 'OSBL 2'],
        'PT. NPA' => ['MD', 'OSBL 1', 'RG 2'],
    ];

    /** Daftar prefix tag untuk variasi equipment */
    private static array $tagPrefixes = [
        'PM', 'PU', 'FN', 'BL', 'CV', 'AG', 'DR', 'MX', 'CP', 'CM',
    ];

    /** Counter untuk generate tag unik */
    private static int $counter = 1;

    public function definition(): array
    {
        $pt   = $this->faker->randomElement(array_keys(self::$ptPlants));
        $plant = $this->faker->randomElement(self::$ptPlants[$pt]);
        $prefix = $this->faker->randomElement(self::$tagPrefixes);
        $tag = sprintf('%s-%04d', $prefix, self::$counter++);

        return [
            'equipment_tag'  => $tag,
            'pt_location'    => $pt,
            'plant'          => $plant,
            'tipe_lubrikasi' => $this->faker->randomElement(['Grease', 'Oil Bath', 'Oil Mist', null]),
        ];
    }
}
