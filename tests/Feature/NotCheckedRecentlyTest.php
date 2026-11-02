<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportFreshness;
use Illuminate\Support\Facades\Date;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Date::setTestNow('2026-11-02 12:00:00');
});

function reportAged(Machine $machine, string $age, array $attributes = []): Report
{
    return Report::factory()->for($machine)->create([
        'created_at' => Date::now()->modify($age),
        ...$attributes,
    ]);
}

function ownerMachine(): array
{
    $user = User::factory()->create();

    return [$user, Machine::factory()->for($user)->create()];
}

function expectIndexStale(User $user, bool $stale): void
{
    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page->where('machines.data.0.stale', $stale));
}

function expectShowStale(User $user, Machine $machine, bool $stale): void
{
    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page->where('report.stale', $stale));
}

describe('the 30 day boundary', function () {
    it('keeps the threshold in one named constant', function () {
        expect(ReportFreshness::STALE_AFTER_DAYS)->toBe(30);
    });

    it('is not stale for a report made right now', function () {
        [, $machine] = ownerMachine();

        expect(app(ReportFreshness::class)->isStale(reportAged($machine, 'now')))->toBeFalse();
    });

    it('is not stale 29 days and 23 hours after the report', function () {
        [, $machine] = ownerMachine();

        expect(app(ReportFreshness::class)->isStale(reportAged($machine, '-29 days -23 hours')))->toBeFalse();
    });

    it('is not stale exactly 30 days after the report', function () {
        [, $machine] = ownerMachine();

        expect(app(ReportFreshness::class)->isStale(reportAged($machine, '-30 days')))->toBeFalse();
    });

    it('is stale 30 days and one second after the report', function () {
        [, $machine] = ownerMachine();

        expect(app(ReportFreshness::class)->isStale(reportAged($machine, '-30 days -1 second')))->toBeTrue();
    });

    it('is stale 31 days after the report', function () {
        [, $machine] = ownerMachine();

        expect(app(ReportFreshness::class)->isStale(reportAged($machine, '-31 days')))->toBeTrue();
    });

    it('is not stale for a report dated in the future', function () {
        [, $machine] = ownerMachine();

        expect(app(ReportFreshness::class)->isStale(reportAged($machine, '+2 days')))->toBeFalse();
    });

    it('is not stale for a machine with no report', function () {
        expect(app(ReportFreshness::class)->isStale(null))->toBeFalse();
    });

    it('follows the app clock', function () {
        [, $machine] = ownerMachine();
        $report = reportAged($machine, '-29 days');

        expect(app(ReportFreshness::class)->isStale($report))->toBeFalse();

        $this->travel(3)->days();

        expect(app(ReportFreshness::class)->isStale($report))->toBeTrue();
    });
});

