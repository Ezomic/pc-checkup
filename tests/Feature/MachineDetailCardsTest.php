<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * @param  list<string>  $flags
 * @return list<array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: array<string, mixed>|null}>
 */
function ownerDetails(string $markdown, array $flags = [], string $deviceType = 'laptop'): array
{
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['device_type' => $deviceType]);
    Report::factory()->for($machine)->create(['markdown' => $markdown, 'flags' => $flags]);

    $details = [];

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$details): void {
            $details = $page->toArray()['props']['detailCards'];
        });

    return $details;
}

it('gives the owner the three cards from a real Mac report', function () {
    $details = ownerDetails(macReportFixture(), ['macOS 10.14.6 is old and likely out of security support.']);

    expect(array_column($details, 'id'))->toBe(['age', 'storage', 'battery'])
        ->and($details[0]['rows'][3])->toBe(['label' => 'Security support', 'value' => 'Out of security support', 'tone' => 'bad'])
        ->and($details[1]['rows'][0])->toBe(['label' => 'Free', 'value' => '14 GB (12%)', 'tone' => 'neutral'])
        ->and($details[1]['list']['items'][0])->toBe(['label' => 'Library', 'value' => '38 GB', 'tone' => 'neutral'])
        ->and($details[2]['rows'][1])->toBe(['label' => 'Condition', 'value' => 'Normal', 'tone' => 'good']);
});

it('agrees with the free disk fact tile', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['markdown' => macReportFixture(), 'flags' => []]);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->where('report.facts.free_disk', '12%')
            ->where('detailCards.1.rows.0.value', '14 GB (12%)'));
});

it('gives the owner the cards from a Windows report', function () {
    $details = ownerDetails(windowsLaptopFixture(), ['Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.']);

    expect(array_column($details, 'id'))->toBe(['age', 'storage', 'battery'])
        ->and($details[0]['rows'][2]['value'])->toBe('Out of security support')
        ->and($details[1]['rows'][0]['value'])->toBe('52 GB (22%)');
});

it('sends no cards for a machine without a report', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();

    actingAs($user)->get("/machines/{$machine->id}")->assertInertia(fn ($page) => $page->where('detailCards', []));
});

it('sends only the cards whose data exists', function (string $markdown, array $ids) {
    expect(array_column(ownerDetails($markdown, [], 'desktop'), 'id'))->toBe($ids);
})->with([
    'a report from an old script version' => ["# Mac checkup: Old\n\n- Format: 1\n- OS: macOS\n- Free disk: 30%\n\n## Things to look at\n\n- Nothing flagged automatically.\n", ['storage']],
    'a title and nothing else' => ["# Report\n", []],
    'an empty report' => ['', []],
    'sections that exist but are empty' => ["# Report\n\n## Disk usage\n\n```\n```\n\n## Software update age\n\n```\n```\n", []],
]);

it('reads a report with Windows line endings', function () {
    $lf = ownerDetails(macReportFixture());
    $crlf = ownerDetails(str_replace("\n", "\r\n", macReportFixture()));

    expect($crlf)->toBe($lf);
});

it('is not fooled by a section heading inside a code fence', function () {
    $markdown = str_replace("Volume Name:               Macintosh HD\n", "Volume Name:               Macintosh HD\n## Disk usage\n", macReportFixture());
    $details = ownerDetails($markdown);

    expect($details[1]['rows'][0]['value'])->toBe('14 GB (12%)');
});

it('uses the first of two sections with the same heading', function () {
    $details = ownerDetails(macReportFixture()."\n## Disk usage\n\n```\n/dev/other 1Gi 1Gi 0B 100% /\n```\n");

    expect($details[1]['rows'][1]['value'])->toBe('99 GB of 113 GB');
});

it('reads df output with terabytes and decimals', function () {
    $markdown = str_replace('/dev/disk1s1   113Gi   97Gi   14Gi    88%', '/dev/disk1s1   1.8Ti   1.2Ti   600Gi    67%', macReportFixture());
    $details = ownerDetails($markdown);

    expect($details[1]['rows'][0]['value'])->toBe('600 GB (12%)')
        ->and($details[1]['rows'][1]['value'])->toBe('1.2 TB of 1.8 TB');
});

it('handles a very long report', function () {
    $filler = implode("\n", array_fill(0, 20000, 'filler line'));
    $details = ownerDetails(macReportFixture()."\n## Installed apps\n\n```\n{$filler}\n```\n");

    expect(array_column($details, 'id'))->toBe(['age', 'storage', 'battery']);
});

it('never lets report text become markup or a placeholder value', function () {
    $markdown = str_replace('MacBook Air (MacBookAir7,2)', '<img src=x onerror=alert(1)> (MacBookAir99,9)', macReportFixture());
    $details = ownerDetails($markdown);
    $encoded = (string) json_encode($details);

    expect($details[0]['rows'][0]['value'])->toBe('<img src=x onerror=alert(1)>')
        ->and($encoded)->not->toContain('undefined')->not->toContain('NaN')->not->toContain('null,');
});

it('keeps all of it off the share pages', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => macReportFixture(), 'flags' => ['macOS 10.14.6 is old and likely out of security support.']]);

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $encoded = (string) json_encode($props);

                expect($props)->not->toHaveKey('detailCards')->not->toHaveKey('details')
                    ->and($encoded)->not->toContain('Library/Caches')
                    ->and($encoded)->not->toContain('Solid state')
                    ->and($encoded)->not->toContain('First released')
                    ->and($encoded)->not->toContain('Charge cycles');
            });
    }
});
