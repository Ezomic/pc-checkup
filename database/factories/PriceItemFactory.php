<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceItem>
 */
class PriceItemFactory extends Factory
{
    protected $model = PriceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'price_cents' => fake()->numberBetween(500, 25000),
            'unit' => null,
            'sort_order' => 0,
        ];
    }
}
