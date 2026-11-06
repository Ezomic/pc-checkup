<?php

declare(strict_types=1);

use App\Models\Report;
use App\Services\Reports\ReportNumbers;

function numbersOf(string $markdown, ?array $benchmark = null): array
{
    $report = new Report(['markdown' => $markdown, 'benchmark' => $benchmark]);

    return app(ReportNumbers::class)->from($report);
}

it('reads nothing from a report without facts', function () {
    expect(numbersOf('just text'))->toBe([])
        ->and(numbersOf("# Windows checkup: PC\n\n## Things to look at\n"))->toBe([]);
});

it('reads free disk and memory from the header', function () {
    expect(numbersOf("# Mac checkup: Mac\n\n- Free disk: 41%\n- Memory: 16 GB\n"))->toBe(['free_disk' => 41, 'memory' => 16.0]);
});

it('reads a decimal comma in the memory', function () {
    expect(numbersOf("# Mac checkup: Mac\n\n- Memory: 7,5 GB\n"))->toBe(['memory' => 7.5]);
});

it('ignores facts that are not in a readable form', function (string $line) {
    expect(numbersOf("# Mac checkup: Mac\n\n{$line}\n"))->toBe([]);
})->with([
    'unknown disk' => '- Free disk: n/a',
    'disk in gigabytes' => '- Free disk: 120 GB',
    'memory unit missing' => '- Memory: lots',
    'memory with a minus' => '- Memory: -8 GB',
    'empty' => '- Memory: ',
]);

it('reads the battery cycles and the condition of a Mac', function () {
    expect(numbersOf("# Mac checkup: Mac\n\n- Battery: 312 cycles, condition Service Recommended, max capacity 80%\n"))
        ->toBe(['battery_cycles' => 312, 'battery_condition' => 'service_recommended']);
});

it('reads the cycles of a Windows laptop, which states no condition', function () {
    expect(numbersOf("# Windows checkup: PC\n\n- Battery: 120 cycles, 91% of design capacity\n"))->toBe(['battery_cycles' => 120]);
});

it('drops a battery value that is unknown', function (string $line) {
    expect(numbersOf("# Mac checkup: Mac\n\n{$line}\n"))->toBe([]);
})->with([
    'no cycles and no condition' => '- Battery: n/a cycles, condition n/a, max capacity n/a',
    'a condition nobody knows' => '- Battery: n/a cycles, condition Hovering, max capacity 80%',
    'not a battery line' => '- Battery: charging',
]);

it('counts the programs that start with Windows', function () {
    expect(numbersOf("# Windows checkup: PC\n\n## Startup programs\n\nCount: 12\n"))->toBe(['startup' => 12]);
});

it('counts the login items of a Mac, zero included', function () {
    expect(numbersOf("# Mac checkup: Mac\n\n## Login items\n\nDropbox, Spotify\n"))->toBe(['startup' => 2])
        ->and(numbersOf("# Mac checkup: Mac\n\n## Login items\n\n\n"))->toBe(['startup' => 0]);
});

it('does not compare the background items of a Mac as if they were startup programs', function () {
    $markdown = "# Mac checkup: Mac\n\n## Startup items: LaunchAgents and LaunchDaemons\n\n/Library/LaunchAgents/com.dropbox.plist\n";

    expect(numbersOf($markdown))->not->toHaveKey('startup');
});

it('reads the disk speed from the benchmark only when it is a positive number', function () {
    expect(numbersOf('x', ['disk_read_mb_s' => 512.4, 'disk_write_mb_s' => 90]))->toBe(['disk_read' => 512.4, 'disk_write' => 90.0])
        ->and(numbersOf('x', ['disk_read_mb_s' => 0, 'disk_write_mb_s' => -5]))->toBe([])
        ->and(numbersOf('x', ['cpu_single_ms' => 400]))->toBe([])
        ->and(numbersOf('x', null))->toBe([]);
});
