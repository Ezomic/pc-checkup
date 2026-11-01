<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChecklistOutcome;
use App\Models\ChecklistEntry;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistEntry>
 */
class ChecklistEntryFactory extends Factory
{
    protected $model = ChecklistEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'machine_id' => Machine::factory(),
            'item_key' => fake()->unique()->lexify('item_????'),
            'outcome' => ChecklistOutcome::Pass,
            'note' => null,
        ];
    }
}
