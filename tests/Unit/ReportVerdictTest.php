<?php

declare(strict_types=1);

use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportVerdict;
use Carbon\CarbonImmutable;

const VERDICT_UNSUPPORTED = ['macOS 10.14.6 is old and likely out of security support.'];
const VERDICT_BASE = ['Model' => 'MacBook Pro (MacBookPro18,1)', 'macOS' => '14.5 (23F79)', 'Memory' => '16 GB', 'Free disk' => '50%'];
const VERDICT_WINDOWS_DISKS = "## Storage (physical disks)\n\n```\nFriendlyName MediaType BusType SizeGB HealthStatus OperationalStatus\n------------ --------- ------- ------ ------------ -----------------\n%s\n```\n";

beforeEach(fn () => CarbonImmutable::setTestNow('2026-06-15'));
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, string|null>  $header
 */
function verdictReport(array $header = [], ?string $solid = 'Yes', string $extra = ''): string
{
    $lines = ['# Checkup', '', '- Format: 1'];

    foreach ($header as $key => $value) {
        if ($value !== null) {
            $lines[] = "- {$key}: {$value}";
        }
    }

    $markdown = implode("\n", $lines)."\n";

    if ($solid !== null) {
        $markdown .= "\n## Storage (boot volume)\n\n```\n   Solid State:               {$solid}\n```\n";
    }

    return $markdown.$extra;
}

/**
 * @param  list<string>  $flags
 * @return list<array{label: string, value: string, tone: string}>
 */
function verdictRows(string $markdown, array $flags = []): array
{
    return (new ReportVerdict)->rows(ReportSections::parse($markdown), $flags, (new ReportFacts)->from($markdown)['free_disk']);
}

/**
 * @param  array<string, string|null>  $override
 * @param  list<string>  $flags
 * @return list<array{label: string, value: string, tone: string}>
 */
function verdictWith(array $override, ?string $solid = 'Yes', array $flags = []): array
{
    return verdictRows(verdictReport($override + VERDICT_BASE, $solid), $flags);
}

/**
 * @param  list<array{label: string, value: string, tone: string}>  $rows
 * @return array<string, string>
 */
function verdictReasons(array $rows): array
{
    return array_column(array_slice($rows, 1, -1), 'tone', 'label');
}

/**
 * @param  list<array{label: string, value: string, tone: string}>  $rows
 */
function verdictWord(array $rows): string
{
    return $rows[0]['value'];
}

it('keeps a healthy machine and says nothing is wrong', function () {
    $rows = verdictWith([]);

    expect($rows[0])->toBe(['label' => 'Verdict', 'value' => 'Keep', 'tone' => 'good'])
        ->and(verdictReasons($rows))->toBe([])
        ->and(end($rows))->toBe(['label' => 'Good to know', 'value' => 'A rule of thumb for you, not a guarantee. Look at the machine itself before you spend money.', 'tone' => 'neutral']);
});

it('rates age at each threshold', function (string $model, array $expected) {
    expect(verdictReasons(verdictWith(['Model' => $model])))->toBe($expected);
})->with([
    'five years is not old' => ['Mac (MacBookPro18,1)', []],
    'six years is a weak sign' => ['Mac (Macmini9,1)', ['About 6 years old' => 'neutral']],
    'eight years is a weak sign' => ['Mac (Macmini8,1)', ['About 8 years old' => 'neutral']],
    'nine years is a strong sign' => ['Mac (iMac18,1)', ['About 9 years old' => 'bad']],
]);

it('ignores an age it cannot trust', function (string $now, string $model) {
    CarbonImmutable::setTestNow($now);

    expect(verdictReasons(verdictWith(['Model' => $model])))->toBe([]);
})->with([
    'a model released in the future' => ['2010-01-01', 'Mac (Macmini9,1)'],
    'an absurd age' => ['2200-01-01', 'Mac (Macmini9,1)'],
    'a model with no known year' => ['2026-06-15', 'Mac (MacBookAir99,9)'],
    'a Windows model with no identifier' => ['2026-06-15', 'Contoso Voyager 14'],
    'markup in the model' => ['2026-06-15', '<img src=x onerror=alert(1)> (Mac99,9)'],
]);

