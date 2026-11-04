<?php

declare(strict_types=1);

use App\Services\Reports\ReportFacts;

function factsFor(string $disk, string $memory = '16 GB', string $cpu = 'Ryzen 5'): array
{
    return (new ReportFacts)->from("# Checkup\n\n- Free disk: {$disk}\n- Memory: {$memory}\n- Chip/CPU: {$cpu}\n\n## Things to look at\n");
}

it('keeps readable values', function () {
    expect(factsFor('12%'))->toBe(['free_disk' => '12%', 'memory' => '16 GB', 'cpu' => 'Ryzen 5']);
});

it('treats values the scan could not read as missing', function (string $value) {
    expect(factsFor($value, $value, $value))->toBe(['free_disk' => null, 'memory' => null, 'cpu' => null]);
})->with(['n/a', 'n/a%', 'N/A%', 'unknown', 'None', '-']);

it('keeps a value that only contains n/a', function () {
    expect(factsFor('n/a% of 100 GB')['free_disk'])->toBe('n/a% of 100 GB');
});

it('has nothing when the lines are missing', function () {
    expect((new ReportFacts)->from("# Checkup\n\n## Things to look at\n"))->toBe(['free_disk' => null, 'memory' => null, 'cpu' => null]);
});
