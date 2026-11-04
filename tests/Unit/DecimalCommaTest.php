<?php

declare(strict_types=1);

use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportParser;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportStorage;
use Carbon\CarbonImmutable;

const DC_PERFORMANCE = 'Performance: load, swap, memory, throttling';
const DC_SAMPLE = 'Performance: 10 second CPU sample (second snapshot is the real one)';
const DC_SPOTLIGHT = 'Performance: Spotlight indexing';
const DC_CPU = 'Top processes by CPU';
const DC_MEM = 'Top processes by memory';
const DC_HARDWARE = 'Hardware overview';
const DC_VERSION = 'macOS version';
const DC_CHIP = 'Model identifier and chip';
const DC_WIN_CPU = 'Top processes by CPU (5 second sample, grouped by name)';
const DC_DISK_USAGE = 'Disk usage';
const DC_FOLDERS = 'Performance: biggest folders in home';

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-04'));
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, string>  $sections
 * @param  array<string, string>  $header
 */
function dcDoc(array $sections = [], array $header = []): string
{
    $lines = ['# Report', '', '- Format: 1'];

    foreach ($header as $key => $value) {
        $lines[] = "- {$key}: {$value}";
    }

    $lines[] = '';

    foreach ($sections as $title => $body) {
        array_push($lines, "## {$title}", '', '```', $body, '```', '');
    }

    return implode("\n", $lines);
}

/**
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: array{title: string, items: list<array{label: string, value: string, tone: string}>}|null}|null
 */
