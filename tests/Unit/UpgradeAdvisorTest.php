<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\UpgradeAdvisor;
use Carbon\CarbonImmutable;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-07 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function upaSection(string $title, string $body): string
{
    return "\n## {$title}\n\n```\n{$body}\n```\n";
}

function upaDisks(string ...$rows): string
{
    return upaSection('Storage (physical disks)', "FriendlyName MediaType BusType SizeGB HealthStatus OperationalStatus\n------------ --------- ------- ------ ------------ -----------------\n".implode("\n", $rows));
}

function upaBios(?string $date): string
{
    return $date === null ? '' : upaSection('BIOS and firmware age', "BIOS release date: {$date} (about 7 full years ago)");
}

function upaModules(string ...$modules): string
{
    return upaSection('Memory modules', implode("\n", ['format: 1', 'scan: complete', ...$modules]));
}

function upaReadiness(string $slots, string $form = 'socketed', string $maximum = '32 GB'): string
{
    return upaSection('Windows 11 readiness', "Memory slots: {$slots}\nMemory form: {$form}\nMemory maximum: {$maximum}");
}

function upaWindows(string $body, string $memory = '8 GB', ?string $bios = '2019-05-01'): string
{
    return "# Windows checkup: TEST\n\n- Format: 1\n- OS: Windows\n- Memory: {$memory}\n".upaBios($bios).$body;
}

/**
 * @return list<array{id: string, title: string, rows: list<array{label: string, value: string, tone: 'good'|'bad'|'neutral'}>, list: null}>
 */
function upaVerdict(string $verdict): array
{
    return [
        ['id' => 'age', 'title' => 'Age', 'rows' => [['label' => 'Verdict', 'value' => 'Replace', 'tone' => 'bad']], 'list' => null],
        ['id' => 'verdict', 'title' => 'Worth keeping?', 'rows' => [['label' => 'Verdict', 'value' => $verdict, 'tone' => 'neutral'], ['label' => 'Good to know', 'value' => 'A rule of thumb.', 'tone' => 'neutral']], 'list' => null],
    ];
}

/**
 * @param  list<array{id: string, title: string, rows: list<array{label: string, value: string, tone: 'good'|'bad'|'neutral'}>, list: null}>  $cards
 * @return array{part: 'ssd'|'memory', text: string, memoryType: string|null, moduleGb: int|null, bus: string|null, driveGb: int|null, form: string|null}|null
 */
function upaAdvise(string $markdown, array $cards = []): ?array
{
    return (new UpgradeAdvisor)->advise(ReportSections::parse($markdown), $cards);
}

function upaHdd(): string
{
    return upaDisks('ST500LM000 HDD SATA 466 Healthy OK');
}

function upaFreeSlot(string $module = 'module|ChannelA-DIMM0|4096 MB|DDR4|2400 MHz|SODIMM', string $maximum = '32 GB'): string
{
    return upaModules($module).upaReadiness('2 total, 1 used', 'socketed', $maximum);
}

describe('the SSD advice', function () {
    it('swaps a spinning hard drive for an SSD, with its size, its connection and the age of the machine', function () {
        expect(upaAdvise(upaWindows(upaHdd())))->toBe([
            'part' => 'ssd',
            'text' => 'Swap the 466 GB spinning hard drive for a SATA SSD: the biggest speed gain for a machine of about 7 years old.',
            'memoryType' => null,
            'moduleGb' => null,
            'bus' => 'SATA',
            'driveGb' => 466,
            'form' => null,
        ]);
    });

    it('still advises without the age, the size or the connection', function () {
        expect(upaAdvise(upaWindows(upaDisks('Disk HDD Unspecified 0 Healthy OK'), bios: null))['text'] ?? null)
            ->toBe('Swap the spinning hard drive for an SSD: the biggest speed gain for this machine.');
    });

    it('reads the drives of the health step before the older table', function () {
        $health = upaSection('Windows health and security', 'Drive 1: WDC | type HDD | bus SATA | size 932 GB | health Healthy');

        expect(upaAdvise(upaWindows($health.upaDisks('Fast SSD NVMe 477 Healthy OK')))['text'] ?? null)->toStartWith('Swap the 932 GB spinning hard drive');
    });

    it('advises an SSD for an older Mac whose boot volume spins', function () {
        $markdown = "# Mac checkup: TEST\n\n- OS: macOS\n- Model: iMac (iMac19,2)\n- Memory: 8 GB\n".upaSection('Storage (boot volume)', "   Solid State:               No\n   Protocol:                  SATA");

        expect(upaAdvise($markdown)['text'] ?? null)->toBe('Swap the spinning hard drive for a SATA SSD: the biggest speed gain for a machine of about 7 years old.');
    });

    it('gives no SSD advice when an internal SSD is there already, or when the spinning drive is on USB', function (string $disks) {
        expect(upaAdvise(upaWindows($disks)))->toBeNull();
    })->with([
        'SSD and HDD' => [upaDisks('Fast SSD NVMe 477 Healthy OK', 'Slow HDD SATA 932 Healthy OK')],
        'USB HDD' => [upaDisks('Fast SSD NVMe 477 Healthy OK', 'Backup HDD USB 1863 Healthy OK')],
        'only SSD' => [upaDisks('Fast SSD NVMe 477 Healthy OK')],
        'no drives' => [''],
    ]);
});

