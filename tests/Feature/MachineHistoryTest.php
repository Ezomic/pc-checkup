<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

const HISTORY_OLD_OS = 'macOS 10.14.6 is old and likely out of security support.';
const HISTORY_NO_AV = 'No antivirus is protecting this PC';

/**
 * @param  array<string, string>  $header
 */
function historyMarkdown(array $header = []): string
{
    $lines = ['# Checkup', '', '- Format: 1', '- Script version: 7'];

    foreach (['Model' => 'MacBook Pro (MacBookPro18,1)', 'macOS' => '14.5 (23F79)', 'Memory' => '16 GB', 'Free disk' => '50%', ...$header] as $key => $value) {
        $lines[] = "- {$key}: {$value}";
    }

    return implode("\n", $lines)."\n\n## Storage (boot volume)\n\n```\n   Solid State:               Yes\n```\n";
}

/**
 * @param  array<string, mixed>  $attributes
 */
function historyReport(Machine $machine, array $attributes = []): Report
{
    return Report::factory()->for($machine)->create([
        'markdown' => historyMarkdown(),
        'created_at' => CarbonImmutable::parse('2026-10-01 10:00:00'),
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function historyProps(User $user, Machine $machine, string $query = ''): array
{
    return actingAs($user)->get("/machines/{$machine->id}/history{$query}")->assertOk()->viewData('page')['props'];
}

describe('GET /machines/{machine}/history', function () {
    it('requires a login', function () {
        $machine = Machine::factory()->create();

        $this->get("/machines/{$machine->id}/history")->assertRedirect('/login');
    });

    it('answers 403 for another user’s machine and 404 for a deleted one', function () {
        $machine = Machine::factory()->create();
        $mine = Machine::factory()->for($user = User::factory()->create())->create();
        $mine->delete();

        actingAs($user)->get("/machines/{$machine->id}/history")->assertForbidden();
        actingAs($user)->get("/machines/{$mine->id}/history")->assertNotFound();
    });

    it('lists the scans newest first with the key numbers', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $old = historyReport($machine, ['created_at' => CarbonImmutable::parse('2026-09-01 10:00:00'), 'ran_as_admin' => false]);
        $new = historyReport($machine, [
            'markdown' => historyMarkdown(['Free disk' => '4%', 'Memory' => '2 GB']),
            'flags' => [HISTORY_OLD_OS, HISTORY_NO_AV],
            'ran_as_admin' => true,
            'created_at' => CarbonImmutable::parse('2026-10-20 10:00:00'),
        ]);

        $scans = historyProps($user, $machine)['scans'];

        expect(array_column($scans['data'], 'id'))->toBe([$new->id, $old->id])
            ->and($scans['data'][0])->toMatchArray([
                'ran_as_admin' => true,
                'free_disk' => '4%',
                'flags_count' => 2,
                'kind' => 'audit',
                'script_version' => '7',
                'verdict' => 'Repair',
            ])
            ->and($scans['data'][1])->toMatchArray(['ran_as_admin' => false, 'free_disk' => '50%', 'flags_count' => 0, 'verdict' => 'Keep']);
    });

    it('shows missing data as null instead of guessing', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        historyReport($machine, ['markdown' => "# Checkup\n\nNothing parsable here\n", 'ran_as_admin' => null]);

        expect(historyProps($user, $machine)['scans']['data'][0])->toMatchArray([
            'ran_as_admin' => null,
            'free_disk' => null,
            'script_version' => null,
            'verdict' => null,
            'flags_count' => 0,
        ]);
    });

    it('works for a machine without a client and without scans', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => null]);

        $props = historyProps($user, $machine);

        expect($props['scans']['data'])->toBe([])
            ->and($props['scans']['total'])->toBe(0)
            ->and($props['comparison'])->toBeNull();
    });

    it('leaves out deleted scans and other machines’ scans', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $kept = historyReport($machine);
        historyReport($machine)->delete();
        historyReport(Machine::factory()->for($user)->create());

        expect(array_column(historyProps($user, $machine)['scans']['data'], 'id'))->toBe([$kept->id]);
    });

    it('paginates 200 scans in pages of 50, keeping the comparison in the links', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $first = historyReport($machine);
        $second = historyReport($machine);
        Report::factory()->count(198)->for($machine)->create(['markdown' => historyMarkdown(), 'created_at' => CarbonImmutable::parse('2026-10-02 10:00:00')]);

        $props = historyProps($user, $machine, "?page=2&from={$first->id}&to={$second->id}");

        expect($props['scans']['total'])->toBe(200)
            ->and($props['scans']['per_page'])->toBe(50)
            ->and($props['scans']['data'])->toHaveCount(50)
            ->and($props['scans']['current_page'])->toBe(2)
            ->and($props['scans']['next_page_url'])->toContain("from={$first->id}");
    });

    it('runs the same number of queries for three scans as for sixty', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Report::factory()->count(3)->for($machine)->create(['markdown' => historyMarkdown()]);
        actingAs($user);

        DB::enableQueryLog();
        $this->get("/machines/{$machine->id}/history")->assertOk();
        $few = count(DB::getQueryLog());

        Report::factory()->count(57)->for($machine)->create(['markdown' => historyMarkdown(['Free disk' => '4%'])]);
        DB::flushQueryLog();
        $this->get("/machines/{$machine->id}/history")->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });
});

