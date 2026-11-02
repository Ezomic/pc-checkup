<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Clients\GenerateShareLink;
use App\Actions\Clients\RevokeShareLink;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class ClientShareLinkController extends Controller
{
    public function store(Client $client, GenerateShareLink $action): RedirectResponse
    {
        $this->authorize('update', $client);

        $action->handle($client);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Share link ready. Any earlier link no longer works.']);

        return to_route('clients.show', $client);
    }

    public function destroy(Client $client, RevokeShareLink $action): RedirectResponse
    {
        $this->authorize('update', $client);

        $action->handle($client);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Share link revoked.']);

        return to_route('clients.show', $client);
    }
}