it('rates the battery at each threshold', function (string $battery, array $expected) {
    $mac = verdictReasons(verdictWith(['Battery' => $battery]));

    expect($mac)->toBe($expected);
})->with([
    'capacity 80 is fine' => ['100 cycles, condition Normal, max capacity 80%', []],
    'capacity 79 is a weak sign' => ['100 cycles, condition Normal, max capacity 79%', ['The battery holds 79% of what it did when new' => 'neutral']],
    'capacity 60 is a weak sign' => ['100 cycles, condition Normal, max capacity 60%', ['The battery holds 60% of what it did when new' => 'neutral']],
    'capacity 59 is a strong sign' => ['100 cycles, condition Normal, max capacity 59%', ['The battery holds only 59% of what it did when new' => 'bad']],
    'capacity 0 is a strong sign' => ['100 cycles, condition Normal, max capacity 0%', ['The battery holds only 0% of what it did when new' => 'bad']],
    'capacity over 100 is unreadable' => ['100 cycles, condition Normal, max capacity 101%', []],
    'cycles 699 is fine' => ['699 cycles, condition Normal, max capacity n/a', []],
    'cycles 700 is a weak sign' => ['700 cycles, condition Normal, max capacity n/a', ['The battery has been charged 700 times' => 'neutral']],
    'cycles 999 is a weak sign' => ['999 cycles, condition Normal, max capacity n/a', ['The battery has been charged 999 times' => 'neutral']],
    'cycles 1000 is a strong sign' => ['1000 cycles, condition Normal, max capacity n/a', ['The battery has been charged 1000 times' => 'bad']],
    'cycles far beyond design are still strong' => ['10000 cycles, condition Normal, max capacity n/a', ['The battery has been charged 10000 times' => 'bad']],
    'absurd cycles are unreadable' => ['10001 cycles, condition Normal, max capacity n/a', []],
    'service recommended' => ['100 cycles, condition Service Recommended, max capacity n/a', ['The battery is worn out' => 'bad']],
    'poor' => ['100 cycles, condition Poor, max capacity n/a', ['The battery is worn out' => 'bad']],
    'replace soon' => ['100 cycles, condition Replace Soon, max capacity n/a', ['The battery is worn out' => 'bad']],
    'replace now' => ['100 cycles, condition Replace Now, max capacity n/a', ['The battery is worn out' => 'bad']],
    'a worn condition beats a good capacity' => ['100 cycles, condition Poor, max capacity 95%', ['The battery is worn out' => 'bad']],
    'a low capacity beats a high cycle count' => ['800 cycles, condition Normal, max capacity 50%', ['The battery holds only 50% of what it did when new' => 'bad']],
    'a Windows battery line' => ['312 cycles, 55% of design capacity', ['The battery holds only 55% of what it did when new' => 'bad']],
    'a Windows battery line that is fine' => ['312 cycles, 85% of design capacity', []],
    'a negative capacity is unreadable' => ['100 cycles, condition Normal, max capacity -5%', []],
    'a negative cycle count is unreadable' => ['-5 cycles, condition n/a, max capacity n/a', []],
]);

it('counts a battery only when something about it is readable', function (string $battery, bool $counts) {
    $base = ['macOS' => '14.5', 'Memory' => '16 GB', 'Battery' => $battery];
    $rows = verdictRows(verdictReport($base, null));

    expect(verdictWord($rows) === 'Not enough data to judge')->toBe(! $counts);
})->with([
    'a normal condition counts' => ['n/a cycles, condition Normal, max capacity n/a', true],
    'a cycle count counts' => ['120 cycles, condition n/a, max capacity n/a', true],
    'a capacity counts' => ['n/a cycles, 90% of design capacity', true],
    'nothing readable does not count' => ['n/a cycles, condition n/a, max capacity n/a', false],
    'a desktop with no battery does not count' => ['none', false],
    'an unknown condition does not count' => ['n/a cycles, condition Mystery, max capacity n/a', false],
    'no battery line does not count' => ['', false],
]);

