<?php

declare(strict_types=1);

use App\Enums\ChecklistOutcome;
use App\Enums\MachineStatus;
use App\Models\ChecklistEntry;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MachineNote;
use App\Models\Photo;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

function sharedClient(string $name = 'Acme'): Client
{
    return Client::factory()->withShareLink()->create(['name' => $name]);
}

function assignedMachine(Client $client, array $attributes = []): Machine
{
    return Machine::factory()->for($client->user)->create(['client_id' => $client->id, ...$attributes]);
}

function sharePath(Client $client, ?Machine $machine = null): string
{
    return "/c/{$client->share_token}".($machine === null ? '' : "/machines/{$machine->id}");
}

describe('the client page', function () {
    it('needs no login and shows the client name with only its own machines', function () {
        $client = sharedClient('Acme Dental');
        $first = assignedMachine($client, ['name' => 'Front-desk', 'os' => 'Windows', 'device_type' => 'desktop', 'model' => 'OptiPlex', 'status' => MachineStatus::Ok]);
        $second = assignedMachine($client, ['name' => 'Back-office', 'status' => MachineStatus::NeedsWork]);
        Report::factory()->for($first)->create(['flags' => ['FileVault is OFF.', 'A restart is pending.'], 'created_at' => '2026-10-05 10:00:00']);

        $this->get(sharePath($client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('share/Show')
                ->where('token', $client->share_token)
                ->where('client', ['name' => 'Acme Dental'])
                ->has('machines', 2)
                ->where('machines.0', [
                    'id' => $first->id,
                    'name' => 'Front-desk',
                    'os' => 'Windows',
                    'device_type' => 'desktop',
                    'model' => 'OptiPlex',
                    'status' => 'ok',
                    'top_issue' => ['severity' => 'medium', 'headline' => 'Disk encryption is off', 'advice' => 'If the machine is lost or stolen, anyone can read its files. Turn disk encryption on.'],
                    'issue_count' => 2,
                    'scanned_at' => $first->newestReport?->created_at?->toJSON(),
                ])
                ->where('machines.1.id', $second->id)
                ->where('machines.1.top_issue', null)
                ->where('machines.1.issue_count', 0)
                ->where('machines.1.scanned_at', null));
    });

    it('shows an empty list for a client without machines', function () {
        $client = sharedClient();

        $this->get(sharePath($client))->assertInertia(fn ($page) => $page->has('machines', 0));
    });

    it('leaves out unassigned, other clients and deleted machines', function () {
        $client = sharedClient();
        $mine = assignedMachine($client);
        assignedMachine($client)->delete();
        Machine::factory()->for($client->user)->create();
        assignedMachine(sharedClient('Other'));

        $this->get(sharePath($client))
            ->assertInertia(fn ($page) => $page->has('machines', 1)->where('machines.0.id', $mine->id));
    });

    it('never shows a rejected status to the client', function () {
        $client = sharedClient();
        assignedMachine($client, ['status' => MachineStatus::Reject]);

        $this->get(sharePath($client))
            ->assertInertia(fn ($page) => $page->where('machines.0.status', null));
    });

    it('works the same for a signed-in owner', function () {
        $client = sharedClient();

        actingAs($client->user)->get(sharePath($client))->assertOk();
    });
});

describe('a machine of the client', function () {
    it('shows the verdict, key numbers and what to do first', function () {
        $client = sharedClient();
        $machine = assignedMachine($client, ['name' => 'Front-desk', 'status' => MachineStatus::NeedsWork]);
        Report::factory()->for($machine)->create(['flags' => ['old'], 'created_at' => '2026-10-01 10:00:00']);
        $latest = Report::factory()->for($machine)->create([
            'markdown' => "# Windows checkup: PC\n\n- Chip/CPU: Ryzen 5\n- Memory: 32 GB\n- Free disk: 5%\n\n## Things to look at\n",
            'flags' => ['A restart is pending.', 'Defender real-time protection is OFF.'],
            'created_at' => '2026-10-05 10:00:00',
        ]);

        $this->get(sharePath($client, $machine))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('share/Machine')
                ->where('token', $client->share_token)
                ->where('client', ['name' => $client->name])
                ->where('machine', [
                    'id' => $machine->id,
                    'name' => 'Front-desk',
                    'os' => 'macOS',
                    'device_type' => 'laptop',
                    'model' => 'MacBook Pro (MacBookPro18,3)',
                    'status' => 'needs_work',
                ])
                ->where('report', [
                    'scanned_at' => $latest->created_at?->toJSON(),
                    'facts' => ['free_disk' => '5%', 'memory' => '32 GB', 'cpu' => 'Ryzen 5'],
                    'advice' => [
                        ['severity' => 'high', 'headline' => 'No antivirus is protecting this PC', 'advice' => 'Turn on real-time protection in Windows Security, or install an antivirus.'],
                        ['severity' => 'low', 'headline' => 'A restart is pending', 'advice' => 'Restart to finish installing updates.'],
                    ],
                ]));
    });

    it('has no report for a machine that was never scanned', function () {
        $client = sharedClient();
        $machine = assignedMachine($client);

        $this->get(sharePath($client, $machine))->assertInertia(fn ($page) => $page->where('report', null));
    });

    it('never shows a rejected status to the client', function () {
        $client = sharedClient();
        $machine = assignedMachine($client, ['status' => MachineStatus::Reject]);

        $this->get(sharePath($client, $machine))->assertInertia(fn ($page) => $page->where('machine.status', null));
    });

    it('hides the text of a flag no rule recognises', function () {
        $client = sharedClient();
        $machine = assignedMachine($client);
        Report::factory()->for($machine)->create(['flags' => ['Odd thing on volume SECRET-VOLUME-LABEL.']]);

        $response = $this->get(sharePath($client, $machine))
            ->assertInertia(fn ($page) => $page
                ->where('report.advice', [['severity' => 'low', 'headline' => 'Something to look at', 'advice' => null]]));

        expect((string) $response->getContent())->not->toContain('SECRET-VOLUME-LABEL');
        $this->get(sharePath($client))->assertDontSee('SECRET-VOLUME-LABEL')
            ->assertInertia(fn ($page) => $page->where('machines.0.top_issue.headline', 'Something to look at'));
    });

    it('shows an ok status', function () {
        $client = sharedClient();
        $machine = assignedMachine($client, ['status' => MachineStatus::Ok]);

        $this->get(sharePath($client, $machine))->assertInertia(fn ($page) => $page->where('machine.status', 'ok'));
    });
});

