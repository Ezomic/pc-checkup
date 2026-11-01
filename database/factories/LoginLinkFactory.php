<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginLink>
 */
class LoginLinkFactory extends Factory
{
    protected $model = LoginLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => LoginLink::hashToken(Str::random(64)),
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
