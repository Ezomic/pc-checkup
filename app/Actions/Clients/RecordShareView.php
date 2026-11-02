<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;
use Illuminate\Contracts\Database\Eloquent\Builder;

final class RecordShareView
{
    private const int WRITE_EVERY_SECONDS = 60;

    public function handle(Client $client): void
    {
        Client::query()
            ->whereKey($client->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('last_viewed_at')
                ->orWhere('last_viewed_at', '<=', now()->subSeconds(self::WRITE_EVERY_SECONDS)))
            ->update(['last_viewed_at' => now()]);
    }
}
