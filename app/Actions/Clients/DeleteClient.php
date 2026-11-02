<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

final class DeleteClient
{
    public function handle(Client $client): void
    {
        DB::transaction(function () use ($client): void {
            $client->machines()->withTrashed()->update(['client_id' => null]);
            $client->forceFill(['share_token' => null])->save();
            $client->delete();
        });
    }
}