it('rates memory at each threshold', function (string $memory, array $expected) {
    expect(verdictReasons(verdictWith(['Memory' => $memory])))->toBe($expected);
})->with([
    '8 GB is enough' => ['8 GB', []],
    'just under 8 GB is a weak sign' => ['7.9 GB', ['7.9 GB of memory is on the small side' => 'neutral']],
    '4 GB is a weak sign' => ['4 GB', ['4 GB of memory is on the small side' => 'neutral']],
    'just under 4 GB is a strong sign' => ['3.9 GB', ['Only 3.9 GB of memory' => 'bad']],
    'megabytes are converted' => ['512 MB', ['Only 0.5 GB of memory' => 'bad']],
    'terabytes are converted' => ['1 TB', []],
    'zero is unreadable' => ['0 GB', []],
    'an absurd size is unreadable' => ['99999999 GB', []],
    'unknown is unreadable' => ['unknown', []],
    'not applicable is unreadable' => ['n/a', []],
]);

it('rates free disk space at each threshold', function (string $free, array $expected) {
    expect(verdictReasons(verdictWith(['Free disk' => $free])))->toBe($expected);
})->with([
    '10% is fine' => ['10%', []],
    '9% is a weak sign' => ['9%', ['Only 9% of the drive is free' => 'neutral']],
    '5% is a weak sign' => ['5%', ['Only 5% of the drive is free' => 'neutral']],
    '4% is a strong sign' => ['4%', ['Only 4% of the drive is free' => 'bad']],
    '0% is a strong sign' => ['0%', ['Only 0% of the drive is free' => 'bad']],
    '100% is fine' => ['100%', []],
    'over 100% is unreadable' => ['101%', []],
    'negative is unreadable' => ['-5%', []],
    'not applicable is unreadable' => ['n/a%', []],
    'unknown is unreadable' => ['unknown', []],
]);

it('reads the drive type on a Mac', function (?string $solid, array $expected) {
    expect(verdictReasons(verdictWith([], $solid)))->toBe($expected);
})->with([
    'solid state' => ['Yes', []],
    'spinning' => ['No', ['It still has a spinning hard drive' => 'bad']],
    'unknown' => ['Maybe', []],
    'missing' => [null, []],
]);

it('reads the drive type on Windows', function (string $disks, array $expected) {
    $markdown = verdictReport(VERDICT_BASE, null, sprintf(VERDICT_WINDOWS_DISKS, $disks));

    expect(verdictReasons(verdictRows($markdown)))->toBe($expected);
})->with([
    'solid state' => ['Contoso NVMe 256GB   SSD       NVMe       238 Healthy      OK', []],
    'spinning' => ['Contoso Disk 1TB   HDD       SATA       931 Healthy      OK', ['It still has a spinning hard drive' => 'bad']],
    'both kinds' => ["Contoso NVMe 256GB   SSD       NVMe       238 Healthy      OK\nContoso Disk 1TB   HDD       SATA       931 Healthy      OK", []],
    'unspecified' => ['Contoso Disk 1TB   Unspecified       SATA       931 Healthy      OK', []],
]);

it('tells a Mac and a Windows machine apart only by the data each reports', function () {
    $windows = verdictRows(windowsLaptopFixture(), ['Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.']);
    $desktop = verdictRows(fixtureReport());
    $mac = verdictRows(macReportFixture(), VERDICT_UNSUPPORTED);

    expect(verdictWord($windows))->toBe('Repair')
        ->and(verdictReasons($windows))->toBe(['The operating system no longer gets security updates' => 'bad'])
        ->and(verdictWord($desktop))->toBe('Keep')
        ->and(verdictWord($mac))->toBe('Replace');
});

