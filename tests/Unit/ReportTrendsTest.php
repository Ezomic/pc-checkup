<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportTrends;
use Carbon\CarbonImmutable;

/**
 * @param  list<string>  $markdowns
 * @return list<array{at: CarbonImmutable, markdown: string}>
 */
function scansEvery(int $days, array $markdowns): array
{
    $start = CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC');

    return array_map(fn (string $markdown, int $index): array => ['at' => $start->addDays($index * $days), 'markdown' => $markdown], $markdowns, array_keys($markdowns));
}

/**
 * @param  list<array{at: CarbonImmutable, markdown: string}>  $scans
 * @return array<string, array{id: string, title: string, points: list<array{at: string, value: float}>, latest: string, change: string, sentence: string|null, tone: string}>
 */
function trendsById(array $scans): array
{
    $trends = [];

    foreach ((new ReportTrends(new ReportDetails(new ReportFacts), new ReportFacts))->fromScans($scans) as $trend) {
        $trends[$trend['id']] = $trend;
    }

    return $trends;
}

/**
 * @param  list<float|null>  $values
 * @return list<string>
 */
function diskReports(array $values): array
{
    return array_map(fn (?float $free): string => trendMarkdown(freeGb: $free), $values);
}

it('follows a falling disk and says roughly when it fills', function () {
    $trend = trendsById(scansEvery(30, diskReports([100, 90, 80, 70, 60, 50])))['disk'];

    expect($trend['title'])->toBe('Free disk space')
        ->and($trend['points'])->toHaveCount(6)
        ->and($trend['points'][0])->toBe(['at' => '2026-05-01T10:00:00Z', 'value' => 100.0, 'label' => '100 GB'])
        ->and($trend['latest'])->toBe('50 GB')
        ->and($trend['change'])->toBe('-50 GB')
        ->and($trend['sentence'])->toBe('At this pace the disk is full in about 5 months.')
        ->and($trend['tone'])->toBe('neutral');
});

it('turns bad when the disk fills within three months', function () {
    $trend = trendsById(scansEvery(30, diskReports([30, 25, 20, 15, 10, 5])))['disk'];

    expect($trend['sentence'])->toBe('At this pace the disk is full in about 1 month.')
        ->and($trend['tone'])->toBe('bad');
});

it('is bad for about three months and neutral for about four', function () {
    $three = trendsById(scansEvery(30, diskReports([182, 152, 122, 92])))['disk'];
    $four = trendsById(scansEvery(30, diskReports([212, 182, 152, 122])))['disk'];

    expect($three['sentence'])->toBe('At this pace the disk is full in about 3 months.')
        ->and($three['tone'])->toBe('bad')
        ->and($four['sentence'])->toBe('At this pace the disk is full in about 4 months.')
        ->and($four['tone'])->toBe('neutral');
});

it('says less than a month when it is nearly full', function () {
    $trend = trendsById(scansEvery(10, diskReports([6, 4, 2])))['disk'];

    expect($trend['sentence'])->toBe('At this pace the disk is full in less than a month.')
        ->and($trend['tone'])->toBe('bad');
});

it('caps a slow decline at more than two years', function () {
    $trend = trendsById(scansEvery(30, diskReports([400, 399, 398, 397])))['disk'];

    expect($trend['sentence'])->toBe('At this pace the disk is full in more than 2 years.')
        ->and($trend['tone'])->toBe('neutral');
});

it('notes free space going up', function () {
    $trend = trendsById(scansEvery(30, diskReports([50, 60, 80, 100])))['disk'];

    expect($trend['sentence'])->toBe('Free space is going up.')
        ->and($trend['change'])->toBe('+50 GB')
        ->and($trend['tone'])->toBe('neutral');
});

it('says nothing about a flat or noisy disk but still shows the points', function (array $values) {
    $trend = trendsById(scansEvery(30, diskReports($values)))['disk'];

    expect($trend['sentence'])->toBeNull()->and($trend['points'])->toHaveCount(count($values));
})->with([
    'identical values' => [[80, 80, 80, 80]],
    'a wobble that is basically flat' => [[80, 80.2, 79.9, 80.1]],
    'a poor fit' => [[100, 20, 90, 30, 80]],
]);

it('reports no change for identical values', function () {
    $trend = trendsById(scansEvery(30, diskReports([80, 80, 80])))['disk'];

    expect($trend['change'])->toBe('No change')->and($trend['latest'])->toBe('80 GB');
});

