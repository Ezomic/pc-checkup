<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Enums\ShareLocale;
use App\Models\Client;

final class SetClientLocale
{
    public function handle(Client $client, ShareLocale $locale): void
    {
        $client->update(['locale' => $locale]);
    }
}
