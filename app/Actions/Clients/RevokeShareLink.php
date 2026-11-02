<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;

final class RevokeShareLink
{
    public function handle(Client $client): void
    {
        $client->forceFill(['share_token' => null])->save();
    }
}
