<?php

declare(strict_types=1);

use App\Actions\Machines\CompareLatestReports;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

function dcReport(Machine $machine, string $memory, string $at): Report
{
    return Report::factory()->for($machine)->create([
        'markdown' => "# Mac checkup: PC\n\n- Memory: {$memory}\n\n## Things to look at\n",
        'flags' => [],
        'created_at' => $at,
    ]);
}

it('sees a real change in memory and ignores a locale change of the same number', function (string $before, string $after, ?array $expected) {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    dcReport($machine, $before, '2026-10-01');
    dcReport($machine, $after, '2026-11-01');

    expect(app(CompareLatestReports::class)->handle($machine)['memory'])->toBe($expected);
})->with([
    'comma to comma, changed' => ['1,5 GB', '2,5 GB', ['from' => '1,5 GB', 'to' => '2,5 GB']],
    'point to comma, same' => ['1.5 GB', '1,5 GB', null],
    'whole to point, changed' => ['8 GB', '8.5 GB', ['from' => '8 GB', 'to' => '8.5 GB']],
    'not a number' => ['1,5,5 GB', '2,5 GB', null],
]);
