<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

function changesMachine(): array
{
    $user = User::factory()->create();

    return [$user, Machine::factory()->for($user)->create()];
}

function changesReport(Machine $machine, string $at, array $flags = [], ?string $disk = null, ?string $memory = null): Report
{
    $header = ($memory === null ? '' : "- Memory: {$memory}\n").($disk === null ? '' : "- Free disk: {$disk}\n");

    return Report::factory()->for($machine)->create([
        'markdown' => "# Windows checkup: PC\n\n{$header}\n## Things to look at\n",
        'flags' => $flags,
        'created_at' => $at,
    ]);
}

function ownerChanges(User $user, Machine $machine): ?array
{
    $changes = null;

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$changes) {
            $changes = $page->toArray()['props']['changes'];

            return $page;
        });

    return $changes;
}

describe('the owner page', function () {
    it('shows nothing for a machine without reports', function () {
        [$user, $machine] = changesMachine();

        expect(ownerChanges($user, $machine))->toBeNull();
    });

    it('shows nothing for a machine with exactly one report', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.']);

        expect(ownerChanges($user, $machine))->toBeNull();
    });

    it('reports nothing changed for two reports with the same flags in another order', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.', 'A restart is pending.'], '40%', '16 GB');
        changesReport($machine, '2026-10-20 10:00:00', ['A restart is pending.', 'FileVault is OFF.'], '40%', '16 GB');

        expect(ownerChanges($user, $machine))->toBe([
            'previous_at' => '2026-10-12T10:00:00.000000Z',
            'new' => [],
            'fixed' => [],
            'open' => 2,
            'disk' => null,
            'memory' => null,
        ]);
    });

    it('lists a new issue and a fixed issue by their plain headline', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.', 'A restart is pending.']);
        changesReport($machine, '2026-10-20 10:00:00', ['A restart is pending.', 'Secure Boot is OFF.']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe(['Secure Boot is off'])
            ->and($changes['fixed'])->toBe(['Disk encryption is off'])
            ->and($changes['open'])->toBe(1);
    });

    it('treats the same flag with a different number as the same issue', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['Only about 9% of drive C: is free.']);
        changesReport($machine, '2026-10-20 10:00:00', ['Only about 7% of drive C: is free.']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe([])->and($changes['fixed'])->toBe([])->and($changes['open'])->toBe(1);
    });

    it('treats a low drive C: and a low drive D: as different issues', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['Only about 9% of drive C: is free.']);
        changesReport($machine, '2026-10-20 10:00:00', ['Only about 9% of drive D: is free.']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe(['Drive D: is almost full, only 9% left'])
            ->and($changes['fixed'])->toBe(['Drive C: is almost full, only 9% left'])
            ->and($changes['open'])->toBe(0);
    });

    it('counts duplicated flags once', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.', 'FileVault is OFF.', 'BitLocker is OFF on C:.']);
        changesReport($machine, '2026-10-20 10:00:00', ['Secure Boot is OFF.', 'Secure Boot is OFF.']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe(['Secure Boot is off'])
            ->and($changes['fixed'])->toBe(['Disk encryption is off'])
            ->and($changes['open'])->toBe(0);
    });

    it('compares flags no rule recognises by their text without numbers', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['The toaster ran 5 times.', 'The kettle is loud.']);
        changesReport($machine, '2026-10-20 10:00:00', ['The toaster ran 9 times.', 'The fridge is warm.']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe(['The fridge is warm'])
            ->and($changes['fixed'])->toBe(['The kettle is loud'])
            ->and($changes['open'])->toBe(1);
    });

    it('compares an older report that has no flags with a newer one that has some', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', []);
        changesReport($machine, '2026-10-20 10:00:00', ['FileVault is OFF.']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe(['Disk encryption is off'])->and($changes['fixed'])->toBe([]);
    });

    it('compares a stored null as no flags', function () {
        [$user, $machine] = changesMachine();
        $old = changesReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.']);
        changesReport($machine, '2026-10-20 10:00:00', []);
        DB::table('reports')->where('id', $old->id)->update(['flags' => 'null']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe([])->and($changes['fixed'])->toBe([])->and($changes['open'])->toBe(0);
    });

    it('counts an after-fix report as the newest check', function () {
        [$user, $machine] = changesMachine();
        $audit = changesReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.', 'Secure Boot is OFF.']);
        changesReport($machine, '2026-10-13 10:00:00', ['Secure Boot is OFF.'])
            ->forceFill(['kind' => 'after-fix', 'before_report_id' => $audit->id])->save();

        $changes = ownerChanges($user, $machine);

        expect($changes['fixed'])->toBe(['Disk encryption is off'])->and($changes['open'])->toBe(1);
    });

    it('breaks a tie on created_at by id', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['FileVault is OFF.']);
        changesReport($machine, '2026-10-12 10:00:00', ['Secure Boot is OFF.']);

        $changes = ownerChanges($user, $machine);

        expect($changes['new'])->toBe(['Secure Boot is off'])->and($changes['fixed'])->toBe(['Disk encryption is off']);
    });

    it('skips a soft-deleted newest report', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-01 10:00:00', ['FileVault is OFF.']);
        changesReport($machine, '2026-10-12 10:00:00', ['Secure Boot is OFF.']);
        changesReport($machine, '2026-10-20 10:00:00', ['A restart is pending.'])->delete();

        $changes = ownerChanges($user, $machine);

        expect($changes['previous_at'])->toBe('2026-10-01T10:00:00.000000Z')
            ->and($changes['new'])->toBe(['Secure Boot is off'])
            ->and($changes['fixed'])->toBe(['Disk encryption is off']);
    });

    it('shows nothing when deleting the newest report leaves one', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', ['Secure Boot is OFF.']);
        changesReport($machine, '2026-10-20 10:00:00', ['A restart is pending.'])->delete();

        expect(ownerChanges($user, $machine))->toBeNull();
    });

    it('shows a changed disk and memory when both reports have them', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', [], '40%', '8 GB');
        changesReport($machine, '2026-10-20 10:00:00', [], '4%', '16 GB');

        $changes = ownerChanges($user, $machine);

        expect($changes['disk'])->toBe(['from' => '40%', 'to' => '4%'])
            ->and($changes['memory'])->toBe(['from' => '8 GB', 'to' => '16 GB']);
    });

    it('shows no number line that did not change', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', [], '40%', '16 GB');
        changesReport($machine, '2026-10-20 10:00:00', [], '40%', '16.0 GB');

        $changes = ownerChanges($user, $machine);

        expect($changes['disk'])->toBeNull()->and($changes['memory'])->toBeNull();
    });

    it('shows no number line when a value is missing in one of the reports', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', [], null, '8 GB');
        changesReport($machine, '2026-10-20 10:00:00', [], '4%', null);

        $changes = ownerChanges($user, $machine);

        expect($changes['disk'])->toBeNull()->and($changes['memory'])->toBeNull();
    });

    it('shows no number line for facts it cannot read', function () {
        [$user, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00', [], 'n/a%', 'lots');
        changesReport($machine, '2026-10-20 10:00:00', [], '4%', '16 GB');

        $changes = ownerChanges($user, $machine);

        expect($changes['disk'])->toBeNull()->and($changes['memory'])->toBeNull();
    });

    it('copes with markdown that has no facts at all', function () {
        [$user, $machine] = changesMachine();
        Report::factory()->for($machine)->create(['markdown' => 'just text', 'created_at' => '2026-10-12 10:00:00']);
        Report::factory()->for($machine)->create(['markdown' => '', 'created_at' => '2026-10-20 10:00:00']);

        $changes = ownerChanges($user, $machine);

        expect($changes['disk'])->toBeNull()->and($changes['memory'])->toBeNull()->and($changes['open'])->toBe(0);
    });

    it('forbids another user’s machine', function () {
        [, $machine] = changesMachine();
        changesReport($machine, '2026-10-12 10:00:00');
        changesReport($machine, '2026-10-20 10:00:00');

        actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
    });
});