function dcCard(string $id, string $markdown, array $flags = []): ?array
{
    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, $flags, 'laptop') as $card) {
        if ($card['id'] === $id) {
            return $card;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function dcSlowTitles(string $markdown): array
{
    $card = dcCard('slowness', $markdown);

    return $card === null ? [] : array_slice(array_column($card['rows'], 'label'), 1);
}

function dcFixture(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/mac-checkup-dutch-air.md');
}

function dcPs(string ...$rows): string
{
    return "  PID  %CPU %MEM COMM\n".implode("\n", $rows);
}

const DC_BUSY = 'The processor is working flat out';
const DC_SWAP = 'The drive is being used as extra memory';
const DC_INDEXING = 'The Mac is busy cataloguing its files';

describe('the Dutch-locale Mac scan', function () {
    it('explains why the Mac is slow instead of finding nothing', function () {
        $card = dcCard('slowness', dcFixture());

        expect($card['rows'][0]['value'])->toBe('Probably slow because of 2 things')
            ->and(array_slice(array_column($card['rows'], 'label'), 1))->toBe([DC_BUSY, DC_INDEXING]);
    });

    it('does not blame swap of 328 MB, a quarter of the memory or the program list', function () {
        $titles = dcSlowTitles(dcFixture());

        expect($titles)->not->toContain(DC_SWAP)
            ->and($titles)->not->toContain('Memory is getting full')
            ->and($titles)->not->toContain('mds is working the processor hard');
    });

    it('takes the memory size from the header and never from the raw memory pressure number', function () {
        $withoutHeader = str_replace("- Memory: 8 GB\n", '', dcFixture());

        expect(dcSlowTitles(dcFixture()))->not->toContain('Only 2 GB of memory')
            ->and(dcSlowTitles($withoutHeader))->not->toContain('Only 2 GB of memory');
    });

    it('still says Replace for this old Mac and dates it from its model', function () {
        $verdict = dcCard('verdict', dcFixture(), ['macOS 10.14.6 is old and likely out of security support.']);
        $age = dcCard('age', dcFixture());

        expect($verdict['rows'][0]['value'])->toBe('Replace')
            ->and(array_column($verdict['rows'], 'label'))->toContain('About 11 years old')
            ->and(array_column($age['rows'], 'value', 'label')['First released'])->toBe('2015');
    });
});

describe('Slowness reads either decimal separator', function () {
    it('swap', function (string $used, array $expected) {
        $body = "vm.loadavg: { 0,50 0,60 0,70 }\nvm.swapusage: total = 9216,00M  used = {$used}  free = 1,00M  (encrypted)";

        expect(dcSlowTitles(dcDoc([DC_PERFORMANCE => $body])))->toBe($expected);
    })->with([
        'comma over the limit' => ['2500,50M', [DC_SWAP]],
        'point over the limit' => ['2500.50M', [DC_SWAP]],
        'comma under the limit' => ['328,25M', []],
        'point under the limit' => ['328.25M', []],
        'gigabytes with a comma' => ['2,5G', [DC_SWAP]],
        'not a number' => ['1,2,3M', []],
    ]);

    it('five minute load against the physical cores', function (string $load, array $expected) {
        $body = "vm.loadavg: { {$load} }";
        $hardware = "Total Number of Cores: 2\nMemory: 8 GB";

        expect(dcSlowTitles(dcDoc([DC_PERFORMANCE => $body, DC_HARDWARE => $hardware])))->toBe($expected);
    })->with([
        'comma busy' => ['1,41 2,11 1,89', [DC_BUSY]],
        'point busy' => ['1.41 2.11 1.89', [DC_BUSY]],
        'comma quiet' => ['1,41 1,41 1,89', []],
        'point quiet' => ['1.41 1.41 1.89', []],
        'whole numbers' => ['1 2 3', [DC_BUSY]],
        'garbage' => ['1,,5 2 3', []],
    ]);

    it('the uptime line when the sysctl line is quieter', function (string $line, array $expected) {
        $quiet = 'vm.loadavg: { 1,41 1,41 1,89 }';

        expect(dcSlowTitles(dcDoc([DC_PERFORMANCE => $quiet, DC_VERSION => $line, DC_HARDWARE => 'Total Number of Cores: 2'])))->toBe($expected);
    })->with([
        'comma' => [' 9:12  up 2 days, 3:41, 2 users, load averages: 2,79 2,11 1,98', [DC_BUSY]],
        'point' => [' 9:12  up 2 days, 3:41, 2 users, load averages: 2.79 2.11 1.98', [DC_BUSY]],
        'commas between' => [' 9:12  up 2 days, load averages: 2,79, 2,11, 1,98', [DC_BUSY]],
        'quiet' => [' 9:12  up 2 days, load averages: 0,79 0,11 0,98', []],
        'single average' => [' 9:12  up 2 days, load average: 2,79', []],
    ]);

    it('counts the physical cores, then the logical ones', function (string $sections, array $expected) {
        $sectionList = [DC_PERFORMANCE => 'vm.loadavg: { 1,00 2,50 1,00 }'];
        $hardware = ['hardware' => [DC_HARDWARE => 'Total Number of Cores: 4 (2 performance and 2 efficiency)'], 'chip' => [DC_CHIP => "MacBookAir7,2\nIntel\n4\n8 GB RAM"], 'both' => [DC_HARDWARE => 'Total Number of Cores: 2', DC_CHIP => "MacBookAir7,2\nIntel\n8\n8 GB RAM"], 'zero' => [DC_HARDWARE => 'Total Number of Cores: 0', DC_CHIP => "MacBookAir7,2\nIntel\n2\n8 GB RAM"], 'none' => []];

        expect(dcSlowTitles(dcDoc($sectionList + $hardware[$sections])))->toBe($expected);
    })->with([
        'physical only' => ['hardware', []],
        'logical only' => ['chip', []],
        'physical wins over logical' => ['both', [DC_BUSY]],
        'no core count at all' => ['none', []],
        'zero physical cores falls back to logical' => ['zero', [DC_BUSY]],
    ]);

    it('the CPU sample idle percentage', function (string $line, array $expected) {
        expect(dcSlowTitles(dcDoc([DC_SAMPLE => "Processes: 400 total\n{$line}\nPhysMem: 7G used"])))->toBe($expected);
    })->with([
        'comma busy' => ['CPU usage: 60,50% user, 31,50% sys, 8,00% idle', [DC_BUSY]],
        'point busy' => ['CPU usage: 60.50% user, 31.50% sys, 8.00% idle', [DC_BUSY]],
        'comma quiet' => ['CPU usage: 10,00% user, 5,00% sys, 85,00% idle', []],
        'point quiet' => ['CPU usage: 10.00% user, 5.00% sys, 85.00% idle', []],
        'whole number' => ['CPU usage: 60% user, 32% sys, 8% idle', [DC_BUSY]],
        'over a hundred' => ['CPU usage: 1,0% user, 1,0% sys, 150,0% idle', []],
    ]);

    it('a program working the processor hard', function (string $row, array $expected) {
        expect(dcSlowTitles(dcDoc([DC_CPU => dcPs($row)])))->toBe($expected);
    })->with([
        'comma' => ['  101  85,5  1,2 Chrome', ['Chrome is working the processor hard']],
        'point' => ['  101  85.5  1.2 Chrome', ['Chrome is working the processor hard']],
        'comma under the limit' => ['  101  79,9  1,2 Chrome', []],
        'name with spaces' => ['  101 120,0  1,2 Google Chrome Helper', ['Google Chrome Helper is working the processor hard']],
        'glued numbers' => ['  101  85,,5  1,2 Chrome', []],
        'spotlight is reported separately' => ['   66  69,2  0,1 mds', []],
    ]);

    it('a program using a lot of memory', function (string $row, array $expected) {
        expect(dcSlowTitles(dcDoc([DC_MEM => dcPs($row)])))->toBe($expected);
    })->with([
        'comma' => ['  101  1,5 40,2 Photoshop', ['Photoshop is using a lot of memory']],
        'point' => ['  101  1.5 40.2 Photoshop', ['Photoshop is using a lot of memory']],
        'comma under the limit' => ['  101  1,5 34,9 Photoshop', []],
    ]);

    it('Spotlight indexing', function (string $row, array $expected) {
        expect(dcSlowTitles(dcDoc([DC_SPOTLIGHT => "/:\n\tIndexing enabled. \n{$row}"])))->toBe($expected);
    })->with([
        'comma' => ['   66  69,2 mds', [DC_INDEXING]],
        'point' => ['   66  69.2 mds', [DC_INDEXING]],
        'comma under the limit' => ['   66  19,9 mds', []],
        'helper with a comma' => ['  346  25,0 mdworker_shared', [DC_INDEXING]],
    ]);

    it('Windows program rows from Format-Table', function (string $row, array $expected) {
        expect(dcSlowTitles(dcDoc([DC_WIN_CPU => "Name CPU% Count MemoryMB\n{$row}"])))->toBe($expected);
    })->with([
        'comma' => ['chrome 55,5 3 800', ['chrome is working the processor hard']],
        'point' => ['chrome 55.5 3 800', ['chrome is working the processor hard']],
        'comma under the limit' => ['chrome 39,9 3 800', []],
    ]);

    it('the memory size in the header', function (string $memory, array $expected) {
        expect(dcSlowTitles(dcDoc([DC_MEM => dcPs('  101  1,5 1,2 Safari')], ['Memory' => $memory])))->toBe($expected);
    })->with([
        'comma' => ['3,5 GB', ['Only 3.5 GB of memory']],
        'point' => ['3.5 GB', ['Only 3.5 GB of memory']],
        'megabytes with a comma' => ['1536,0 MB', ['Only 1.5 GB of memory']],
        'enough' => ['8,0 GB', []],
        'nonsense' => ['3,5,5 GB', []],
    ]);
});

describe('Verdict reads a decimal memory size', function () {
    it('from either separator', function (string $memory, string $reason) {
        $header = ['Model' => 'MacBook Pro (MacBookPro18,1)', 'macOS' => '14.5 (23F79)', 'Memory' => $memory, 'Free disk' => '50%'];
        $storage = "Solid State: Yes\nSMART Status: Verified";
        $card = dcCard('verdict', dcDoc(['Storage (boot volume)' => $storage], $header));

        expect(array_column($card['rows'], 'label'))->toContain($reason);
    })->with([
        'comma' => ['3,5 GB', 'Only 3.5 GB of memory'],
        'point' => ['3.5 GB', 'Only 3.5 GB of memory'],
        'small side with a comma' => ['6,5 GB', '6.5 GB of memory is on the small side'],
    ]);
});

describe('Details reads decimal sizes from df and du', function () {
    it('free space from df -h', function (string $size, string $free) {
        $line = "Filesystem Size Used Avail Capacity\n/dev/disk1s1   {$size}   97Gi   {$free}    88%  842391 9223372036854933416    0%   /";
        $card = dcCard('storage', dcDoc([DC_DISK_USAGE => $line], ['Free disk' => '41%']));

        expect(array_column($card['rows'], 'value', 'label')['Free'])->toBe('1.5 TB (41%)');
    })->with([
        'comma' => ['1,8Ti', '1,5Ti'],
        'point' => ['1.8Ti', '1.5Ti'],
    ]);

    it('the biggest folders from du -h', function (string $size) {
        $card = dcCard('storage', dcDoc([DC_FOLDERS => "{$size}\t/Users/testuser/Documents"], ['Free disk' => '41%']));

        expect($card['list']['items'][0])->toBe(['label' => 'Documents', 'value' => '9.5 GB', 'tone' => 'neutral']);
    })->with(['comma' => ['9,5G'], 'point' => ['9.5G']]);

    it('skips a size that is not one number', function () {
        $card = dcCard('storage', dcDoc([DC_FOLDERS => "9,5,5G\t/Users/testuser/Documents"], ['Free disk' => '41%']));

        expect($card['list'])->toBeNull();
    });
});

describe('the storage breakdown', function () {
    it('reads sizes with either separator and counts with thousands', function (string $size, string $count) {
        $body = "scan: complete\nhome|{$size}\ntype|Photos|{$size}|{$count}\nfolder|Downloads|{$size}";
        $read = (new ReportStorage)->read(ReportSections::parse(dcDoc(['Storage breakdown' => $body])));

        expect($read['home'])->toBe(1.5 * 1024 ** 3)
            ->and($read['categories'][0]['count'])->toBe(1234)
            ->and($read['folders'][0]['bytes'])->toBe(1.5 * 1024 ** 3);
    })->with([
        'comma' => ['1,5 GB', '1,234'],
        'point' => ['1.5 GB', '1.234'],
        'plain count' => ['1.5 GB', '1234'],
    ]);

    it('drops a size or a count that is not a number', function () {
        $body = "scan: complete\nhome|1,5,5 GB\ntype|Photos|1024|1,23\nfolder|Downloads|1024";
        $read = (new ReportStorage)->read(ReportSections::parse(dcDoc(['Storage breakdown' => $body])));

        expect($read['home'])->toBeNull()
            ->and($read['categories'][0]['count'])->toBeNull();
    });
});

describe('the benchmark section', function () {
    it('reads results printed with a decimal comma or point', function (string $value, int|float|null $expected) {
        $parsed = (new ReportParser)->parse("# Mac checkup: Test\n\n- Format: 1\n\n## Benchmark\n\n- cpu_single_ms: {$value}\n- memory_mb_s: 12\n");

        expect($parsed->benchmark['cpu_single_ms'] ?? null)->toBe($expected)
            ->and($parsed->benchmark['memory_mb_s'])->toBe(12);
    })->with([
        'integer' => ['93', 93],
        'point' => ['93.5', 93.5],
        'comma' => ['93,5', 93.5],
        'thousands and comma' => ['1.293,5', 1293.5],
        'list' => ['1,2,3', null],
        'words' => ['abc', null],
    ]);
});

describe('flag keys', function () {
    it('are the same whichever separator and length the number used', function () {
        $advisor = new FlagAdvisor;

        expect($advisor->advise('Custom note 1,5 here.')->key)->toBe($advisor->advise('Custom note 12.25 here.')->key)
            ->and($advisor->advise('Custom note 1,5 here.')->key)->toBe('Custom note # here')
            ->and($advisor->advise('Custom note 1, 5 here.')->key)->toBe('Custom note #, # here');
    });
});
