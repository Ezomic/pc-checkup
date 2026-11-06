<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportUpgrade;

const UPGRADE_FACTS = [
    'Firmware' => 'UEFI',
    'Secure Boot' => 'on',
    'TPM' => 'present',
    'TPM version' => '2.0',
    'TPM ready' => 'yes',
    'CPU' => 'Intel(R) Core(TM) i5-8250U CPU @ 1.60GHz',
    'CPU maker' => 'GenuineIntel',
    'CPU family' => 'Intel64 Family 6 Model 142 Stepping 10',
    'Memory' => '8 GB',
    'System disk' => '256 GB',
    'Memory slots' => '2 total, 1 used',
    'Memory form' => 'socketed',
    'Memory maximum' => '32 GB',
];

/**
 * @param  array<string, string|null>  $facts  A null leaves the line out.
 */
function upgradeReport(array $facts = [], string $windows = 'Windows 10 Pro 22H2 (build 19045.5000)', bool $section = true): string
{
    $lines = ['# Windows checkup: TEST', '', '- Format: 1', "- Windows: {$windows}", ''];

    if ($section) {
        $lines[] = '## Windows 11 readiness';
        $lines[] = '';
        $lines[] = '```';

        foreach ($facts + UPGRADE_FACTS as $key => $value) {
            if ($value !== null) {
                $lines[] = "{$key}: {$value}";
            }
        }

        $lines[] = '```';
    }

    return implode("\n", $lines)."\n";
}

/**
 * @param  array<string, string|null>  $facts
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: mixed}|null
 */
function upgradeCard(array $facts = [], string $windows = 'Windows 10 Pro 22H2 (build 19045.5000)'): ?array
{
    return (new ReportUpgrade)->card(ReportSections::parse(upgradeReport($facts, $windows)));
}

/**
 * @param  array<string, string|null>  $facts
 */
function upgradeVerdict(array $facts = []): string
{
    return upgradeCard($facts)['rows'][0]['value'];
}

/**
 * @param  array<string, string|null>  $facts
 * @return list<string>
 */
function upgradeLabels(array $facts = []): array
{
    return array_column(array_slice(upgradeCard($facts)['rows'], 1, -1), 'label');
}

it('says yes when everything checks out, and says the final word is Microsoft\'s', function () {
    $card = upgradeCard();

    expect($card['id'])->toBe('upgrade')
        ->and($card['title'])->toBe('Can this PC run Windows 11?')
        ->and($card['rows'][0])->toBe(['label' => 'Verdict', 'value' => 'Yes', 'tone' => 'good'])
        ->and(end($card['rows'])['label'])->toBe('Good to know')
        ->and(end($card['rows'])['value'])->toContain('PC Health Check')->toContain('final word');
});

it('shows the card only for Windows 10', function (string $windows, bool $shown) {
    expect(upgradeCard([], $windows) !== null)->toBe($shown);
})->with([
    'Windows 10 by build' => ['Windows 10 Pro 22H2 (build 19045.5000)', true],
    'Windows 10 on the first build' => ['Windows 10 Home 1507 (build 10240.1)', true],
    'Windows 11 by build' => ['Windows 11 Pro 24H2 (build 26100.1)', false],
    'the first Windows 11 build' => ['Windows 11 Home 21H2 (build 22000.1)', false],
    'Windows 11 captioned as Windows 10' => ['Windows 10 Pro 22H2 (build 22621.1)', false],
    'Windows Server 2019' => ['Windows Server 2019 Standard (build 17763.1)', false],
    'Windows 8.1' => ['Windows 8.1 Pro (build 9600.1)', false],
    'a caption without a build' => ['Windows 10 Pro 22H2', true],
    'Windows 11 without a build' => ['Windows 11 Pro 24H2', false],
    'nothing readable' => ['n/a', false],
]);

it('shows nothing when the report has no readiness section', function () {
    expect((new ReportUpgrade)->card(ReportSections::parse(upgradeReport([], section: false))))->toBeNull();
});

