<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\User;

final class CreateClient
{
    public function handle(User $user, string $name): Client
    {
        return $user->clients()->create(['name' => $name]);
    }
}
