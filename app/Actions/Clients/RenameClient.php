<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;

final class RenameClient
{
    public function handle(Client $client, string $name): void
    {
        $client->update(['name' => $name]);
    }
}