it('rates every verdict', function (array $facts, string $verdict, string $tone) {
    expect(upgradeCard($facts)['rows'][0])->toBe(['label' => 'Verdict', 'value' => $verdict, 'tone' => $tone]);
})->with([
    'a modern PC' => [[], 'Yes', 'good'],
    'an AMD Ryzen 2000 laptop' => [['CPU' => 'AMD Ryzen 5 2500U with Radeon Vega Mobile Gfx', 'CPU maker' => 'AuthenticAMD'], 'Yes', 'good'],
    'TPM not readable, rest fine' => [['TPM' => 'could not check', 'TPM version' => 'could not check', 'TPM ready' => 'could not check'], 'Probably yes', 'good'],
    'secure boot not readable, rest fine' => [['Secure Boot' => 'could not check'], 'Probably yes', 'good'],
    'system disk not readable' => [['System disk' => 'could not check'], 'Probably yes', 'good'],
    'memory not readable' => [['Memory' => 'could not check'], 'Probably yes', 'good'],
    'TPM present but its version unreadable' => [['TPM version' => 'could not check'], 'Probably yes', 'good'],
    'TPM and secure boot both unreadable' => [['Secure Boot' => 'could not check', 'TPM' => 'could not check', 'TPM version' => 'could not check'], 'Cannot tell', 'neutral'],
    'a Pentium' => [['CPU' => 'Intel(R) Pentium(R) Gold G5400 CPU @ 3.70GHz'], 'Cannot tell', 'neutral'],
    'garbage CPU name' => [['CPU' => "\u{0007}\u{1F4A9} ??? 9000"], 'Cannot tell', 'neutral'],
    'Intel 7th generation' => [['CPU' => 'Intel(R) Core(TM) i7-7700HQ CPU @ 2.80GHz'], 'No', 'bad'],
    'TPM 1.2' => [['TPM version' => '1.2'], 'No', 'bad'],
    'old CPU beats a fixable problem' => [['CPU' => 'Intel(R) Core(TM) i5-6200U', 'Secure Boot' => 'off'], 'No', 'bad'],
    'secure boot off' => [['Secure Boot' => 'off'], 'Not as it is', 'neutral'],
    'secure boot off beats an unknown CPU' => [['Secure Boot' => 'off', 'CPU' => 'Intel(R) Celeron(R) N4020'], 'Not as it is', 'neutral'],
    'legacy BIOS' => [['Firmware' => 'BIOS (legacy)', 'Secure Boot' => 'not supported (legacy BIOS)'], 'Not as it is', 'neutral'],
    'legacy BIOS seen only through secure boot' => [['Firmware' => 'could not check', 'Secure Boot' => 'not supported (legacy BIOS)'], 'Not as it is', 'neutral'],
    'TPM switched off' => [['TPM ready' => 'no'], 'Not as it is', 'neutral'],
    'no TPM on a recent CPU' => [['TPM' => 'not found', 'TPM version' => 'could not check', 'TPM ready' => 'could not check'], 'Not as it is', 'neutral'],
    'no TPM and an unknown CPU' => [['TPM' => 'not found', 'TPM version' => 'could not check', 'CPU' => 'Intel(R) Celeron(R) N4020'], 'Cannot tell', 'neutral'],
    'too little memory' => [['Memory' => '2 GB'], 'Not as it is', 'neutral'],
    'a drive that is too small' => [['System disk' => '32 GB'], 'Not as it is', 'neutral'],
]);

it('names the fixable reasons with what to do', function () {
    $rows = upgradeCard(['Secure Boot' => 'off', 'Memory' => '2 GB', 'System disk' => '32 GB', 'TPM ready' => 'no'])['rows'];

    expect(array_column($rows, 'label'))->toBe([
        'Verdict',
        'The security chip (TPM) is switched off',
        'Secure Boot is off',
        'Only 2 GB of memory',
        'The system drive is only 32 GB',
        'Upgrade options',
        'Good to know',
    ])
        ->and($rows[2]['value'])->toContain('BIOS')
        ->and($rows[3]['value'])->toContain('Add memory')
        ->and($rows[4]['value'])->toContain('bigger drive');
});

it('explains why it said no', function () {
    $rows = upgradeCard(['CPU' => 'Intel(R) Core(TM) i5-6200U CPU @ 2.30GHz', 'TPM version' => '1.2'])['rows'];

    expect(array_column($rows, 'label'))->toContain('The processor is too old', 'The security chip (TPM) is too old')
        ->and($rows[1]['tone'])->toBe('bad')
        ->and($rows[1]['value'])->toContain('8th generation')->toContain('2000 series');
});

it('explains a processor it cannot judge and a security check it could not read', function () {
    expect(upgradeLabels(['CPU' => 'Intel(R) Atom(TM) x5-Z8350', 'TPM' => 'could not check', 'TPM version' => 'could not check']))
        ->toBe(['The processor could not be judged', 'Part of the security check could not be read', 'Upgrade options']);
});

it('suggests the BIOS for a missing TPM on a recent CPU', function () {
    $rows = upgradeCard(['TPM' => 'not found', 'TPM version' => 'could not check'])['rows'];

    expect($rows[1]['label'])->toBe('No security chip (TPM) was found')
        ->and($rows[1]['value'])->toContain('PTT')->toContain('fTPM');
});

