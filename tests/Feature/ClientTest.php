<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

describe('authentication', function () {
    it('requires a login for every client route', function (string $method, string $uri) {
        $client = Client::factory()->create();
        $machine = Machine::factory()->create();

        $uri = str_replace(['{client}', '{machine}'], [(string) $client->id, (string) $machine->id], $uri);

        $this->{$method}($uri)->assertRedirect('/login');
    })->with([
        ['get', '/clients'],
        ['get', '/clients/create'],
        ['post', '/clients'],
        ['get', '/clients/{client}'],
        ['put', '/clients/{client}'],
        ['delete', '/clients/{client}'],
        ['post', '/clients/{client}/share-link'],
        ['delete', '/clients/{client}/share-link'],
        ['put', '/machines/{machine}/client'],
    ]);
});

describe('list', function () {
    it('lists only my clients with their machine count and share link state', function () {
        $user = User::factory()->create();
        $mine = Client::factory()->for($user)->create(['name' => 'Mine']);
        Machine::factory()->count(2)->for($user)->create(['client_id' => $mine->id]);
        Client::factory()->for($user)->withShareLink()->create(['name' => 'Linked']);
        Client::factory()->create(['name' => 'Theirs']);

        actingAs($user)->get('/clients')
            ->assertInertia(fn ($page) => $page
                ->component('clients/Index')
                ->has('clients.data', 2)
                ->where('clients.data.0.name', 'Linked')
                ->where('clients.data.0.has_share_link', true)
                ->where('clients.data.1.name', 'Mine')
                ->where('clients.data.1.machines_count', 2)
                ->where('clients.data.1.has_share_link', false)
                ->missing('clients.data.0.share_token'));
    });

    it('leaves out deleted clients', function () {
        $user = User::factory()->create();
        Client::factory()->for($user)->create()->delete();

        actingAs($user)->get('/clients')->assertInertia(fn ($page) => $page->has('clients.data', 0));
    });

    it('renders the create page', function () {
        actingAs(User::factory()->create())->get('/clients/create')
            ->assertInertia(fn ($page) => $page->component('clients/Create'));
    });
});

describe('POST /clients', function () {
    it('creates a client for me with no share link', function () {
        $user = User::factory()->create();

        $response = actingAs($user)->post('/clients', ['name' => 'Acme']);

        $client = Client::query()->sole();
        $response->assertRedirect("/clients/{$client->id}");
        expect($client->name)->toBe('Acme')
            ->and($client->user_id)->toBe($user->id)
            ->and($client->share_token)->toBeNull()
            ->and($client->last_viewed_at)->toBeNull();
    });

    it('ignores a user_id or share_token in the request', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        actingAs($user)->post('/clients', ['name' => 'Acme', 'user_id' => $other->id, 'share_token' => 'x']);

        $client = Client::query()->sole();
        expect($client->user_id)->toBe($user->id)->and($client->share_token)->toBeNull();
    });

    it('trims the name', function () {
        actingAs(User::factory()->create())->post('/clients', ['name' => "  Acme Ltd \n"]);

        expect(Client::query()->sole()->name)->toBe('Acme Ltd');
    });

    it('accepts odd but valid names', function (string $name) {
        actingAs(User::factory()->create())->post('/clients', ['name' => $name])->assertSessionHasNoErrors();

        expect(Client::query()->sole()->name)->toBe($name);
    })->with([
        'punctuation only' => '!!!',
        'unicode' => 'Zoë Müller 田中 🚀',
        'markup' => '<script>alert(1)</script>',
        'numeric string' => '12345',
        'exactly 255 characters' => str_repeat('a', 255),
        'exactly 255 multibyte characters' => str_repeat('é', 255),
    ]);

    it('allows two clients with the same name', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/clients', ['name' => 'Acme'])->assertSessionHasNoErrors();
        actingAs($user)->post('/clients', ['name' => 'Acme'])->assertSessionHasNoErrors();

        expect(Client::query()->count())->toBe(2);
    });

    it('rejects an invalid name', function (mixed $name) {
        actingAs(User::factory()->create())->post('/clients', ['name' => $name])->assertSessionHasErrors('name');

        expect(Client::query()->count())->toBe(0);
    })->with([
        'null' => null,
        'empty' => '',
        'spaces only' => '   ',
        'tabs and newlines only' => "\t\n ",
        'over 255 characters' => str_repeat('a', 256),
        'over 255 multibyte characters' => str_repeat('é', 256),
        'array' => [['Acme']],
        'integer' => 5,
        'boolean' => true,
    ]);

    it('rejects a missing name', function () {
        actingAs(User::factory()->create())->post('/clients', [])->assertSessionHasErrors('name');
    });
});