describe('the owner machines list', function () {
    it('flags a machine whose newest report is 31 days old', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-31 days');

        expectIndexStale($user, true);
    });

    it('does not flag exactly 30 days', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-30 days');

        expectIndexStale($user, false);
    });

    it('does not flag a machine that has no report', function () {
        [$user] = ownerMachine();

        expectIndexStale($user, false);
    });

    it('does not flag a future-dated report', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '+5 days');

        expectIndexStale($user, false);
    });

    it('judges by the newest created_at, not the newest id', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-5 days');
        reportAged($machine, '-90 days');

        expectIndexStale($user, false);
    });

    it('is stale when every report is old, whatever order they were stored in', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-40 days');
        reportAged($machine, '-90 days');

        expectIndexStale($user, true);
    });

    it('is not stale when the newest of several reports is fresh', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-90 days');
        reportAged($machine, '-60 days');
        reportAged($machine, '-1 day');

        expectIndexStale($user, false);
    });

    it('ignores a soft-deleted fresh report', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-90 days');
        reportAged($machine, '-1 day')->delete();

        expectIndexStale($user, true);
    });

    it('treats a machine whose only report was deleted as having none', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-90 days')->delete();

        expectIndexStale($user, false);
    });

    it('flags each machine in the list on its own', function () {
        $user = User::factory()->create();
        $old = Machine::factory()->for($user)->create(['name' => 'Old']);
        $fresh = Machine::factory()->for($user)->create(['name' => 'Fresh']);
        Machine::factory()->for($user)->create(['name' => 'Manual']);
        reportAged($old, '-90 days');
        reportAged($fresh, '-1 day');

        $stale = collect(actingAs($user)->get('/machines')->viewData('page')['props']['machines']['data'])
            ->pluck('stale', 'name')->all();

        expect($stale)->toEqual(['Manual' => false, 'Fresh' => false, 'Old' => true]);
    });

    it('keeps the status and issue count untouched on a stale machine', function () {
        [$user, $machine] = ownerMachine();
        $machine->forceFill(['status' => 'ok'])->save();
        reportAged($machine, '-90 days', ['flags' => ['FileVault is OFF.']]);

        actingAs($user)->get('/machines')->assertInertia(fn ($page) => $page
            ->where('machines.data.0.stale', true)
            ->where('machines.data.0.status', 'ok')
            ->where('machines.data.0.issue_count', 1)
            ->where('machines.data.0.top_issue.headline', 'Disk encryption is off'));
    });
});

describe('the owner machine page', function () {
    it('flags the report when it is 31 days old', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-31 days');

        expectShowStale($user, $machine, true);
    });

    it('does not flag exactly 30 days', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-30 days');

        expectShowStale($user, $machine, false);
    });

    it('does not flag 29 days and 23 hours', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-29 days -23 hours');

        expectShowStale($user, $machine, false);
    });

    it('flags at 30 days and one second', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-30 days -1 second');

        expectShowStale($user, $machine, true);
    });

    it('sends no report, so no flag, for a machine without one', function () {
        [$user, $machine] = ownerMachine();

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('report', null));
    });

    it('does not flag a future-dated report', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '+5 days');

        expectShowStale($user, $machine, false);
    });

    it('judges by the newest created_at, not the newest id', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-5 days');
        reportAged($machine, '-90 days');

        expectShowStale($user, $machine, false);
    });

    it('ignores a soft-deleted fresh report', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-90 days');
        reportAged($machine, '-1 day')->delete();

        expectShowStale($user, $machine, true);
    });

    it('keeps the advice untouched on a stale machine', function () {
        [$user, $machine] = ownerMachine();
        reportAged($machine, '-90 days', ['flags' => ['FileVault is OFF.']]);

        actingAs($user)->get("/machines/{$machine->id}")->assertInertia(fn ($page) => $page
            ->where('report.stale', true)
            ->where('report.advice.0.headline', 'Disk encryption is off'));
    });
});

describe('the client share pages', function () {
    function oldSharedMachine(): array
    {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'name' => 'Front-desk']);
        reportAged($machine, '-90 days', ['flags' => ['FileVault is OFF.']]);

        return [$client, $machine];
    }

    it('carries no stale flag in the props of the client page', function () {
        [$client] = oldSharedMachine();

        $this->get("/c/{$client->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('share/Show')
                ->where('machines.0.scanned_at', Date::now()->subDays(90)->toJSON())
                ->missing('machines.0.stale')
                ->missing('machines.0.stale_days'));
    });

    it('carries no stale flag in the props of the machine page', function () {
        [$client, $machine] = oldSharedMachine();

        $this->get("/c/{$client->share_token}/machines/{$machine->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('share/Machine')
                ->where('report.scanned_at', Date::now()->subDays(90)->toJSON())
                ->missing('report.stale')
                ->missing('machine.stale'));
    });

    it('has no warning wording anywhere in the HTML of either page', function () {
        [$client, $machine] = oldSharedMachine();

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            expect(strtolower($html))
                ->not->toContain('stale')
                ->not->toContain('outdated')
                ->not->toContain('not checked recently')
                ->not->toContain('overdue');
        }
    });
});
