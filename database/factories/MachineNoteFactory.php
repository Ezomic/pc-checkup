<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Machine;
use App\Models\MachineNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineNote>
 */
class MachineNoteFactory extends Factory
{
    protected $model = MachineNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'machine_id' => Machine::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