it('replaces only when the age is strong and the system or two more signs agree', function (array $header, ?string $solid, array $flags, string $expected) {
    expect(verdictWord(verdictWith($header, $solid, $flags)))->toBe($expected);
})->with([
    'strong age and two other strong signs' => [['Model' => 'Mac (iMac18,1)', 'Memory' => '2 GB', 'Free disk' => '3%'], 'Yes', [], 'Replace'],
    'strong age and a strong drive and memory' => [['Model' => 'Mac (iMac18,1)', 'Memory' => '2 GB'], 'No', [], 'Replace'],
    'strong age and one other strong sign' => [['Model' => 'Mac (iMac18,1)', 'Memory' => '2 GB'], 'Yes', [], 'Repair'],
    'strong age and one other strong sign plus weak ones' => [['Model' => 'Mac (iMac18,1)', 'Memory' => '2 GB', 'Free disk' => '7%', 'Battery' => '100 cycles, condition Normal, max capacity 70%'], 'Yes', [], 'Repair'],
    'strong age and an unsupported system' => [['Model' => 'Mac (iMac18,1)'], 'Yes', VERDICT_UNSUPPORTED, 'Replace'],
    'strong age, unsupported system and more' => [['Model' => 'Mac (iMac18,1)', 'Memory' => '2 GB'], 'No', VERDICT_UNSUPPORTED, 'Replace'],
    'weak age and an unsupported system' => [['Model' => 'Mac (Macmini9,1)'], 'Yes', VERDICT_UNSUPPORTED, 'Repair'],
    'unsupported system alone' => [[], 'Yes', VERDICT_UNSUPPORTED, 'Repair'],
    'a young machine with three strong fixable signs' => [['Memory' => '2 GB', 'Free disk' => '3%'], 'No', [], 'Repair'],
    'a young machine with an unsupported system and two strong signs' => [['Memory' => '2 GB', 'Free disk' => '3%'], 'Yes', VERDICT_UNSUPPORTED, 'Repair'],
    'a young machine with four strong signs' => [['Memory' => '2 GB', 'Free disk' => '3%', 'Battery' => '100 cycles, condition Poor, max capacity n/a'], 'No', VERDICT_UNSUPPORTED, 'Repair'],
    'a strong age with only a weak sign' => [['Model' => 'Mac (iMac18,1)', 'Memory' => '6 GB'], 'Yes', [], 'Keep'],
]);

it('never replaces a Windows machine, which has no readable age', function () {
    $disks = sprintf(VERDICT_WINDOWS_DISKS, 'Contoso Disk 1TB   HDD       SATA       931 Healthy      OK');
    $markdown = verdictReport(['Windows' => 'Windows 10 Pro 22H2', 'Memory' => '2 GB', 'Free disk' => '3%', 'Battery' => '1500 cycles, 40% of design capacity'], null, $disks);
    $rows = verdictRows($markdown, ['Windows 10 Pro reached end of support on 14 October 2025.']);

    expect(verdictWord($rows))->toBe('Repair')
        ->and(verdictReasons($rows))->toHaveCount(5);
});

it('repairs when there is a cheap fix to make and keeps otherwise', function (array $header, ?string $solid, string $expected) {
    expect(verdictWord(verdictWith($header, $solid)))->toBe($expected);
})->with([
    'one strong fixable sign' => [['Memory' => '3 GB'], 'Yes', 'Repair'],
    'a spinning drive' => [[], 'No', 'Repair'],
    'two weak fixable signs' => [['Memory' => '6 GB', 'Free disk' => '8%'], 'Yes', 'Repair'],
    'one weak fixable sign' => [['Memory' => '6 GB'], 'Yes', 'Keep'],
    'a strong age that nothing can fix' => [['Model' => 'Mac (iMac18,1)'], 'Yes', 'Keep'],
    'a strong age and one weak fixable sign' => [['Model' => 'Mac (iMac18,1)', 'Free disk' => '8%'], 'Yes', 'Keep'],
    'a strong age and one strong fixable sign' => [['Model' => 'Mac (iMac18,1)', 'Free disk' => '3%'], 'Yes', 'Repair'],
]);

it('colours the verdict', function (array $header, string $tone) {
    expect(verdictWith($header)[0]['tone'])->toBe($tone);
})->with([
    'keep' => [[], 'good'],
    'repair' => [['Memory' => '3 GB'], 'neutral'],
    'replace' => [['Model' => 'Mac (iMac18,1)', 'Memory' => '2 GB', 'Free disk' => '3%'], 'bad'],
]);

it('lists the reasons strongest first, at most five', function () {
    $rows = verdictRows(verdictReport([
        'Model' => 'Mac (iMac18,1)', 'macOS' => '10.14.6', 'Memory' => '2 GB', 'Free disk' => '3%',
        'Battery' => '100 cycles, condition Poor, max capacity n/a',
    ], 'No'), VERDICT_UNSUPPORTED);

    expect(verdictReasons($rows))->toHaveCount(5)
        ->and(array_keys(verdictReasons($rows)))->toBe([
            'The operating system no longer gets security updates',
            'About 9 years old',
            'The battery is worn out',
            'Only 2 GB of memory',
            'It still has a spinning hard drive',
        ])
        ->and($rows[1]['value'])->toBe('No macOS that still gets security updates runs on this Mac, so it is time to replace it.');
});

