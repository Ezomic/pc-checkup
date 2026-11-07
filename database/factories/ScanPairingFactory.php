<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\Machine;
use App\Models\ScanPairing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScanPairing>
 */
class ScanPairingFactory extends Factory
{
    protected $model = ScanPairing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pairing_hash' => ScanPairing::hash(Str::random(ScanPairing::ID_LENGTH)),
            'secret_hash' => ScanPairing::hash(Str::random(ScanPairing::SECRET_LENGTH)),
            'check_code' => 'K7MX',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function withId(string $id, string $secret = ''): static
    {
        return $this->state(fn (): array => array_filter([
            'pairing_hash' => ScanPairing::hash($id),
            'secret_hash' => $secret === '' ? null : ScanPairing::hash($secret),
        ]));
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function approved(User $user, Client $client, ?Machine $machine = null, string $token = ''): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'machine_id' => $machine?->id,
            'approved_at' => now(),
            'token_hash' => $token === '' ? null : ScanPairing::hash($token),
            'token_expires_at' => now()->addMinutes(30),
        ]);
    }

    public function rejected(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id, 'rejected_at' => now()]);
    }

    public function used(): static
    {
        return $this->state(fn (): array => ['used_at' => now()]);
    }
}