it('shows terabytes for a big drive', function () {
    $scans = scansEvery(30, array_map(fn (float $free): string => str_replace('500Gi', '4000Gi', trendMarkdown(freeGb: $free)), [2000, 1800, 1500]));

    expect(trendsById($scans)['disk']['latest'])->toBe('1.5 TB');
});

it('needs three points', function () {
    expect(trendsById(scansEvery(30, diskReports([100, 90]))))->toBe([])
        ->and(trendsById([]))->toBe([]);
});

it('needs fourteen days', function (int $days, bool $shown) {
    $scans = scansEvery(1, diskReports([100, 90, 80]));
    $scans[2]['at'] = $scans[0]['at']->addDays($days);
    $scans[1]['at'] = $scans[0]['at']->addDays(min(1, $days));

    expect(isset(trendsById($scans)['disk']))->toBe($shown);
})->with([
    'three scans in one day' => [0, false],
    'thirteen days' => [13, false],
    'exactly fourteen days' => [14, true],
]);

it('skips a scan with no readable value for that metric only', function () {
    $markdowns = [
        trendMarkdown(freeGb: 100, cycles: 100),
        trendMarkdown(freeGb: 90),
        trendMarkdown(cycles: 110),
        trendMarkdown(freeGb: 70, cycles: 120),
        trendMarkdown(freeGb: 60, cycles: 130),
        trendMarkdown(freeGb: 50, cycles: 140),
    ];
    $trends = trendsById(scansEvery(30, $markdowns));

    expect($trends['disk']['points'])->toHaveCount(5)
        ->and($trends['battery']['points'])->toHaveCount(5);
});

it('falls back to percent when the sizes are not readable', function () {
    $scans = scansEvery(30, array_map(fn (int $percent): string => trendMarkdown(freePercent: $percent), [40, 36, 31, 28]));
    $trend = trendsById($scans)['disk'];

    expect($trend['latest'])->toBe('28%')
        ->and($trend['change'])->toBe('-12 points')
        ->and($trend['sentence'])->toBe('At this pace the disk is full in about 7 months.')
        ->and($trend['points'][0]['value'])->toBe(40.0);
});

it('prefers gigabytes over percent when both qualify', function () {
    $markdowns = array_map(fn (float $free): string => trendMarkdown(freeGb: $free, freePercent: 20), [100, 90, 80]);

    expect(trendsById(scansEvery(30, $markdowns))['disk']['latest'])->toBe('80 GB');
});

it('uses percent when too few scans have sizes', function () {
    $markdowns = [trendMarkdown(freeGb: 100, freePercent: 20), trendMarkdown(freePercent: 19), trendMarkdown(freePercent: 18), trendMarkdown(freeGb: 80, freePercent: 17)];

    expect(trendsById(scansEvery(30, $markdowns))['disk']['latest'])->toBe('17%');
});

it('ignores a corrupt disk line', function (string $replacement) {
    $markdowns = array_map(fn (float $free): string => str_replace('500Gi', $replacement, trendMarkdown(freeGb: $free, freePercent: 20)), [100, 90, 80]);
    $trend = trendsById(scansEvery(30, $markdowns))['disk'];

    expect($trend['latest'])->toBe('20%');
})->with([
    'a negative size' => ['-500Gi'],
    'a size beyond a petabyte' => ['99999999999Pi'],
    'free space bigger than the disk' => ['50Gi'],
    'a zero size' => ['0Gi'],
]);

it('ignores a free percent above one hundred', function () {
    $scans = scansEvery(30, array_map(fn (int $percent): string => trendMarkdown(freePercent: $percent), [140, 130, 120]));

    expect(trendsById($scans))->toBe([]);
});

it('counts battery cycles gained per month', function () {
    $trend = trendsById(scansEvery(30, array_map(fn (int $cycles): string => trendMarkdown(cycles: $cycles), [100, 113, 127, 140])))['battery'];
    expect($trend['title'])->toBe('Battery charge cycles')
        ->and($trend['latest'])->toBe('140 cycles')
        ->and($trend['change'])->toBe('+40 cycles')
        ->and($trend['sentence'])->toBe('The battery gained 40 cycles in 3 months, about 13 a month.')
        ->and($trend['tone'])->toBe('neutral');
});