it('shows the upgrade options only when the slots are readable and the memory is not soldered', function (array $facts, ?string $expected) {
    $rows = upgradeCard($facts)['rows'];
    $row = collect($rows)->firstWhere('label', 'Upgrade options');

    expect($row['value'] ?? null)->toBe($expected);
})->with([
    'one of two slots free' => [[], 'RAM can be expanded: 1 of 2 slots free, up to 32 GB'],
    'three of four free' => [['Memory slots' => '4 total, 1 used', 'Memory maximum' => '64 GB'], 'RAM can be expanded: 3 of 4 slots free, up to 64 GB'],
    'a single free slot' => [['Memory slots' => '1 total, 0 used'], 'RAM can be expanded: 1 of 1 slot free, up to 32 GB'],
    'free slots, maximum unknown' => [['Memory maximum' => 'could not check'], 'RAM can be expanded: 1 of 2 slots free'],
    'free slots, maximum not above what is installed' => [['Memory maximum' => '8 GB'], 'RAM can be expanded: 1 of 2 slots free'],
    'all slots used but bigger modules fit' => [['Memory slots' => '2 total, 2 used'], 'RAM slots: all 2 in use, bigger modules would fit, up to 32 GB'],
    'all slots used and nothing bigger fits' => [['Memory slots' => '2 total, 2 used', 'Memory maximum' => '8 GB'], null],
    'all slots used, maximum unknown' => [['Memory slots' => '2 total, 2 used', 'Memory maximum' => 'could not check'], null],
    'soldered memory' => [['Memory form' => 'soldered or unknown'], null],
    'zero slots' => [['Memory slots' => '0 total, 0 used'], null],
    'more used than there are slots' => [['Memory slots' => '2 total, 3 used'], null],
    'an absurd number of slots' => [['Memory slots' => '9999 total, 1 used'], null],
    'slots not readable' => [['Memory slots' => 'could not check'], null],
    'slots garbled' => [['Memory slots' => 'two of four'], null],
    'a negative-looking count' => [['Memory slots' => '-2 total, 1 used'], null],
    'a maximum that makes no sense' => [['Memory maximum' => '99999999 GB'], 'RAM can be expanded: 1 of 2 slots free'],
    'unknown installed memory but a known maximum' => [['Memory' => 'could not check'], 'RAM can be expanded: 1 of 2 slots free, up to 32 GB'],
]);

it('treats missing lines as unknown rather than as a problem', function () {
    $facts = array_fill_keys(array_keys(UPGRADE_FACTS), null);
    $rows = upgradeCard($facts)['rows'];

    expect($rows[0]['value'])->toBe('Cannot tell')
        ->and(array_column($rows, 'label'))->not->toContain('Secure Boot is off');
});

it('ignores unreadable numbers', function (string $memory, string $disk) {
    expect(upgradeVerdict(['Memory' => $memory, 'System disk' => $disk]))->toBe('Probably yes');
})->with([
    'zero' => ['0 GB', '0 GB'],
    'negative' => ['-8 GB', '-256 GB'],
    'huge' => ['99999999 GB', '99999999 GB'],
    'words' => ['eight gigabytes', 'a lot'],
    'a decimal comma' => ['7,5 GB', '238,5 GB'],
]);

it('keeps the first of a repeated line and ignores lines that are not facts', function () {
    $markdown = upgradeReport(['Secure Boot' => 'on'])."\n## Other\n\n```\nSecure Boot: off\n```\n";
    $section = "\n## Windows 11 readiness\n\n```\nnonsense without a colon\n: empty key\nSecure Boot: on\nSecure Boot: off\n```\n";

    expect((new ReportUpgrade)->card(ReportSections::parse($markdown))['rows'][0]['value'])->toBe('Yes')
        ->and((new ReportUpgrade)->card(ReportSections::parse("- Windows: Windows 10 Pro (build 19045.1)\n".$section))['rows'][0]['value'])->toBe('Cannot tell');
});

it('reads Dutch-locale text from the fixtures', function () {
    $laptop = (new ReportUpgrade)->card(ReportSections::parse((string) file_get_contents(__DIR__.'/../Fixtures/windows10-readiness-laptop.md')));
    $desktop = (new ReportUpgrade)->card(ReportSections::parse((string) file_get_contents(__DIR__.'/../Fixtures/windows10-readiness-desktop.md')));

    expect($laptop['rows'][0]['value'])->toBe('Yes')
        ->and(collect($laptop['rows'])->firstWhere('label', 'Upgrade options')['value'])->toBe('RAM can be expanded: 1 of 2 slots free, up to 32 GB')
        ->and($desktop['rows'][0]['value'])->toBe('No')
        ->and(array_column($desktop['rows'], 'label'))->toContain('The processor is too old', 'The security chip (TPM) is too old', 'Secure Boot is off');
});
