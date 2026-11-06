<?php

declare(strict_types=1);

use App\Enums\ShareLocale;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

function baMachine(?User $user = null, array $attributes = []): Machine
{
    return Machine::factory()->for($user ?? User::factory()->create())->create($attributes);
}

function baReport(Machine $machine, string $at, array $flags = [], ?string $disk = null, ?bool $admin = true, array $extra = []): Report
{
    $header = $disk === null ? '' : "- Free disk: {$disk}\n";

    return Report::factory()->for($machine)->create([
        'markdown' => "# Windows checkup: PC\n\n{$header}\n## Things to look at\n",
        'flags' => $flags,
        'ran_as_admin' => $admin,
        'created_at' => $at,
        ...$extra,
    ]);
}

function baPath(Machine $machine, string $query = ''): string
{
    return "/machines/{$machine->id}/before-after".$query;
}

function baProps(User $user, string $path): array
{
    $props = [];

    actingAs($user)->get($path)->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];

        return $page;
    });

    return $props;
}

function twoScans(Machine $machine): array
{
    return [
        baReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.', 'A restart is pending.', 'Only about 8% of disk is free.'], '8%'),
        baReport($machine, '2026-10-20 10:00:00', ['A restart is pending.', 'Screen Sharing is on.'], '41%'),
    ];
}