describe('the memory advice', function () {
    it('adds a matching module in a free slot to reach 8 GB', function () {
        expect(upaAdvise(upaWindows(upaFreeSlot(), '4 GB')))->toBe([
            'part' => 'memory',
            'text' => 'Add a module of 4 GB (DDR4 SODIMM, 2400 MHz) in the free slot to go from 4 to 8 GB of memory.',
            'memoryType' => 'DDR4',
            'moduleGb' => 4,
            'bus' => null,
            'driveGb' => null,
            'form' => 'SODIMM',
        ]);
    });

    it('picks a module big enough to reach 8 GB', function () {
        expect(upaAdvise(upaWindows(upaFreeSlot('module|DIMM1|2048 MB|DDR3|1600 MHz|SODIMM'), '2 GB'))['text'] ?? null)
            ->toBe('Add a module of 8 GB (DDR3 SODIMM, 1600 MHz) in the free slot to go from 2 to 10 GB of memory.');
    });

    it('falls back to the smallest module that is enough when a bigger one would pass the maximum', function () {
        expect(upaAdvise(upaWindows(upaModules('module|A|4096 MB|DDR3|1600 MHz|DIMM', 'module|B|2048 MB|DDR3|1600 MHz|DIMM').upaReadiness('4 total, 2 used', 'socketed', '8 GB'), '6 GB'))['text'] ?? null)
            ->toBe('Add a module of 2 GB (DDR3 DIMM, 1600 MHz) in the free slot to go from 6 to 8 GB of memory.');
    });

    it('works from an older report that has only the readiness step', function () {
        expect(upaAdvise(upaWindows(upaReadiness('2 total, 1 used'), '4 GB'))['text'] ?? null)
            ->toBe('Add a module of 4 GB in the free slot to go from 4 to 8 GB of memory.');
    });

    it('swaps socketed modules for bigger ones when every slot is in use', function (string $modules, string $memory, string $text) {
        expect(upaAdvise(upaWindows($modules, $memory))['text'] ?? null)->toBe($text);
    })->with([
        'two slots' => [upaModules('module|A|2048 MB|DDR3|1600 MHz|SODIMM', 'module|B|2048 MB|DDR3|1600 MHz|SODIMM').upaReadiness('2 total, 2 used', 'socketed', '16 GB'), '4 GB', 'Replace the 2 memory modules with 2 modules of 4 GB (DDR3 SODIMM, 1600 MHz) to go from 4 to 8 GB of memory.'],
        'one slot' => [upaModules('module|A|4096 MB|DDR4|2400 MHz|SODIMM').upaReadiness('1 total, 1 used', 'socketed', '16 GB'), '4 GB', 'Replace the memory module with a module of 8 GB (DDR4 SODIMM, 2400 MHz) to go from 4 to 8 GB of memory.'],
    ]);

    it('gives no memory advice when no part can be added or swapped', function (string $body, string $memory) {
        expect(upaAdvise(upaWindows($body, $memory)))->toBeNull();
    })->with([
        'enough memory' => [upaFreeSlot('module|A|8192 MB|DDR4|2400 MHz|SODIMM'), '8 GB'],
        'LPDDR with a free slot' => [upaFreeSlot('module|A|4096 MB|LPDDR4|4267 MHz|SODIMM'), '4 GB'],
        'full and maximum unknown' => [upaModules('module|A|4096 MB|DDR4|2400 MHz|SODIMM').upaReadiness('1 total, 1 used', 'socketed', 'could not check'), '4 GB'],
        'full and form unknown' => [upaModules('module|A|4096 MB|DDR4|2400 MHz|').upaReadiness('1 total, 1 used', 'soldered or unknown', '16 GB'), '4 GB'],
        'free slot but form unknown' => [upaModules('module|A|4096 MB|DDR4|2400 MHz|').upaReadiness('2 total, 1 used', 'soldered or unknown', '16 GB'), '4 GB'],
        'free slot from the readiness step but form unknown' => [upaReadiness('2 total, 1 used', 'soldered or unknown'), '4 GB'],
        'full at the maximum' => [upaModules('module|A|2048 MB|DDR3|1600 MHz|SODIMM', 'module|B|2048 MB|DDR3|1600 MHz|SODIMM').upaReadiness('2 total, 2 used', 'socketed', '4 GB'), '4 GB'],
        'free slot but no room' => [upaFreeSlot(maximum: '4 GB'), '4 GB'],
        'free slot but a maximum too small for any step' => [upaFreeSlot(maximum: '6 GB'), '4 GB'],
        'no installed amount' => [upaReadiness('2 total, 1 used'), 'n/a'],
        'slots unknown' => [upaModules('module|A|4096 MB|DDR4|2400 MHz|SODIMM'), '4 GB'],
    ]);

    it('never advises memory for a Mac that says it cannot be upgraded, does not say it can, or is Apple silicon', function (string $lines) {
        $markdown = "# Mac checkup: TEST\n\n- OS: macOS\n- Model: MacBook Air (MacBookAir10,1)\n- Memory: 4 GB\n".upaSection('Memory and drives', "format: 1\nmemory: complete\n{$lines}\ndrives: complete\ndrive|SSD|NVMe|251 GB");

        expect(upaAdvise($markdown))->toBeNull();
    })->with([
        'soldered' => ["memory-upgradeable: no\nmodule|BANK 0/DIMM0|4 GB|LPDDR3|2133 MHz|\nmodule|BANK 1/DIMM0|empty|||"],
        'unified' => ["memory-unified: yes\nmemory-type: LPDDR4"],
        'no upgradeable line' => ["module|DIMM1|4 GB|DDR4|2667 MHz|\nmodule|DIMM2|empty|||"],
    ]);

    it('advises memory for a Mac whose slots can be upgraded', function () {
        $markdown = "# Mac checkup: TEST\n\n- OS: macOS\n- Model: iMac (iMac19,1)\n- Memory: 4 GB\n".upaSection('Memory and drives', "format: 1\nmemory: complete\nmemory-upgradeable: yes\nmodule|DIMM1|4 GB|DDR4|2667 MHz|\nmodule|DIMM2|empty|||\ndrives: complete\ndrive|SSD|NVMe|251 GB");

        expect(upaAdvise($markdown)['text'] ?? null)->toBe('Add a module of 4 GB (DDR4, 2667 MHz) in the free slot to go from 4 to 8 GB of memory.');
    });
});

