<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * @param  list<string>  $markdowns  oldest first, one scan every thirty days
 */
function scanHistory(Machine $machine, array $markdowns, int $everyDays = 30): void
{
    $start = CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC');

    foreach ($markdowns as $index => $markdown) {
        Report::factory()->for($machine)->create(['markdown' => $markdown, 'created_at' => $start->addDays($index * $everyDays)]);
    }
}

/**
 * @return list<array{id: string, title: string, points: list<array{at: string, value: float}>, latest: string, change: string, sentence: string|null, tone: string}>
 */
function ownerTrends(Machine $machine): array
{
    $trends = [];

    actingAs($machine->user)->get("/machines/{$machine->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$trends): void {
            $trends = $page->toArray()['props']['trends'];
        });

    return $trends;
}

/**
 * @param  list<float>  $values
 * @return list<string>
 */
function freeDiskHistory(array $values): array
{
    return array_map(fn (float $free): string => trendMarkdown(freeGb: $free, cycles: (int) (400 - $free)), $values);
}

it('gives the owner trends built from the stored reports', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($machine, freeDiskHistory([100, 90, 80, 70, 60, 50]));

    $trends = ownerTrends($machine);

    expect(array_column($trends, 'id'))->toBe(['disk', 'battery'])
        ->and($trends[0]['latest'])->toBe('50 GB')
        ->and($trends[0]['sentence'])->toBe('At this pace the disk is full in about 5 months.')
        ->and($trends[1]['latest'])->toBe('350 cycles')
        ->and($trends[1]['change'])->toBe('+50 cycles');
});

it('uses the camelCase trends prop the page declares', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($machine, freeDiskHistory([100, 90, 80]));

    actingAs($machine->user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page->has('trends.0.points', 3)->has('trends.0.sentence')->where('trends.0.id', 'disk'));
});

it('sends an empty list for a machine with no reports', function () {
    expect(ownerTrends(Machine::factory()->for(User::factory()->create())->create()))->toBe([]);
});

it('sends an empty list when there are not enough scans or days', function () {
    $short = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($short, freeDiskHistory([100, 90]));
    $sameDay = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($sameDay, freeDiskHistory([100, 90, 80]), everyDays: 0);

    expect(ownerTrends($short))->toBe([])->and(ownerTrends($sameDay))->toBe([]);
});

it('keeps the points in created_at order even when the ids say otherwise', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    $start = CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC');

    foreach ([50, 70, 90] as $index => $free) {
        Report::factory()->for($machine)->create(['markdown' => trendMarkdown(freeGb: $free), 'created_at' => $start->addDays((2 - $index) * 30)]);
    }

    $trend = ownerTrends($machine)[0];

    expect(array_column($trend['points'], 'value'))->toEqual([90, 70, 50])
        ->and($trend['latest'])->toBe('50 GB');
});

it('leaves soft deleted reports out', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($machine, freeDiskHistory([100, 90, 80, 70]));
    Report::query()->where('machine_id', $machine->id)->orderBy('id')->limit(2)->get()->each->delete();

    expect(ownerTrends($machine))->toBe([]);
});

it('uses the twelve most recent reports of fifty', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($machine, freeDiskHistory(array_map(fn (int $i): float => 500.0 - $i * 5, range(0, 49))), everyDays: 7);

    $trend = ownerTrends($machine)[0];

    expect($trend['points'])->toHaveCount(12)
        ->and($trend['latest'])->toBe('255 GB')
        ->and($trend['points'][0]['value'])->toEqual(310);
});

it('never mixes in another machine of the same owner', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    $other = Machine::factory()->for($user)->create();
    scanHistory($machine, freeDiskHistory([100, 90, 80]));
    scanHistory($other, freeDiskHistory([400, 300, 200, 100]));

    $trend = ownerTrends($machine)[0];

    expect(array_column($trend['points'], 'value'))->toEqual([100, 90, 80]);
});

it('forbids another user from seeing the machine and so its trends', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($machine, freeDiskHistory([100, 90, 80]));

    actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
});

it('survives scans with n/a values in the middle and corrupt lines', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    scanHistory($machine, [
        trendMarkdown(freeGb: 100, cycles: 100, freePercent: 20),
        str_replace('100 cycles', 'n/a cycles', trendMarkdown(freeGb: 90, cycles: 100, freePercent: 18)),
        str_replace('500Gi', '-500Gi', trendMarkdown(freeGb: 80, cycles: 120, freePercent: 16)),
        trendMarkdown(freeGb: 70, cycles: 99999999, freePercent: 14),
        '',
        trendMarkdown(freeGb: 60, cycles: 140, freePercent: 12),
    ]);

    $trends = ownerTrends($machine);
    $encoded = (string) json_encode($trends);

    expect(array_column($trends, 'id'))->toBe(['disk', 'battery'])
        ->and($trends[0]['points'])->toHaveCount(4)
        ->and($trends[1]['points'])->toHaveCount(3)
        ->and($encoded)->not->toContain('NaN')->not->toContain('INF')->not->toContain('undefined');
});

it('keeps report text out of the trend text', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    $markdowns = array_map(fn (float $free): string => str_replace('Free disk: n/a', 'Free disk: <img src=x onerror=alert(1)>', trendMarkdown(freeGb: $free)), [100, 90, 80]);
    scanHistory($machine, $markdowns);

    expect((string) json_encode(ownerTrends($machine)))->not->toContain('<img');
});

it('keeps all of it off the share pages', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
    scanHistory($machine, freeDiskHistory([100, 90, 80, 70, 60, 50]));

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $encoded = (string) json_encode($props);

                expect($props)->not->toHaveKey('trends')->not->toHaveKey('trend')
                    ->and($encoded)->not->toContain('At this pace')
                    ->and($encoded)->not->toContain('Free disk space')
                    ->and($encoded)->not->toContain('charge cycles');
            });
    }
});
