<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Machine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Machine>
 */
class MachineFactory extends Factory
{
    protected $model = Machine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $serial = strtoupper(fake()->unique()->bothify('C02??########'));

        return [
            'user_id' => User::factory(),
            'identity_key' => 'serial:'.$serial,
            'serial' => $serial,
            'hardware_uuid' => fake()->uuid(),
            'name' => fake()->firstName().'-MacBook',
            'os' => 'macOS',
            'device_type' => 'laptop',
            'model' => 'MacBook Pro (MacBookPro18,3)',
        ];
    }
}