describe('the client share page', function () {
    function sharedPair(array $before, array $after): array
    {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);
        changesReport($machine, '2026-10-12 10:00:00', $before, '40%', '8 GB');
        changesReport($machine, '2026-10-20 10:00:00', $after, '4%', '16 GB');

        return [$client, $machine];
    }

    it('carries only the number of fixed issues', function () {
        [$client, $machine] = sharedPair(
            ['FileVault is OFF.', 'A restart is pending.', 'Gatekeeper is DISABLED.'],
            ['Secure Boot is OFF.', 'A restart is pending.'],
        );

        $this->get("/c/{$client->share_token}/machines/{$machine->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.fixed_count', 2)
                ->has('report', 4)
                ->has('report.facts', 3));
    });

    it('leaks no new issue, previous report or number into the page', function () {
        [$client, $machine] = sharedPair(
            ['FileVault is OFF.', 'Only about 9% of drive C: is free.'],
            ['Secure Boot is OFF.', 'Only about 7% of drive C: is free.'],
        );

        $response = $this->get("/c/{$client->share_token}/machines/{$machine->id}")->assertOk();
        $props = json_encode($response->viewData('page')['props']);

        expect($props)->not->toContain('2026-10-12')
            ->and($props)->not->toContain('previous')
            ->and($props)->not->toContain('"new"')
            ->and($props)->not->toContain('worse')
            ->and($props)->not->toContain('changes')
            ->and($props)->not->toContain('8 GB')
            ->and($props)->not->toContain('40%')
            ->and($props)->not->toContain('Disk encryption is off');
    });

    it('carries zero when nothing was fixed or there is a single report', function () {
        [$client, $machine] = sharedPair(['FileVault is OFF.'], ['FileVault is OFF.', 'Secure Boot is OFF.']);

        $this->get("/c/{$client->share_token}/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('report.fixed_count', 0));

        $lone = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);
        changesReport($lone, '2026-10-20 10:00:00', ['FileVault is OFF.']);

        $this->get("/c/{$client->share_token}/machines/{$lone->id}")
            ->assertInertia(fn ($page) => $page->where('report.fixed_count', 0));
    });

    it('leaves the card list without any change information', function () {
        [$client] = sharedPair(['FileVault is OFF.'], []);

        $this->get("/c/{$client->share_token}")
            ->assertInertia(fn ($page) => $page->missing('machines.0.fixed_count'));
    });
});
