<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Report;
use App\Models\ScanCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanCode>
 */
class ScanCodeFactory extends Factory
{
    protected $model = ScanCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => ScanCode::hashCode(sprintf('%06d', fake()->unique()->numberBetween(0, 999999))),
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function withCode(string $code): static
    {
        return $this->state(fn (): array => ['code_hash' => ScanCode::hashCode($code)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function used(?Report $report = null): static
    {
        return $this->state(fn (): array => ['used_at' => now(), 'report_id' => $report?->id]);
    }
}