describe('comparing two scans', function () {
    it('shows what changed between the older and the newer scan, whichever way round they are asked for', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $before = historyReport($machine, [
            'markdown' => historyMarkdown(['Free disk' => '40%']),
            'flags' => [HISTORY_NO_AV],
            'ran_as_admin' => true,
        ]);
        $after = historyReport($machine, [
            'markdown' => historyMarkdown(['Free disk' => '30%', 'Memory' => '32 GB']),
            'flags' => [HISTORY_OLD_OS],
            'ran_as_admin' => true,
            'created_at' => CarbonImmutable::parse('2026-10-20 10:00:00'),
        ]);

        $forward = historyProps($user, $machine, "?from={$before->id}&to={$after->id}");
        $backward = historyProps($user, $machine, "?from={$after->id}&to={$before->id}");

        expect($forward['comparison'])->toBe($backward['comparison'])
            ->and($forward['comparison_error'])->toBeNull()
            ->and($forward['selected'])->toBe([$before->id, $after->id])
            ->and($forward['comparison']['before']['id'])->toBe($before->id)
            ->and($forward['comparison']['after']['id'])->toBe($after->id)
            ->and($forward['comparison']['new'])->toHaveCount(1)
            ->and($forward['comparison']['fixed'])->toHaveCount(1)
            ->and($forward['comparison']['open'])->toBe(0)
            ->and($forward['comparison']['disk'])->toBe(['from' => '40%', 'to' => '30%'])
            ->and($forward['comparison']['memory'])->toBe(['from' => '16 GB', 'to' => '32 GB'])
            ->and($forward['comparison']['same_rights'])->toBeTrue();
    });

    it('breaks a tie on the timestamp by the id and flags a different scan context', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $one = historyReport($machine, ['ran_as_admin' => true, 'flags' => [HISTORY_NO_AV]]);
        $two = historyReport($machine, ['ran_as_admin' => false, 'flags' => [HISTORY_NO_AV]]);

        $comparison = historyProps($user, $machine, "?from={$two->id}&to={$one->id}")['comparison'];

        expect($comparison['before']['id'])->toBe($one->id)
            ->and($comparison['open'])->toBe(1)
            ->and($comparison['disk'])->toBeNull()
            ->and($comparison['verdict'])->toBeNull()
            ->and($comparison['same_rights'])->toBeFalse();
    });

    it('reports a changed verdict and ignores numbers it cannot read', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $before = historyReport($machine, ['markdown' => historyMarkdown(['Free disk' => 'n/a', 'Memory' => 'lots'])]);
        $after = historyReport($machine, [
            'markdown' => historyMarkdown(['Free disk' => '2%', 'Memory' => '2 GB']),
            'flags' => [HISTORY_OLD_OS],
            'created_at' => CarbonImmutable::parse('2026-10-20 10:00:00'),
        ]);

        $comparison = historyProps($user, $machine, "?from={$before->id}&to={$after->id}")['comparison'];

        expect($comparison['verdict'])->toBe(['from' => 'Keep', 'to' => 'Repair'])
            ->and($comparison['disk'])->toBeNull()
            ->and($comparison['memory'])->toBeNull();
    });

    it('compares decimal commas as numbers', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $before = historyReport($machine, ['markdown' => historyMarkdown(['Memory' => '7,5 GB'])]);
        $after = historyReport($machine, ['markdown' => historyMarkdown(['Memory' => '7.5 GB']), 'created_at' => CarbonImmutable::parse('2026-10-20 10:00:00')]);

        expect(historyProps($user, $machine, "?from={$before->id}&to={$after->id}")['comparison']['memory'])->toBeNull();
    });

    it('refuses to compare a scan with itself', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $report = historyReport($machine);

        $props = historyProps($user, $machine, "?from={$report->id}&to={$report->id}");

        expect($props['comparison'])->toBeNull()
            ->and($props['comparison_error'])->toBe('Pick two different scans to compare.');
    });

    it('does not compare scans of another machine, of another user or deleted ones', function (string $case) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $mine = historyReport($machine);
        $foreign = match ($case) {
            'same user' => historyReport(Machine::factory()->for($user)->create()),
            'other user' => historyReport(Machine::factory()->create()),
            'deleted' => tap(historyReport($machine), fn (Report $report) => $report->delete()),
            default => $mine->replicate()->forceFill(['id' => 999999]),
        };

        $props = historyProps($user, $machine, "?from={$mine->id}&to={$foreign->id}");

        expect($props['comparison'])->toBeNull()
            ->and($props['comparison_error'])->toBe('One of those scans is not available for this machine.');
    })->with(['same user', 'other user', 'deleted', 'unknown']);

    it('ignores incomplete or garbage selections', function (string $query) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        historyReport($machine);

        $props = historyProps($user, $machine, $query);

        expect($props['comparison'])->toBeNull()
            ->and($props['comparison_error'])->toBeNull()
            ->and($props['selected'])->toBe([]);
    })->with(['?from=1', '?to=1', '?from=abc&to=def', '?from[]=1&to[]=2', '?from=1.5&to=2']);
});