describe('the owner view', function () {
    it('compares the previous and the latest scan by default', function () {
        $machine = baMachine(attributes: ['name' => 'Kassa', 'serial' => 'SERIAL123']);
        twoScans($machine);
        baReport($machine, '2026-09-01 10:00:00', ['Odd thing.']);

        $props = baProps($machine->user, baPath($machine));

        expect($props['comparison'])->toBe([
            'before' => '2026-10-12T10:00:00.000000Z',
            'after' => '2026-10-20T10:00:00.000000Z',
            'same_rights' => true,
            'fixed' => ['The disk is almost full, only 8% left', 'Disk encryption is off'],
            'open' => ['A restart is pending'],
            'new' => ['Screen Sharing is turned on'],
            'numbers' => [['key' => 'free_disk', 'from' => '8%', 'to' => '41%', 'improved' => true]],
        ])
            ->and($props['machine']['name'])->toBe('Kassa')
            ->and($props['machine'])->not->toHaveKey('serial')
            ->and($props['client'])->toBeNull()
            ->and(array_column($props['scans'], 'scanned_at'))->toBe(['2026-10-20T10:00:00.000000Z', '2026-10-12T10:00:00.000000Z', '2026-09-01T10:00:00.000000Z']);
    });

    it('lets the owner pick any two scans and puts the older one first', function () {
        $machine = baMachine();
        [$old, $new] = twoScans($machine);
        $oldest = baReport($machine, '2026-09-01 10:00:00', ['Odd thing.']);

        $props = baProps($machine->user, baPath($machine, "?before={$new->id}&after={$oldest->id}"));

        expect($props['selected'])->toBe(['before' => $oldest->id, 'after' => $new->id])
            ->and($props['comparison']['before'])->toBe('2026-09-01T10:00:00.000000Z');
    });

    it('fills in the missing side of a pick', function () {
        $machine = baMachine();
        [$old, $new] = twoScans($machine);
        $oldest = baReport($machine, '2026-09-01 10:00:00');

        expect(baProps($machine->user, baPath($machine, "?after={$old->id}"))['selected'])->toBe(['before' => $oldest->id, 'after' => $old->id])
            ->and(baProps($machine->user, baPath($machine, "?before={$old->id}"))['selected'])->toBe(['before' => $old->id, 'after' => $new->id]);
    });

    it('breaks a tie between scans from the same second by id', function () {
        $machine = baMachine();
        $first = baReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.']);
        $second = baReport($machine, '2026-10-12 10:00:00', []);
        $third = baReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.']);

        expect(baProps($machine->user, baPath($machine, "?after={$third->id}"))['selected'])->toBe(['before' => $second->id, 'after' => $third->id])
            ->and(baProps($machine->user, baPath($machine, "?after={$second->id}"))['selected'])->toBe(['before' => $first->id, 'after' => $second->id]);
    });

    it('sends the same scan twice back to the default comparison', function () {
        $machine = baMachine();
        [$old] = twoScans($machine);

        actingAs($machine->user)->get(baPath($machine, "?before={$old->id}&after={$old->id}"))->assertRedirect(baPath($machine));
        actingAs($machine->user)->get(baPath($machine, "?before={$old->id}&after={$old->id}&lang=nl"))->assertRedirect(baPath($machine, '?lang=nl'));
    });

    it('treats an empty pick as no pick', function () {
        $machine = baMachine();
        [$old, $new] = twoScans($machine);

        expect(baProps($machine->user, baPath($machine, '?before=&after='))['selected'])->toBe(['before' => $old->id, 'after' => $new->id]);
    });

    it('answers 404 without two scans', function () {
        $machine = baMachine();
        actingAs($machine->user)->get(baPath($machine))->assertNotFound();

        $only = baReport($machine, '2026-10-12 10:00:00');
        actingAs($machine->user)->get(baPath($machine))->assertNotFound();
        actingAs($machine->user)->get(baPath($machine, "?after={$only->id}"))->assertNotFound();
    });

    it('answers 404 for a pick that is not a scan of this machine', function (string $kind) {
        $machine = baMachine();
        [$old] = twoScans($machine);
        $trashed = baReport($machine, '2026-10-15 10:00:00');
        $trashed->delete();
        $siblingScan = baReport(baMachine($machine->user), '2026-10-15 10:00:00');
        $strangerScan = baReport(baMachine(), '2026-10-15 10:00:00');

        $pick = match ($kind) {
            'another machine of the owner' => (string) $siblingScan->id,
            'another account' => (string) $strangerScan->id,
            'soft deleted' => (string) $trashed->id,
            'unknown' => '999999',
            'not a number' => 'abc',
            'leading zero' => '0'.$old->id,
            'negative' => '-1',
            'zero' => '0',
            'too long' => str_repeat('9', 40),
            default => "{$old->id} or 1=1",
        };

        actingAs($machine->user)->get(baPath($machine, '?before='.urlencode($pick)))->assertNotFound();
        actingAs($machine->user)->get(baPath($machine, '?after='.urlencode($pick)))->assertNotFound();
    })->with(['another machine of the owner', 'another account', 'soft deleted', 'unknown', 'not a number', 'leading zero', 'negative', 'zero', 'too long', 'sql']);

    it('answers 404 for a pick given as a list', function () {
        $machine = baMachine();
        twoScans($machine);

        actingAs($machine->user)->get(baPath($machine, '?before[]=1'))->assertNotFound();
    });

    it('ignores a soft deleted scan when choosing the default', function () {
        $machine = baMachine();
        [$old, $new] = twoScans($machine);
        baReport($machine, '2026-10-25 10:00:00', ['Odd thing.'])->delete();

        $props = baProps($machine->user, baPath($machine));

        expect($props['selected'])->toBe(['before' => $old->id, 'after' => $new->id])
            ->and($props['scans'])->toHaveCount(2);
    });

    it('is closed to other accounts and to guests', function () {
        $machine = baMachine();
        twoScans($machine);

        actingAs(User::factory()->create())->get(baPath($machine))->assertForbidden();
        auth()->logout();
        $this->get(baPath($machine))->assertRedirect('/login');
    });

    it('does not claim a fix when the scans ran with different or unknown rights', function (?bool $before, ?bool $after) {
        $machine = baMachine();
        baReport($machine, '2026-10-12 10:00:00', ['Defender real-time protection is OFF.'], admin: $before);
        baReport($machine, '2026-10-20 10:00:00', [], admin: $after);

        expect(baProps($machine->user, baPath($machine))['comparison']['same_rights'])->toBeFalse();
    })->with([[true, false], [false, true], [null, null], [true, null]]);

    it('copes with old reports that lack a header, facts and flags', function () {
        $machine = baMachine();
        baReport($machine, '2026-10-12 10:00:00', [], extra: ['markdown' => 'just text', 'format_version' => null]);
        baReport($machine, '2026-10-20 10:00:00', ['A restart is pending.'], '30%');

        $comparison = baProps($machine->user, baPath($machine))['comparison'];

        expect($comparison['new'])->toBe(['A restart is pending'])
            ->and($comparison['fixed'])->toBe([])
            ->and($comparison['numbers'])->toBe([]);
    });

    it('shows a number only when both scans state it and it changed', function () {
        $machine = baMachine();
        baReport($machine, '2026-10-12 10:00:00', disk: '40%', extra: ['benchmark' => ['disk_read_mb_s' => 120.4, 'disk_write_mb_s' => 90]]);
        baReport($machine, '2026-10-20 10:00:00', disk: '40%', extra: ['benchmark' => ['disk_read_mb_s' => 510.2]]);

        expect(baProps($machine->user, baPath($machine))['comparison']['numbers'])->toBe([
            ['key' => 'disk_read', 'from' => '120 MB/s', 'to' => '510 MB/s', 'improved' => true],
        ]);
    });

    it('speaks the language of the client unless one is asked for', function () {
        $client = Client::factory()->create(['locale' => ShareLocale::Dutch]);
        $machine = baMachine($client->user, ['client_id' => $client->id]);
        twoScans($machine);

        expect(baProps($machine->user, baPath($machine))['comparison']['new'])->toBe(['Scherm delen staat aan'])
            ->and(baProps($machine->user, baPath($machine, '?lang=en'))['comparison']['new'])->toBe(['Screen Sharing is turned on'])
            ->and(baProps($machine->user, baPath($machine, '?lang=zz'))['comparison']['new'])->toBe(['Scherm delen staat aan'])
            ->and(baProps($machine->user, baPath($machine))['client'])->toBe(['name' => $client->name]);
    });

    it('keeps the scanner text of an unrecognised flag for the owner', function () {
        $machine = baMachine();
        baReport($machine, '2026-10-12 10:00:00');
        baReport($machine, '2026-10-20 10:00:00', ['Odd thing.']);

        expect(baProps($machine->user, baPath($machine))['comparison']['new'])->toBe(['Odd thing']);
    });
});

