<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Clients\CreateClient;
use App\Actions\Clients\DeleteClient;
use App\Actions\Clients\RenameClient;
use App\Actions\Clients\SetClientLocale;
use App\Http\Requests\Clients\SaveClientRequest;
use App\Models\Client;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('clients/Index', [
            'clients' => $user->clients()
                ->withCount('machines')
                ->latest()
                ->latest('id')
                ->paginate(Config::integer('pagination.per_page'))
                ->through(fn (Client $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'machines_count' => $client->machines_count ?? 0,
                    'has_share_link' => $client->share_token !== null,
                    'last_viewed_at' => $client->last_viewed_at,
                ])
                ->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('clients/Create');
    }

    public function store(SaveClientRequest $request, CreateClient $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $client = $action->handle($user, $request->name());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Client added.']);

        return to_route('clients.show', $client);
    }

    public function show(Client $client): Response
    {
        $this->authorize('view', $client);

        return Inertia::render('clients/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'locale' => $client->locale->value,
                'share_url' => $client->share_token === null ? null : route('share.show', $client->share_token),
                'last_viewed_at' => $client->last_viewed_at,
                'created_at' => $client->created_at,
            ],
            'machines' => $client->machines()->orderBy('id')->get()
                ->map(fn (Machine $machine): array => $machine->only(['id', 'name', 'model', 'os', 'device_type', 'status']))
                ->all(),
            'available_machines' => Machine::query()
                ->where('user_id', $client->user_id)
                ->where(fn (Builder $query) => $query->whereNull('client_id')->orWhere('client_id', '!=', $client->id))
                ->with('client:id,name')
                ->orderBy('id')
                ->get()
                ->map(fn (Machine $machine): array => [
                    ...$machine->only(['id', 'name', 'model']),
                    'client_name' => $machine->client->name ?? null,
                ])
                ->all(),
        ]);
    }

    public function update(SaveClientRequest $request, Client $client, RenameClient $action, SetClientLocale $setLocale): RedirectResponse
    {
        $this->authorize('update', $client);

        $action->handle($client, $request->name());

        if ($request->locale() !== null) {
            $setLocale->handle($client, $request->locale());
        }

        return to_route('clients.show', $client);
    }

    public function destroy(Client $client, DeleteClient $action): RedirectResponse
    {
        $this->authorize('delete', $client);

        $action->handle($client);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Client deleted and its machines unassigned.']);

        return to_route('clients.index');
    }
}
