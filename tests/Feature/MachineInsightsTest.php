<?php

declare(strict_types=1);

use App\Enums\BenchmarkMetric;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

function insightsMachine(): array
{
    $user = User::factory()->create();

    return [$user, Machine::factory()->for($user)->create()];
}

it('shows no after-fix or benchmark data for a plain audit', function () {
    [$user, $machine] = insightsMachine();
    Report::factory()->for($machine)->create();

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->where('afterFix', null)
            ->where('benchmark', null));
});

it('compares an after-fix report with the audit it follows', function () {
    [$user, $machine] = insightsMachine();
    $audit = Report::factory()->for($machine)->create([
        'flags' => ['Firewall is off.', 'FileVault is OFF.'],
        'created_at' => '2026-10-01 10:00:00',
    ]);
    $fixes = [
        ['id' => 'firewall-off', 'status' => 'applied', 'command' => 'socketfilterfw --setglobalstate on', 'undo' => 'socketfilterfw --setglobalstate off'],
        ['id' => 'filevault-off', 'status' => 'skipped', 'command' => 'fdesetup enable', 'undo' => null],
    ];
    $after = Report::factory()->for($machine)->create([
        'kind' => 'after-fix',
        'before_report_id' => $audit->id,
        'flags' => ['FileVault is OFF.', 'Updates pending.'],
        'fixes' => $fixes,
        'created_at' => '2026-10-02 10:00:00',
    ]);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->where('afterFix.id', $after->id)
            ->where('afterFix.before.id', $audit->id)
            ->where('afterFix.resolved', ['Firewall is off.'])
            ->where('afterFix.open', ['FileVault is OFF.'])
            ->where('afterFix.introduced', ['Updates pending.'])
            ->where('afterFix.fixes', $fixes));
});

it('shows the fix log without a comparison when the audit is not on file', function () {
    [$user, $machine] = insightsMachine();
    Report::factory()->for($machine)->create(['kind' => 'after-fix', 'flags' => ['x'], 'fixes' => null]);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->where('afterFix.before', null)
            ->where('afterFix.fixes', [])
            ->where('afterFix.resolved', [])
            ->where('afterFix.open', [])
            ->where('afterFix.introduced', [])
            ->where('benchmark', null));
});

it('rates the latest benchmark and shows the change against earlier runs', function () {
    [$user, $machine] = insightsMachine();
    Report::factory()->for($machine)->create([
        'benchmark' => ['disk_read_mb_s' => 400, 'cpu_single_ms' => 900],
        'created_at' => '2026-10-01 10:00:00',
    ]);
    Report::factory()->for($machine)->create([
        'benchmark' => ['disk_read_mb_s' => 2100, 'net_down_mbps' => 10, 'bogus' => 5, 'memory_mb_s' => 'x'],
        'created_at' => '2026-10-02 10:00:00',
    ]);
    Report::factory()->for($machine)->create(['benchmark' => [], 'created_at' => '2026-10-03 10:00:00']);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->where('benchmark.latest.metrics.0.key', 'disk_read_mb_s')
            ->where('benchmark.latest.metrics.0.rating', 'healthy')
            ->where('benchmark.latest.metrics.1.key', 'net_down_mbps')
            ->where('benchmark.latest.metrics.1.rating', 'poor')
            ->has('benchmark.history', 2)
            ->where('benchmark.history.0.values.0.delta', 1700)
            ->where('benchmark.history.0.values.0.improved', true)
            ->where('benchmark.history.0.values.1.delta', null)
            ->where('benchmark.history.1.values.0.delta', null)
            ->where('benchmark.comparison', null));
});

it('looks past a run that skipped a test when computing the change', function () {
    [$user, $machine] = insightsMachine();
    Report::factory()->for($machine)->create(['benchmark' => ['net_up_mbps' => 20], 'created_at' => '2026-10-01 10:00:00']);
    Report::factory()->for($machine)->create(['benchmark' => ['disk_read_mb_s' => 100], 'created_at' => '2026-10-02 10:00:00']);
    Report::factory()->for($machine)->create(['benchmark' => ['net_up_mbps' => 35], 'created_at' => '2026-10-03 10:00:00']);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->where('benchmark.history.0.values.0.delta', 15)
            ->where('benchmark.latest.metrics.0.rating', 'fair'));
});

it('compares the benchmark before and after fixes when both carry numbers', function () {
    [$user, $machine] = insightsMachine();
    $audit = Report::factory()->for($machine)->create([
        'benchmark' => ['disk_read_mb_s' => 300, 'cpu_single_ms' => 1000, 'net_up_mbps' => 30],
        'created_at' => '2026-10-01 10:00:00',
    ]);
    Report::factory()->for($machine)->create([
        'kind' => 'after-fix',
        'before_report_id' => $audit->id,
        'benchmark' => ['disk_read_mb_s' => 900, 'cpu_single_ms' => 1000, 'net_down_mbps' => 80],
        'created_at' => '2026-10-02 10:00:00',
    ]);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->has('benchmark.comparison', 2)
            ->where('benchmark.comparison.0.key', 'cpu_single_ms')
            ->where('benchmark.comparison.0.improved', null)
            ->where('benchmark.comparison.1.key', 'disk_read_mb_s')
            ->where('benchmark.comparison.1.delta', 600)
            ->where('benchmark.comparison.1.improved', true));
});

it('leaves the comparison out when the audit has no matching numbers', function () {
    [$user, $machine] = insightsMachine();
    $audit = Report::factory()->for($machine)->create(['benchmark' => null, 'created_at' => '2026-10-01 10:00:00']);
    Report::factory()->for($machine)->create([
        'kind' => 'after-fix',
        'before_report_id' => $audit->id,
        'benchmark' => ['disk_read_mb_s' => 900],
        'created_at' => '2026-10-02 10:00:00',
    ]);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page->where('benchmark.comparison', null));
});

it('rates lower-is-better metrics the other way round', function () {
    expect(BenchmarkMetric::CpuSingle->rate(500))->toBe('healthy')
        ->and(BenchmarkMetric::CpuSingle->rate(1200))->toBe('fair')
        ->and(BenchmarkMetric::CpuSingle->rate(3000))->toBe('poor')
        ->and(BenchmarkMetric::DiskRead->rate(300))->toBe('fair')
        ->and(BenchmarkMetric::CpuMulti->improved(-10))->toBeTrue()
        ->and(BenchmarkMetric::CpuMulti->improved(10))->toBeFalse()
        ->and(BenchmarkMetric::Memory->improved(0.0))->toBeNull();
});

it('labels every metric with a unit', function () {
    foreach (BenchmarkMetric::cases() as $metric) {
        expect($metric->label())->not->toBe('')
            ->and($metric->unit())->not->toBe('')
            ->and($metric->healthy())->not->toBe($metric->poor());
    }
});