describe('GET /clients/{client}', function () {
    it('shows the client, its machines and the machines I could assign', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['name' => 'Acme']);
        $assigned = Machine::factory()->for($user)->create(['client_id' => $client->id]);
        $free = Machine::factory()->for($user)->create();
        $elsewhere = Machine::factory()->for($user)->create(['client_id' => Client::factory()->for($user)]);
        Machine::factory()->create();

        actingAs($user)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page
                ->component('clients/Show')
                ->where('client.id', $client->id)
                ->where('client.name', 'Acme')
                ->where('client.share_url', null)
                ->where('client.last_viewed_at', null)
                ->has('machines', 1)
                ->where('machines.0.id', $assigned->id)
                ->has('available_machines', 2)
                ->where('available_machines.0.id', $free->id)
                ->where('available_machines.1.id', $elsewhere->id));
    });

    it('does not list a deleted machine', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        Machine::factory()->for($user)->create(['client_id' => $client->id])->delete();

        actingAs($user)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page->has('machines', 0)->has('available_machines', 0));
    });

    it('shows the share link as an absolute /c/{token} url', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create();

        actingAs($user)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page->where('client.share_url', url("/c/{$client->share_token}")));
    });

    it('forbids another user’s client', function () {
        $client = Client::factory()->create();

        actingAs(User::factory()->create())->get("/clients/{$client->id}")->assertForbidden();
    });

    it('does not find a deleted client or a missing one', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $client->delete();

        actingAs($user)->get("/clients/{$client->id}")->assertNotFound();
        actingAs($user)->get('/clients/999999')->assertNotFound();
        actingAs($user)->get('/clients/abc')->assertNotFound();
    });
});

describe('PUT /clients/{client}', function () {
    it('renames the client', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create(['name' => 'Old']);
        $token = $client->share_token;

        actingAs($user)->put("/clients/{$client->id}", ['name' => ' New '])->assertRedirect("/clients/{$client->id}");

        $client->refresh();
        expect($client->name)->toBe('New')->and($client->share_token)->toBe($token);
    });

    it('rejects an invalid name', function (mixed $name) {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['name' => 'Old']);

        actingAs($user)->put("/clients/{$client->id}", ['name' => $name])->assertSessionHasErrors('name');

        expect($client->fresh()?->name)->toBe('Old');
    })->with([null, '', '   ', [['x']], 12.5]);

    it('forbids another user’s client', function () {
        $client = Client::factory()->create(['name' => 'Old']);

        actingAs(User::factory()->create())->put("/clients/{$client->id}", ['name' => 'Hacked'])->assertForbidden();

        expect($client->fresh()?->name)->toBe('Old');
    });
});