describe('what decides whether there is advice at all', function () {
    it('puts the SSD first when both the drive and the memory could be upgraded', function () {
        expect(upaAdvise(upaWindows(upaHdd().upaFreeSlot(), '4 GB'))['part'] ?? null)->toBe('ssd');
    });

    it('never advises an upgrade when the verdict says replace', function () {
        expect(upaAdvise(upaWindows(upaHdd().upaFreeSlot(), '4 GB'), upaVerdict('Replace')))->toBeNull();
    });

    it('advises when the verdict is keep, repair or not decided', function (string $verdict) {
        expect(upaAdvise(upaWindows(upaHdd()), upaVerdict($verdict)))->not->toBeNull();
    })->with(['Keep', 'Repair', 'Not enough data to judge']);

    it('reads only the verdict card for the verdict', function () {
        $cards = upaVerdict('Keep');
        array_pop($cards);

        expect(upaAdvise(upaWindows(upaHdd()), $cards))->not->toBeNull();
    });

    it('advises up to the age where the verdict starts to plan a replacement, and not after', function (string $bios, bool $advised) {
        expect(upaAdvise(upaWindows(upaHdd(), bios: $bios)) !== null)->toBe($advised);
    })->with([
        'eight years' => ['2018-11-01', true],
        'nine years' => ['2017-11-01', false],
        'twenty years' => ['2006-01-01', false],
    ]);

    it('gives nothing for an empty report', function () {
        expect(upaAdvise(''))->toBeNull();
    });
});