describe('what stays private', function () {
    it('puts none of the owner-only data in the props or the page', function (bool $machinePage) {
        $client = sharedClient();
        $machine = assignedMachine($client, ['serial' => 'SERIAL-HIDDEN-123', 'hardware_uuid' => 'uuid-hidden-4567', 'status' => MachineStatus::Reject]);
        Report::factory()->for($machine)->create([
            'markdown' => "# Mac checkup\n\n- Memory: 16 GB\n\nSECRET-MARKDOWN-BODY\n",
            'flags' => ['FileVault is OFF.'],
        ]);
        MachineNote::factory()->for($machine)->create(['body' => 'PRIVATE-NOTE-TEXT']);
        Photo::factory()->for($machine)->create(['caption' => 'PRIVATE-PHOTO-CAPTION']);
        ChecklistEntry::factory()->for($machine)->create(['outcome' => ChecklistOutcome::Fail, 'note' => 'PRIVATE-CHECKLIST-NOTE']);

        $response = $this->get(sharePath($client, $machinePage ? $machine : null))->assertOk();
        $page = '';
        $response->assertInertia(function ($assertable) use (&$page) {
            $page = json_encode($assertable->toArray(), JSON_THROW_ON_ERROR);

            return $assertable;
        });

        foreach ([$page, (string) $response->getContent()] as $body) {
            foreach (['SERIAL-HIDDEN-123', 'uuid-hidden-4567', 'SECRET-MARKDOWN-BODY', 'PRIVATE-NOTE-TEXT', 'PRIVATE-PHOTO-CAPTION', 'PRIVATE-CHECKLIST-NOTE', 'FileVault is OFF', 'photos', 'checklist', 'serial', 'hardware_uuid', 'markdown', 'reject', '"raw"', 'user_id', 'share_token'] as $hidden) {
                expect($body)->not->toContain($hidden);
            }
        }
    })->with([[false], [true]]);
});