describe('DELETE /clients/{client}', function () {
    it('soft deletes the client, revokes its link and unassigns its machines', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $client->id]);
        $trashed = Machine::factory()->for($user)->create(['client_id' => $client->id]);
        $trashed->delete();
        $bystander = Machine::factory()->for($user)->create();

        actingAs($user)->delete("/clients/{$client->id}")->assertRedirect('/clients');

        assertSoftDeleted($client);
        expect(Client::withTrashed()->findOrFail($client->id)->share_token)->toBeNull()
            ->and($machine->fresh()?->client_id)->toBeNull()
            ->and(Machine::withTrashed()->findOrFail($trashed->id)->client_id)->toBeNull()
            ->and(Machine::query()->find($machine->id))->not->toBeNull()
            ->and($bystander->fresh()?->client_id)->toBeNull();
    });

    it('does not touch another client’s machines', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $keep = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $keep->id]);

        actingAs($user)->delete("/clients/{$client->id}");

        expect($machine->fresh()?->client_id)->toBe($keep->id);
    });

    it('forbids another user’s client', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);

        actingAs(User::factory()->create())->delete("/clients/{$client->id}")->assertForbidden();

        expect($client->fresh())->not->toBeNull()
            ->and($client->fresh()?->share_token)->not->toBeNull()
            ->and($machine->fresh()?->client_id)->toBe($client->id);
    });

    it('answers 404 for a client that is already deleted', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $client->delete();

        actingAs($user)->delete("/clients/{$client->id}")->assertNotFound();
    });
});

describe('share link', function () {
    it('generates a long random token', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        actingAs($user)->post("/clients/{$client->id}/share-link")->assertRedirect("/clients/{$client->id}");

        $token = $client->fresh()?->share_token;
        expect($token)->toBeString()->toMatch('/^[A-Za-z0-9]{40,}$/');
    });

    it('replaces the old token when regenerated', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create();
        $old = $client->share_token;

        actingAs($user)->post("/clients/{$client->id}/share-link");

        $new = $client->fresh()?->share_token;
        expect($new)->not->toBe($old)->and($new)->toBeString();
        assertDatabaseHas('clients', ['id' => $client->id, 'share_token' => $new]);
        expect(Client::query()->where('share_token', $old)->exists())->toBeFalse();
    });

    it('issues a different token for every client', function () {
        $user = User::factory()->create();
        $first = Client::factory()->for($user)->create();
        $second = Client::factory()->for($user)->create();

        actingAs($user)->post("/clients/{$first->id}/share-link");
        actingAs($user)->post("/clients/{$second->id}/share-link");

        expect($first->fresh()?->share_token)->not->toBe($second->fresh()?->share_token);
    });

    it('revokes the link by clearing the token', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create();

        actingAs($user)->delete("/clients/{$client->id}/share-link")->assertRedirect("/clients/{$client->id}");

        expect($client->fresh()?->share_token)->toBeNull();
        actingAs($user)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page->where('client.share_url', null));
    });

    it('can be revoked when there is no link', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        actingAs($user)->delete("/clients/{$client->id}/share-link")->assertRedirect("/clients/{$client->id}");

        expect($client->fresh()?->share_token)->toBeNull();
    });

    it('does not let another user generate, regenerate or revoke', function () {
        $client = Client::factory()->withShareLink()->create();
        $token = $client->share_token;
        $bare = Client::factory()->create();

        actingAs(User::factory()->create())->post("/clients/{$client->id}/share-link")->assertForbidden();
        actingAs(User::factory()->create())->delete("/clients/{$client->id}/share-link")->assertForbidden();
        actingAs(User::factory()->create())->post("/clients/{$bare->id}/share-link")->assertForbidden();

        expect($client->fresh()?->share_token)->toBe($token)->and($bare->fresh()?->share_token)->toBeNull();
    });

    it('does not find a deleted client', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $client->delete();

        actingAs($user)->post("/clients/{$client->id}/share-link")->assertNotFound();
        actingAs($user)->delete("/clients/{$client->id}/share-link")->assertNotFound();
    });

    it('never puts the token in the client list', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create();

        $body = actingAs($user)->get('/clients')->getContent();

        expect($body)->not->toContain((string) $client->share_token);
    });
});

