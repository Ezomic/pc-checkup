<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;
use Illuminate\Support\Str;

final class GenerateShareLink
{
    public const int TOKEN_LENGTH = 48;

    public function handle(Client $client): void
    {
        $client->forceFill(['share_token' => Str::random(self::TOKEN_LENGTH)])->save();
    }
}