describe('404', function () {
    it('answers 404 for a token that does not exist', function () {
        $this->get('/c/'.Str::random(48))->assertNotFound();
    });

    it('answers 404 after the link is revoked', function () {
        $client = sharedClient();
        $machine = assignedMachine($client);
        $path = sharePath($client);
        $machinePath = sharePath($client, $machine);
        $client->forceFill(['share_token' => null])->save();

        $this->get($path)->assertNotFound();
        $this->get($machinePath)->assertNotFound();
    });

    it('answers 404 for the old token after the link is regenerated', function () {
        $client = sharedClient();
        $machine = assignedMachine($client);
        $old = sharePath($client, $machine);
        $client->forceFill(['share_token' => Str::random(48)])->save();

        $this->get($old)->assertNotFound();
        $this->get(sharePath($client, $machine))->assertOk();
    });

    it('answers 404 for a deleted client', function () {
        $client = sharedClient();
        $machine = assignedMachine($client);
        $client->delete();

        $this->get(sharePath($client))->assertNotFound();
        $this->get(sharePath($client, $machine))->assertNotFound();
    });

    it('answers 404 for a machine that is not assigned, deleted or missing', function (string $which) {
        $client = sharedClient();
        $machine = match ($which) {
            'unassigned' => Machine::factory()->for($client->user)->create(),
            'deleted' => tap(assignedMachine($client))->delete(),
            'assigned elsewhere' => assignedMachine(sharedClient('Other')),
            'missing' => assignedMachine($client),
        };
        $id = $which === 'missing' ? $machine->id + 1000 : $machine->id;

        $this->get("/c/{$client->share_token}/machines/{$id}")->assertNotFound();
    })->with(['unassigned', 'deleted', 'assigned elsewhere', 'missing']);

    it('never lets one clients token open another clients machine', function () {
        $a = sharedClient('A');
        $b = sharedClient('B');
        $machineOfB = assignedMachine($b, ['name' => 'B-private-machine']);

        $this->get(sharePath($a, $machineOfB))->assertNotFound()->assertDontSee('B-private-machine');
        $this->get(sharePath($b, $machineOfB))->assertOk();
    });

    it('answers 404 rather than 500 for odd tokens and machine ids', function (string $path) {
        $this->get($path)->assertNotFound();
    })->with([
        'too short' => '/c/abc',
        'odd characters' => '/c/'.'%27%20OR%201=1--',
        'unicode' => '/c/'.'%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9%C3%A9',
        'null byte' => '/c/%00%00%00%00%00%00%00%00%00%00%00%00%00%00%00%00',
        'one over the longest token' => '/c/'.'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0u1V2w3X4y5Z6a1B2c3D4e5F6g',
        'extra segment' => '/c/a/b',
    ]);

    it('answers 404 for a token of any size when it is not one we issued', function () {
        $this->get('/c/'.str_repeat('a', 100000))->assertNotFound();
    });

    it('answers 404 for a machine id that is not a plain number', function (string $id) {
        $client = sharedClient();
        assignedMachine($client);

        $this->get("/c/{$client->share_token}/machines/{$id}")->assertNotFound();
    })->with(['letters' => 'abc', 'digit prefix' => '1abc', 'negative' => '-1', 'decimal' => '1.5', 'huge' => '99999999999999999999999', 'zero' => '0', 'sql' => '1%20OR%201=1']);

    it('renders a plain unavailable page with no link into the app', function () {
        $this->get('/c/'.Str::random(48))
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('share/Unavailable')->where('status', 404));
    });

    it('renders the same unavailable page for a url shaped like no route', function () {
        $this->get('/c/'.Str::random(48).'/machines/1/extra')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('share/Unavailable'));
    });

    it('keeps the normal error page outside the share urls', function () {
        $this->get('/not-a-page')->assertNotFound()->assertInertia(fn ($page) => $page->component('Error'));
    });

    it('treats a revoked link and a missing one the same', function () {
        $client = sharedClient();
        $revoked = sharePath($client);
        $client->forceFill(['share_token' => null])->save();

        foreach ([$revoked, '/c/'.Str::random(48)] as $path) {
            $this->get($path)->assertNotFound()
                ->assertInertia(fn ($page) => $page->component('share/Unavailable')->where('status', 404));
        }
    });
});