describe('a Mac that cannot run a supported macOS', function () {
    it('keeps the update advice when a supported macOS fits', function () {
        $rows = verdictRows(verdictReport(['Model' => 'Mac (MacBookPro18,1)', 'macOS' => '10.14.6', 'Memory' => '16 GB', 'Free disk' => '50%']), VERDICT_UNSUPPORTED);

        expect($rows[1]['value'])->toBe('Update it if the machine allows, otherwise it is time to replace it.')
            ->and($rows[0]['value'])->toBe('Repair');
    });

    it('calls the unsupported system unfixable and advises replacing the Mac', function () {
        $rows = verdictRows(verdictReport(['Model' => 'Mac (MacBookAir7,2)', 'macOS' => '10.14.6', 'Memory' => '16 GB', 'Free disk' => '50%']), VERDICT_UNSUPPORTED);

        expect($rows[1]['value'])->toBe('No macOS that still gets security updates runs on this Mac, so it is time to replace it.');
    });

    it('says replace even when the age of the model is not known', function () {
        $rows = verdictRows(verdictReport(['Model' => 'Mac (MacBookPro6,1)', 'macOS' => '10.13.6', 'Memory' => '16 GB', 'Free disk' => '50%']), VERDICT_UNSUPPORTED);

        expect($rows[0])->toBe(['label' => 'Verdict', 'value' => 'Replace', 'tone' => 'bad']);
    });

    it('does not decide anything for a model it has no macOS answer for', function () {
        $rows = verdictRows(verdictReport(['Model' => 'Mac (MacBookAir99,9)', 'macOS' => '10.14.6', 'Memory' => '16 GB', 'Free disk' => '50%']), VERDICT_UNSUPPORTED);

        expect($rows[0]['value'])->toBe('Repair')
            ->and($rows[1]['value'])->toBe('Update it if the machine allows, otherwise it is time to replace it.');
    });
});

it('puts strong reasons before weak ones', function () {
    $rows = verdictWith(['Model' => 'Mac (Macmini9,1)', 'Memory' => '2 GB']);

    expect(array_keys(verdictReasons($rows)))->toBe(['Only 2 GB of memory', 'About 6 years old']);
});

it('gives a one line suggestion for every reason', function () {
    $rows = verdictRows(verdictReport([
        'Model' => 'Mac (iMac18,1)', 'macOS' => '10.14.6', 'Memory' => '2 GB', 'Free disk' => '3%',
        'Battery' => '100 cycles, condition Poor, max capacity n/a',
    ], 'No'), VERDICT_UNSUPPORTED);

    foreach (array_slice($rows, 1, -1) as $row) {
        expect($row['value'])->not->toBe('')->not->toContain("\n");
    }
});

it('does not judge on fewer than three readable inputs', function (array $header, ?string $solid) {
    $rows = verdictRows(verdictReport($header, $solid));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['label' => 'Verdict', 'value' => 'Not enough data to judge', 'tone' => 'neutral'])
        ->and($rows[1]['label'])->toBe('Good to know');
})->with([
    'one input' => [['Memory' => '2 GB'], null],
    'two bad inputs are still just two' => [['Memory' => '2 GB', 'Free disk' => '3%'], null],
    'unreadable values do not count' => [['Memory' => '2 GB', 'Free disk' => 'n/a', 'macOS' => 'unknown'], 'Maybe'],
]);

it('judges once three inputs are readable', function () {
    expect(verdictWord(verdictRows(verdictReport(['Memory' => '2 GB', 'Free disk' => '3%'], 'Yes'))))->toBe('Repair');
});

it('trusts an unsupported system flag even when the system line is unreadable', function () {
    $rows = verdictRows(verdictReport(['macOS' => 'unknown', 'Memory' => '16 GB', 'Free disk' => '50%']), VERDICT_UNSUPPORTED);

    expect(verdictWord($rows))->toBe('Repair')
        ->and(array_keys(verdictReasons($rows)))->toBe(['The operating system no longer gets security updates']);
});

it('counts inputs that are fine as readable', function () {
    expect(verdictWord(verdictRows(verdictReport(['Memory' => '8 GB', 'Free disk' => '50%', 'macOS' => '14.5'], null))))->toBe('Keep');
});

it('shows nothing when no input is readable', function () {
    expect(verdictRows(verdictReport([], null)))->toBe([])
        ->and(verdictRows(''))->toBe([]);
});