function sharedBeforeAfter(): array
{
    $client = Client::factory()->withShareLink()->create(['locale' => ShareLocale::English]);
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'name' => 'Kassa', 'serial' => 'SERIAL123']);

    return [$client, $machine, "/c/{$client->share_token}/machines/{$machine->id}/before-after"];
}

describe('the client view', function () {
    it('shows the comparison without owner data or raw text', function () {
        [$client, $machine, $path] = sharedBeforeAfter();
        baReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.'], '8%');
        baReport($machine, '2026-10-20 10:00:00', ['Odd thing 42.'], '41%');

        $this->get($path)->assertOk()->assertInertia(fn ($page) => $page
            ->component('share/BeforeAfter')
            ->where('token', $client->share_token)
            ->where('client', ['name' => $client->name])
            ->where('machine.name', 'Kassa')
            ->where('comparison.fixed', ['Disk encryption is off'])
            ->where('comparison.new', ['Something to look at'])
            ->where('comparison.numbers.0.key', 'free_disk')
            ->missing('selected')
            ->missing('scans'));

        expect($this->get($path)->getContent())->not->toContain('SERIAL123')->not->toContain('Odd thing 42')->not->toContain('Things to look at');
    });

    it('ignores picks in the query', function () {
        [, $machine, $path] = sharedBeforeAfter();
        $old = baReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.']);
        baReport($machine, '2026-10-20 10:00:00');
        baReport(baMachine(), '2026-10-21 10:00:00');

        $this->get($path."?before={$old->id}&after=999")->assertOk()->assertInertia(fn ($page) => $page->where('comparison.fixed', ['Disk encryption is off']));
    });

    it('answers 404 with fewer than two scans, deleted ones not counted', function () {
        [, $machine, $path] = sharedBeforeAfter();
        $this->get($path)->assertNotFound();

        baReport($machine, '2026-10-12 10:00:00');
        $this->get($path)->assertNotFound();

        baReport($machine, '2026-10-20 10:00:00')->delete();
        $this->get($path)->assertNotFound();
    });

    it('answers 404 for another client, a bad token or a bad id', function () {
        [$client, $machine, $path] = sharedBeforeAfter();
        twoScans($machine);
        $other = Client::factory()->withShareLink()->create();

        $this->get("/c/{$other->share_token}/machines/{$machine->id}/before-after")->assertNotFound();
        $this->get("/c/short/machines/{$machine->id}/before-after")->assertNotFound();
        $this->get("/c/{$client->share_token}/machines/abc/before-after")->assertNotFound();
        $this->get("/c/{$client->share_token}/machines/0{$machine->id}/before-after")->assertNotFound();
        $this->get($path)->assertOk();
    });

    it('is offered on the machine page only with two scans', function () {
        [$client, $machine] = sharedBeforeAfter();
        $url = "/c/{$client->share_token}/machines/{$machine->id}";

        $this->get($url)->assertInertia(fn ($page) => $page->where('canCompare', false));

        baReport($machine, '2026-10-12 10:00:00');
        $this->get($url)->assertInertia(fn ($page) => $page->where('canCompare', false));

        baReport($machine, '2026-10-20 10:00:00');
        $this->get($url)->assertInertia(fn ($page) => $page->where('canCompare', true));
    });

    it('is written in the language of the page', function () {
        [, $machine, $path] = sharedBeforeAfter();
        twoScans($machine);

        $this->get($path.'?lang=nl')->assertInertia(fn ($page) => $page->where('comparison.new', ['Scherm delen staat aan'])->where('locale', 'nl'));
    });
});