it('describes a short battery span in days and a slow one as under one a month', function () {
    $short = trendsById(scansEvery(10, array_map(fn (int $cycles): string => trendMarkdown(cycles: $cycles), [100, 101, 102])))['battery'];
    $slow = trendsById(scansEvery(60, array_map(fn (int $cycles): string => trendMarkdown(cycles: $cycles), [100, 100, 101])))['battery'];

    expect($short['sentence'])->toBe('The battery gained 2 cycles in 20 days, about 3 a month.')
        ->and($slow['sentence'])->toBe('The battery gained 1 cycle in 4 months, less than 1 a month.');
});

it('says so when the battery was not cycled at all', function () {
    $trend = trendsById(scansEvery(30, array_map(fn (int $cycles): string => trendMarkdown(cycles: $cycles), [200, 200, 200])))['battery'];

    expect($trend['change'])->toBe('No change')
        ->and($trend['sentence'])->toBe('No new charge cycles in 2 months.');
});

it('restarts the battery series after the cycles drop', function () {
    $markdowns = array_map(fn (int $cycles): string => trendMarkdown(cycles: $cycles), [100, 200, 300, 10, 30, 60]);
    $trend = trendsById(scansEvery(10, $markdowns))['battery'];

    expect(array_column($trend['points'], 'value'))->toBe([10.0, 30.0, 60.0])
        ->and($trend['change'])->toBe('+50 cycles');
});

it('hides a battery series that is too short after a drop', function () {
    $markdowns = array_map(fn (int $cycles): string => trendMarkdown(cycles: $cycles), [100, 200, 300, 10, 30]);

    expect(trendsById(scansEvery(30, $markdowns)))->toBe([]);
});

it('ignores an unreadable or absurd cycle count', function () {
    $markdowns = [
        trendMarkdown(cycles: 100),
        str_replace('100 cycles', '999999999 cycles', trendMarkdown(cycles: 100)),
        str_replace('100 cycles', '-5 cycles', trendMarkdown(cycles: 100)),
        str_replace('100 cycles', '99999 cycles', trendMarkdown(cycles: 100)),
        trendMarkdown(cycles: 110),
        trendMarkdown(cycles: 120),
    ];
    $trend = trendsById(scansEvery(30, $markdowns))['battery'];

    expect(array_column($trend['points'], 'value'))->toBe([100.0, 110.0, 120.0]);
});

it('follows free memory on a Mac', function () {
    $falling = trendsById(scansEvery(30, array_map(fn (int $free): string => trendMarkdown(memoryFree: $free), [60, 50, 40])))['memory'];
    $rising = trendsById(scansEvery(30, array_map(fn (int $free): string => trendMarkdown(memoryFree: $free), [40, 50, 60])))['memory'];
    $steady = trendsById(scansEvery(30, array_map(fn (int $free): string => trendMarkdown(memoryFree: $free), [50, 52, 51])))['memory'];

    expect($falling['title'])->toBe('Free memory')
        ->and($falling['latest'])->toBe('40%')
        ->and($falling['change'])->toBe('-20 points')
        ->and($falling['sentence'])->toBe('Free memory is going down.')
        ->and($rising['sentence'])->toBe('Free memory is going up.')
        ->and($steady['sentence'])->toBe('Free memory has stayed about the same.');
});

it('has no memory trend when the report has no readable number', function () {
    $markdowns = array_map(fn (int $free): string => str_replace("percentage: {$free}%", 'percentage: unknown', trendMarkdown(memoryFree: $free)), [60, 50, 40]);

    expect(trendsById(scansEvery(30, $markdowns)))->not->toHaveKey('memory');
});

it('orders the trends disk, battery, memory', function () {
    $markdowns = array_map(fn (int $i): string => trendMarkdown(freeGb: 100 - $i, cycles: 100 + $i, memoryFree: 50 + $i), [0, 1, 2]);

    expect(array_keys(trendsById(scansEvery(30, $markdowns))))->toBe(['disk', 'battery', 'memory']);
});

it('never prints a placeholder number', function () {
    $markdowns = array_map(fn (int $i): string => trendMarkdown(freeGb: 80, cycles: 100, freePercent: 20, memoryFree: 50), [0, 1, 2]);
    $encoded = (string) json_encode((new ReportTrends(new ReportDetails(new ReportFacts), new ReportFacts))->fromScans(scansEvery(30, $markdowns)));

    expect($encoded)->not->toContain('NaN')->not->toContain('INF')->not->toContain('undefined');
});