describe('methods', function () {
    it('refuses anything but GET and changes nothing', function (string $method) {
        $client = sharedClient('Untouched');
        $machine = assignedMachine($client, ['status' => MachineStatus::Ok]);

        foreach ([sharePath($client), sharePath($client, $machine)] as $path) {
            $this->call($method, $path, ['name' => 'Hacked', 'status' => 'reject'])->assertStatus(405);
        }

        expect($client->fresh()?->name)->toBe('Untouched')
            ->and($client->fresh()?->last_viewed_at)->toBeNull()
            ->and($machine->fresh()?->status)->toBe(MachineStatus::Ok)
            ->and($machine->fresh()?->client_id)->toBe($client->id);
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);

    it('answers HEAD without recording a view', function () {
        $client = sharedClient();

        $this->call('HEAD', sharePath($client))->assertOk();

        expect($client->fresh()?->last_viewed_at)->toBeNull();
    });
});

describe('headers', function () {
    it('keeps the page out of search, caches and referrers', function (string $kind) {
        $client = sharedClient();
        $machine = assignedMachine($client);
        $path = match ($kind) {
            'client' => sharePath($client),
            'machine' => sharePath($client, $machine),
            'missing machine' => sharePath($client).'/machines/999999',
            'unknown token' => '/c/'.Str::random(48),
        };

        $response = $this->get($path);

        expect($response->headers->get('X-Robots-Tag'))->toContain('noindex')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    })->with(['client', 'machine', 'missing machine', 'unknown token']);

    it('tells crawlers not to index in the markup too', function () {
        $client = sharedClient();

        $this->get(sharePath($client))
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('<meta name="referrer" content="no-referrer">', false);
    });

    it('does not add the robots meta to owner pages', function () {
        $user = User::factory()->create();

        actingAs($user)->get('/machines')->assertDontSee('noindex');
    });
});

describe('throttling', function () {
    it('limits lookups by address, hits and misses alike', function () {
        RateLimiter::clear('share:127.0.0.1');
        $client = sharedClient();

        foreach (range(1, 60) as $_) {
            $this->get('/c/'.Str::random(48))->assertNotFound();
        }

        $this->get('/c/'.Str::random(48))->assertStatus(429);
        $this->get(sharePath($client))->assertStatus(429);
    });
});

describe('last viewed', function () {
    it('records the first view', function () {
        $this->freezeSecond();
        $client = sharedClient();

        $this->get(sharePath($client))->assertOk();

        expect($client->fresh()?->last_viewed_at?->toJSON())->toBe(now()->toJSON());
    });

    it('records a machine view too', function () {
        $this->freezeSecond();
        $client = sharedClient();
        $machine = assignedMachine($client);

        $this->get(sharePath($client, $machine))->assertOk();

        expect($client->fresh()?->last_viewed_at?->toJSON())->toBe(now()->toJSON());
    });

    it('writes at most once a minute', function () {
        $this->freezeSecond();
        $client = sharedClient();
        $first = now();

        $this->get(sharePath($client));
        $this->travel(59)->seconds();
        $this->get(sharePath($client));

        expect($client->fresh()?->last_viewed_at?->toJSON())->toBe($first->toJSON());

        $this->travel(2)->seconds();
        $this->get(sharePath($client));

        expect($client->fresh()?->last_viewed_at?->toJSON())->toBe(now()->toJSON());
    });

    it('does not record a view for a link that does not work', function () {
        $client = sharedClient();
        $path = sharePath($client);
        $client->forceFill(['share_token' => null])->save();

        $this->get($path)->assertNotFound();
        $this->get('/c/'.Str::random(48))->assertNotFound();

        assertDatabaseHas('clients', ['id' => $client->id, 'last_viewed_at' => null]);
    });

    it('does not touch another clients last viewed', function () {
        $client = sharedClient();
        $other = sharedClient('Other');

        $this->get(sharePath($client));

        expect($other->fresh()?->last_viewed_at)->toBeNull();
    });

    it('shows the owner when the link was last viewed', function () {
        $this->freezeSecond();
        $client = sharedClient();

        $this->get(sharePath($client));

        actingAs($client->user)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page->where('client.last_viewed_at', now()->toJSON()));
    });
});

describe('the owner share link', function () {
    it('is built from the named share route', function () {
        $client = sharedClient();

        actingAs($client->user)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page->where('client.share_url', route('share.show', $client->share_token)));
    });
});
