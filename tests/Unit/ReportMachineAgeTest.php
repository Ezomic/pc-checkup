<?php

declare(strict_types=1);

use App\Services\Reports\ReportMachineAge;
use App\Services\Reports\ReportSections;
use Carbon\CarbonImmutable;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-06 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function machineAgeSections(?string $model, ?string $bios): ReportSections
{
    $markdown = "# Checkup\n\n".($model === null ? '' : "- Model: {$model}\n");

    if ($bios !== null) {
        $markdown .= "\n## BIOS and firmware age\n\n```\n{$bios}\n```\n";
    }

    return ReportSections::parse($markdown);
}

it('reads the age of a Mac from its model identifier', function (string $model, ?int $years) {
    expect(ReportMachineAge::modelYears($model))->toBe($years);
})->with([
    'seven years' => ['MacBook Pro (MacBookPro15,4)', 7],
    'six years' => ['Mac mini (Macmini9,1)', 6],
    'unknown identifier' => ['Mac (MacBookPro99,9)', null],
    'no identifier' => ['Contoso Voyager 14', null],
    'unicode' => ['Mac mini (日本語)', null],
]);

it('reads the age of a PC from its firmware date at the year boundary', function (string $line, ?int $years) {
    expect(ReportMachineAge::firmwareYears($line))->toBe($years);
})->with([
    'a day short of seven years' => ['BIOS release date: 2019-11-07 (about 6 full years ago)', 6],
    'exactly seven years' => ['BIOS release date: 2019-11-06 (about 7 full years ago)', 7],
    'ten years' => ['BIOS release date: 2016-03-01 (about 10 full years ago)', 10],
    'today' => ['BIOS release date: 2026-11-06 (about 0 full years ago)', 0],
    'tomorrow cannot be right' => ['BIOS release date: 2026-11-07', null],
    'next year cannot be right' => ['BIOS release date: 2027-06-01', null],
    'a virtual machine' => ['BIOS age: not checked (this is a virtual machine)', null],
    'firmware did not say' => ['BIOS release date: could not check (the firmware did not report one)', null],
    'impossible date' => ['BIOS release date: 2019-13-40', null],
    'before 2000' => ['BIOS release date: 1998-01-01', null],
    'empty' => ['', null],
]);

it('reads nothing without a section', function () {
    expect(ReportMachineAge::firmwareYears(null))->toBeNull()
        ->and(ReportMachineAge::modelYears(null))->toBeNull();
});

it('prefers the Mac model over the firmware date', function () {
    expect(ReportMachineAge::of(machineAgeSections('Mac (Macmini8,1)', 'BIOS release date: 2025-01-01')))->toBe(['years' => 8, 'source' => 'model'])
        ->and(ReportMachineAge::of(machineAgeSections('Contoso Voyager', 'BIOS release date: 2016-03-01')))->toBe(['years' => 10, 'source' => 'firmware'])
        ->and(ReportMachineAge::of(machineAgeSections(null, null)))->toBeNull();
});

it('ignores an age past the sane maximum', function () {
    CarbonImmutable::setTestNow('2070-01-01');

    expect(ReportMachineAge::modelYears('Mac (Macmini9,1)'))->toBeNull()
        ->and(ReportMachineAge::firmwareYears('BIOS release date: 2016-03-01'))->toBeNull();
});
