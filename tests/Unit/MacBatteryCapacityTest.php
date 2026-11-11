<?php

declare(strict_types=1);

use App\Services\Reports\MacBatteryCapacity;

function batteryIoreg(string ...$lines): string
{
    return implode("\n", array_map(fn (string $line): string => "      {$line}", $lines));
}

function intelBatteryIoreg(int $raw = 5230, int $max = 5230, int $design = 6200): string
{
    return batteryIoreg(
        "\"AppleRawMaxCapacity\" = {$raw}",
        "\"MaxCapacity\" = {$max}",
        '"DesignCycleCount70" = 65535',
        '"CycleCount" = 245',
        "\"DesignCapacity\" = {$design}",
        "\"BatteryData\" = {\"Voltage\"=8100,\"DesignCapacity\"={$design},\"CycleCount\"=245,\"StateOfCharge\"=20480}",
        '"Temperature" = 3011',
    );
}

it('reads an Intel Mac whose capacities are all mAh', function () {
    expect((new MacBatteryCapacity)->percent(intelBatteryIoreg()))->toBe(84);
});

it('reads an Apple silicon Mac whose MaxCapacity is a percentage', function () {
    $text = batteryIoreg('"AppleRawMaxCapacity" = 4310', '"MaxCapacity" = 100', '"NominalChargeCapacity" = 4448', '"DesignCapacity" = 4563', '"CycleCount" = 88');

    expect((new MacBatteryCapacity)->percent($text))->toBe(94);
});

it('rounds to a whole percent', function (int $raw, int $design, int $percent) {
    expect((new MacBatteryCapacity)->percent(intelBatteryIoreg(raw: $raw, design: $design)))->toBe($percent);
})->with([
    'down' => [6011, 7150, 84],
    'up' => [6400, 7150, 90],
    'half rounds up' => [5239, 6200, 85],
]);

it('uses MaxCapacity only when it is clearly mAh', function (string $max, ?int $percent) {
    $text = batteryIoreg("\"MaxCapacity\" = {$max}", '"DesignCapacity" = 6200');

    expect((new MacBatteryCapacity)->percent($text))->toBe($percent);
})->with([
    'mAh' => ['5230', 84],
    'a percentage' => ['88', null],
    'exactly 100 is a percentage' => ['100', null],
    'just above 100 is mAh' => ['101', 2],
]);

it('falls back to MaxCapacity when the raw value is missing or unusable', function (string $raw) {
    $text = batteryIoreg($raw, '"MaxCapacity" = 5230', '"DesignCapacity" = 6200');

    expect((new MacBatteryCapacity)->percent($text))->toBe(84);
})->with([
    'missing' => '"CycleCount" = 245',
    'zero' => '"AppleRawMaxCapacity" = 0',
    'absurd' => '"AppleRawMaxCapacity" = 60000',
    'not a number' => '"AppleRawMaxCapacity" = lots',
]);

it('has no answer for a Mac without a battery', function () {
    $text = batteryIoreg('"MaxCapacity" = 0', '"CycleCount" = 0', '"IOReportLegend" = ({"IOReportChannels"=((7167869599145487988,6460407809,"BatteryCycleCount")),"IOReportGroupName"="Battery"})');

    expect((new MacBatteryCapacity)->percent($text))->toBeNull();
});

it('has no answer without a usable design capacity', function (string $design) {
    $text = batteryIoreg('"AppleRawMaxCapacity" = 5230', '"MaxCapacity" = 5230', $design);

    expect((new MacBatteryCapacity)->percent($text))->toBeNull();
})->with([
    'missing' => '"CycleCount" = 245',
    'zero' => '"DesignCapacity" = 0',
    'only inside BatteryData' => '"BatteryData" = {"DesignCapacity"=6200,"CycleCount"=245}',
    'negative' => '"DesignCapacity" = -6200',
    'Dutch thousands comma' => '"DesignCapacity" = 6,200',
    'Dutch decimal comma' => '"DesignCapacity" = 6200,5',
    'thousands dot' => '"DesignCapacity" = 6.200',
    'huge' => '"DesignCapacity" = 18446744073709551615',
    'words' => '"DesignCapacity" = unknown',
    'empty' => '"DesignCapacity" = ',
]);

it('rejects a result that cannot be a real battery', function (int $raw, int $design, ?int $percent) {
    expect((new MacBatteryCapacity)->percent(intelBatteryIoreg(raw: $raw, max: $raw, design: $design)))->toBe($percent);
})->with([
    'rounds to 0' => [3, 7150, null],
    'just 1' => [72, 7150, 1],
    'exactly 110' => [7865, 7150, 110],
    'just over 110' => [7940, 7150, null],
    'ten times design' => [71500, 7150, null],
    'design far too small' => [6011, 1, null],
]);

it('reads each key by its exact name', function () {
    $text = batteryIoreg('"MaxCapacity" = 7', '"BatteryData" = {"DesignCapacity"=9999}', '"AppleRawMaxCapacity" = 5230', '"DesignCycleCount70" = 65535', '"DesignCapacity" = 6200');

    expect((new MacBatteryCapacity)->percent($text))->toBe(84);
});

it('reads Windows line endings and tree pipes', function () {
    $text = "    | \"AppleRawMaxCapacity\" = 5230\r\n    | \"DesignCapacity\" = 6200\r\n";

    expect((new MacBatteryCapacity)->percent($text))->toBe(84);
});

it('has no answer for nothing or junk', function (?string $text) {
    expect((new MacBatteryCapacity)->percent($text))->toBeNull();
})->with([
    'no section' => null,
    'empty' => '',
    'error text' => 'ioreg: command not found',
    'unicode and control characters' => "\"AppleRawMaxCapacity\" = 5230\u{7}\u{202E}\n\"DesignCapacity\" = \u{663}\u{664}",
    'invalid UTF-8' => "\"AppleRawMaxCapacity\" = \xC3\x28\n\"DesignCapacity\" = 6200",
]);
