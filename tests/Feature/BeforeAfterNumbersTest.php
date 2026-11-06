<?php

declare(strict_types=1);

use App\Actions\Machines\BuildBeforeAfter;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

function numbersReport(Machine $machine, string $at, string $header, string $startup, array $benchmark = []): Report
{
    return Report::factory()->for($machine)->create([
        'markdown' => "# Windows checkup: PC\n\n{$header}\n## Startup programs\n\nCount: {$startup}\n",
        'flags' => [],
        'benchmark' => $benchmark,
        'created_at' => $at,
    ]);
}

it('words every kind of number and says which way it moved', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    $before = numbersReport($machine, '2026-10-01 10:00:00', "- Free disk: 40%\n- Memory: 7,5 GB\n- Battery: 100 cycles, condition Normal, max capacity 90%\n", '10', ['disk_write_mb_s' => 300]);
    $after = numbersReport($machine, '2026-10-02 10:00:00', "- Free disk: 30%\n- Memory: 7.5 GB\n- Battery: 140 cycles, condition Service Recommended, max capacity 80%\n", '25', ['disk_write_mb_s' => 200]);

    $rows = collect(app(BuildBeforeAfter::class)->handle($before, $after, true)['numbers'])->keyBy('key')->all();

    expect(array_keys($rows))->toBe(['free_disk', 'startup', 'battery_cycles', 'battery_condition', 'disk_write'])
        ->and($rows['free_disk'])->toBe(['key' => 'free_disk', 'from' => '40%', 'to' => '30%', 'improved' => false])
        ->and($rows['startup']['improved'])->toBeFalse()
        ->and($rows['battery_cycles']['improved'])->toBeNull()
        ->and($rows['battery_condition'])->toBe(['key' => 'battery_condition', 'from' => 'normal', 'to' => 'service_recommended', 'improved' => false])
        ->and($rows['disk_write']['to'])->toBe('200 MB/s');
});

it('words a memory upgrade with its decimals and treats equal-ranked conditions as neither better nor worse', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    $before = numbersReport($machine, '2026-10-01 10:00:00', "- Memory: 7,5 GB\n- Battery: 1 cycles, condition Service Recommended, max capacity 90%\n", '1');
    $after = numbersReport($machine, '2026-10-02 10:00:00', "- Memory: 16 GB\n- Battery: 1 cycles, condition Replace Soon, max capacity 90%\n", '1');

    $rows = collect(app(BuildBeforeAfter::class)->handle($before, $after, false)['numbers'])->keyBy('key')->all();

    expect($rows['memory'])->toBe(['key' => 'memory', 'from' => '7.5 GB', 'to' => '16 GB', 'improved' => true])
        ->and($rows['battery_condition']['improved'])->toBeNull();
});

it('calls a battery that went back to normal an improvement', function () {
    $machine = Machine::factory()->for(User::factory()->create())->create();
    $before = numbersReport($machine, '2026-10-01 10:00:00', "- Battery: 1 cycles, condition Poor, max capacity 90%\n", '1');
    $after = numbersReport($machine, '2026-10-02 10:00:00', "- Battery: 1 cycles, condition Normal, max capacity 90%\n", '1');

    expect(app(BuildBeforeAfter::class)->handle($before, $after, false)['numbers'][0]['improved'])->toBeTrue();
});