describe('PUT /machines/{machine}/client', function () {
    it('assigns my machine to my client', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->from("/machines/{$machine->id}")
            ->put("/machines/{$machine->id}/client", ['client_id' => $client->id])
            ->assertRedirect("/machines/{$machine->id}");

        expect($machine->fresh()?->client_id)->toBe($client->id);
    });

    it('moves a machine from one client to another', function () {
        $user = User::factory()->create();
        $from = Client::factory()->for($user)->create();
        $to = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $from->id]);

        actingAs($user)->put("/machines/{$machine->id}/client", ['client_id' => $to->id]);

        expect($machine->fresh()?->client_id)->toBe($to->id);
    });

    it('unassigns with a null client', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $client->id]);

        actingAs($user)->put("/machines/{$machine->id}/client", ['client_id' => null])->assertSessionHasNoErrors();

        expect($machine->fresh()?->client_id)->toBeNull();
    });

    it('unassigns when the client is missing from the request', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $client->id]);

        actingAs($user)->put("/machines/{$machine->id}/client", [])->assertSessionHasNoErrors();

        expect($machine->fresh()?->client_id)->toBeNull();
    });

    it('rejects another user’s client and leaves the machine alone', function () {
        $user = User::factory()->create();
        $mine = Client::factory()->for($user)->create();
        $theirs = Client::factory()->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $mine->id]);

        actingAs($user)->put("/machines/{$machine->id}/client", ['client_id' => $theirs->id])
            ->assertSessionHasErrors('client_id');

        expect($machine->fresh()?->client_id)->toBe($mine->id);
    });

    it('rejects a missing or malformed client id', function (mixed $value) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->put("/machines/{$machine->id}/client", ['client_id' => $value])
            ->assertSessionHasErrors('client_id');

        expect($machine->fresh()?->client_id)->toBeNull();
    })->with([999999, 'abc', [[1]], 1.5, -1, 0]);

    it('rejects a deleted client', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $client->delete();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->put("/machines/{$machine->id}/client", ['client_id' => $client->id])
            ->assertSessionHasErrors('client_id');

        expect($machine->fresh()?->client_id)->toBeNull();
    });

    it('forbids another user’s machine, even with my own client', function () {
        $user = User::factory()->create();
        $mine = Client::factory()->for($user)->create();
        $theirs = Machine::factory()->create();

        actingAs($user)->put("/machines/{$theirs->id}/client", ['client_id' => $mine->id])->assertForbidden();

        expect($theirs->fresh()?->client_id)->toBeNull();
    });

    it('forbids unassigning another user’s machine', function () {
        $client = Client::factory()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);

        actingAs(User::factory()->create())->put("/machines/{$machine->id}/client", ['client_id' => null])
            ->assertForbidden();

        expect($machine->fresh()?->client_id)->toBe($client->id);
    });

    it('does not find a missing, malformed or deleted machine', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create();
        $machine->delete();

        actingAs($user)->put('/machines/999999/client', ['client_id' => $client->id])->assertNotFound();
        actingAs($user)->put('/machines/abc/client', ['client_id' => $client->id])->assertNotFound();
        actingAs($user)->put("/machines/{$machine->id}/client", ['client_id' => $client->id])->assertNotFound();
    });
});

describe('machine page', function () {
    it('tells the page which client the machine is with and which clients I could pick', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['name' => 'Acme']);
        $other = Client::factory()->for($user)->create(['name' => 'Beta']);
        Client::factory()->create(['name' => 'Theirs']);
        $machine = Machine::factory()->for($user)->create(['client_id' => $client->id]);

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page
                ->where('client', ['id' => $client->id, 'name' => 'Acme'])
                ->has('clients', 2)
                ->where('clients.0.name', 'Acme')
                ->where('clients.1.id', $other->id));
    });

    it('has no client for an unassigned machine', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('client', null)->has('clients', 0));
    });

    it('does not show a deleted client', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $client->id]);
        $client->delete();

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('client', null));
    });
});
