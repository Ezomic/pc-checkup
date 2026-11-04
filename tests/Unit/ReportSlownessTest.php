<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

const SLOW_MAC_MEMORY = 'Memory pressure';
const SLOW_MAC_PERFORMANCE = 'Performance: load, swap, memory, throttling';
const SLOW_MAC_SAMPLE = 'Performance: 10 second CPU sample (second snapshot is the real one)';
const SLOW_MAC_SPOTLIGHT = 'Performance: Spotlight indexing';
const SLOW_MAC_DISK_SPEED = 'Performance: disk write and read speed (512 MB test)';
const SLOW_MAC_CPU = 'Top processes by CPU';
const SLOW_MAC_MEM = 'Top processes by memory';
const SLOW_MAC_CHIP = 'Model identifier and chip';
const SLOW_MAC_DISKS = 'Storage (boot volume)';
const SLOW_WIN_CPU = 'Top processes by CPU (5 second sample, grouped by name)';
const SLOW_WIN_MEM = 'Top processes by memory (grouped by name)';
const SLOW_WIN_MEMORY = 'CPU and memory';
const SLOW_WIN_DISKS = 'Storage (physical disks)';

/**
 * @param  array<string, string>  $sections
 * @param  array<string, string>  $header
 */
function slowDoc(array $sections = [], array $header = []): string
{
    $lines = ['# Report', '', '- Format: 1'];

    foreach ($header as $key => $value) {
        $lines[] = "- {$key}: {$value}";
    }

    $lines[] = '';

    foreach ($sections as $title => $body) {
        $lines[] = "## {$title}";
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $body;
        $lines[] = '```';
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: null}|null
 */
function slowCard(string $markdown): ?array
{
    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop') as $card) {
        if ($card['id'] === 'slowness') {
            return $card;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function slowTitles(string $markdown): array
{
    $card = slowCard($markdown);

    return $card === null ? [] : array_slice(array_column($card['rows'], 'label'), 1);
}

function slowVerdict(string $markdown): ?string
{
    return slowCard($markdown)['rows'][0]['value'] ?? null;
}

function slowMemory(string $text): string
{
    return slowDoc([SLOW_MAC_MEMORY => $text]);
}

function slowSwap(string $used): string
{
    return slowDoc([SLOW_MAC_PERFORMANCE => "vm.loadavg: { 0.50 0.60 0.70 }\nvm.swapusage: total = 9216.00M  used = {$used}  free = 1.00M  (encrypted)"]);
}

function slowPs(string ...$rows): string
{
    return "  PID  %CPU %MEM COMMAND\n".implode("\n", $rows);
}

describe('the verdict', function () {
    it('says nothing is obviously slowing it down when no rule fires', function () {
        $card = slowCard(slowDoc([SLOW_MAC_MEMORY => 'System-wide memory free percentage: 62%'], ['Memory' => '16 GB', 'Free disk' => '50%']));

        expect($card['title'])->toBe('Why is it slow?')
            ->and($card['rows'])->toBe([['label' => 'Verdict', 'value' => 'Nothing obviously slowing it down', 'tone' => 'good']])
            ->and($card['list'])->toBeNull();
    });

    it('has no card when the report holds none of the data it looks at', function () {
        expect(slowCard(slowDoc(['Battery and power' => 'Nothing here'])))->toBeNull()
            ->and(slowCard(''))->toBeNull();
    });

    it('names one reason in the singular', function () {
        $card = slowCard(slowDoc([], ['Free disk' => '4%']));

        expect($card['rows'][0])->toBe(['label' => 'Verdict', 'value' => 'Probably slow because of 1 thing', 'tone' => 'bad'])
            ->and($card['rows'][1]['label'])->toBe('The drive is almost full')
            ->and($card['rows'][1]['tone'])->toBe('bad');
    });

    it('shows a milder verdict tone when no reason is serious', function () {
        $card = slowCard(slowDoc([], ['Memory' => '4 GB']));

        expect($card['rows'][0]['tone'])->toBe('neutral')
            ->and($card['rows'][1]['tone'])->toBe('neutral');
    });

    it('shows at most three reasons, most serious first, and counts only those', function () {
        $markdown = slowDoc([
            SLOW_MAC_DISKS => 'Solid State: No',
            SLOW_MAC_MEMORY => 'System-wide memory free percentage: 5%',
            SLOW_MAC_PERFORMANCE => "CPU_Speed_Limit = 60\n lowpowermode         1",
        ], ['Memory' => '4 GB', 'Free disk' => '9%']);

        expect(slowVerdict($markdown))->toBe('Probably slow because of 3 things')
            ->and(slowTitles($markdown))->toBe([
                'Memory is almost full',
                'The computer has an old style spinning drive',
                'The processor is slowing itself down',
            ]);
    });

    it('keeps the order of the rules when two reasons are equally serious', function () {
        $markdown = slowDoc([SLOW_MAC_MEMORY => 'System-wide memory free percentage: 12%'], ['Memory' => '4 GB']);

        expect(slowTitles($markdown))->toBe(['Memory is getting full', 'Only 4 GB of memory']);
    });
});

describe('memory', function () {
    it('reads the free percentage from a Mac', function (string $text, array $expected) {
        expect(slowTitles(slowMemory($text)))->toBe($expected);
    })->with([
        'low' => ['System-wide memory free percentage: 14%', ['Memory is getting full']],
        'very low' => ['System-wide memory free percentage: 7%', ['Memory is almost full']],
        'exactly at the limit' => ['System-wide memory free percentage: 15%', []],
        'plenty' => ['System-wide memory free percentage: 62%', []],
        'no percent sign' => ['System-wide memory free percentage: 10', ['Memory is getting full']],
        'a space before the sign' => ['System-wide memory free percentage: 10 %', ['Memory is getting full']],
        'a minus sign' => ['System-wide memory free percentage: -5%', []],
        'a plus sign' => ['System-wide memory free percentage: +5%', []],
        'above 100' => ['System-wide memory free percentage: 340%', []],
        'not a number' => ['System-wide memory free percentage: n/a', []],
        'empty' => ['System-wide memory free percentage:', []],
        'a huge number' => ['System-wide memory free percentage: 99999999999999999999%', []],
        'the line is missing' => ["The system has 8589934592 (2097152 pages with a page size of 4096).\nPages free: 100", []],
        'the first of two copies counts' => ["System-wide memory free percentage: 50%\nSystem-wide memory free percentage: 2%", []],
        'windows line endings' => ["The system has 8589934592\r\nSystem-wide memory free percentage: 9%\r\n", ['Memory is getting full']],
    ]);

    it('reads the available percentage from a Windows PC', function (string $text, array $expected) {
        expect(slowTitles(slowDoc([SLOW_WIN_MEMORY => $text])))->toBe($expected);
    })->with([
        'low' => ['Total: 8 GB, available: 9% of visible memory', ['Memory is getting full']],
        'very low' => ['Total: 8 GB, available: 3% of visible memory', ['Memory is almost full']],
        'plenty' => ['Total: 8 GB, available: 40% of visible memory', []],
        'not a number' => ['Total: 8 GB, available: % of visible memory', []],
        'unavailable' => ['Total: 8 GB, available: unavailable of visible memory', []],
    ]);

    it('turns on swap use from two gigabytes up, in any unit', function (string $used, array $expected) {
        expect(slowTitles(slowSwap($used)))->toBe($expected);
    })->with([
        'megabytes over' => ['2048.50M', ['The drive is being used as extra memory']],
        'megabytes under' => ['1024.00M', []],
        'exactly two gigabytes' => ['2048.00M', ['The drive is being used as extra memory']],
        'gigabytes' => ['3.00G', ['The drive is being used as extra memory']],
        'small gigabytes' => ['0.50G', []],
        'kilobytes' => ['512000.00K', []],
        'a lot of kilobytes' => ['4000000.00K', ['The drive is being used as extra memory']],
        'zero' => ['0.00M', []],
        'not a number' => ['n/a', []],
        'a huge number' => ['99999999999999999999.00M', []],
    ]);

    it('ignores swap when the report has no swap line', function () {
        expect(slowTitles(slowDoc([SLOW_MAC_PERFORMANCE => 'vm.loadavg: { 0.50 0.60 0.70 }'])))->toBe([]);
    });

    it('flags small memory in gigabytes or megabytes', function (string $memory, array $expected) {
        expect(slowTitles(slowDoc([], ['Memory' => $memory])))->toBe($expected);
    })->with([
        'four gigabytes' => ['4 GB', ['Only 4 GB of memory']],
        'seven gigabytes' => ['7 GB', ['Only 7 GB of memory']],
        'eight gigabytes' => ['8 GB', []],
        'in megabytes, small' => ['4096 MB', ['Only 4 GB of memory']],
        'in megabytes, big' => ['16384 MB', []],
        'half a gigabyte' => ['512 MB', ['Only 0.5 GB of memory']],
        'in terabytes' => ['1 TB', []],
        'no unit' => ['4', []],
        'n/a' => ['n/a', []],
        'a huge number' => ['99999999999999999999 GB', []],
        'zero' => ['0 GB', []],
    ]);

    it('says nothing about memory when the header line is empty', function () {
        expect(slowCard("# Report\n\n- Format: 1\n- Memory:\n\n## Things to look at\n"))->toBeNull();
    });

    it('names the program using a lot of memory on a Mac', function () {
        $markdown = slowDoc([SLOW_MAC_MEM => slowPs('  412   2.0 41.5 Google Chrome Helper', '  500   1.0  3.0 Finder')]);

        expect(slowTitles($markdown))->toBe(['Google Chrome Helper is using a lot of memory']);
    });

    it('leaves a program alone that uses a normal share of memory', function () {
        expect(slowTitles(slowDoc([SLOW_MAC_MEM => slowPs('  412   2.0 34.9 Safari')])))->toBe([]);
    });

    it('works out a Windows program share from the memory total', function () {
        $body = "Name      MemoryMB Count\n----      -------- -----\nchrome        4096    31\nsvchost        300    60";

        expect(slowTitles(slowDoc([SLOW_WIN_MEM => $body], ['Memory' => '8 GB'])))->toBe(['chrome is using a lot of memory'])
            ->and(slowTitles(slowDoc([SLOW_WIN_MEM => $body], ['Memory' => '32 GB'])))->toBe([])
            ->and(slowTitles(slowDoc([SLOW_WIN_MEM => $body])))->toBe([]);
    });
});

describe('the drive', function () {
    it('flags a drive that is nearly full', function (string $free, array $expected) {
        expect(slowTitles(slowDoc([], ['Free disk' => $free])))->toBe($expected);
    })->with([
        'nine percent' => ['9%', ['The drive is getting full']],
        'ten percent' => ['10%', []],
        'four percent' => ['4%', ['The drive is almost full']],
        'five percent' => ['5%', ['The drive is getting full']],
        'no percent sign' => ['3', ['The drive is almost full']],
        'zero' => ['0%', ['The drive is almost full']],
        'a decimal' => ['4.5%', []],
        'a minus sign' => ['-5%', []],
        'above 100' => ['250%', []],
        'n/a' => ['n/a', []],
        'unknown' => ['unknown', []],
        'a huge number' => ['99999999999999999999%', []],
    ]);

    it('flags a spinning drive on a Mac and a Windows PC', function () {
        $windows = "FriendlyName MediaType BusType SizeGB HealthStatus OperationalStatus\n------------ --------- ------- ------ ------------ -----------------\nWDC WD10EZEX HDD       SATA       932 Healthy      OK";

        expect(slowTitles(slowDoc([SLOW_MAC_DISKS => "   Solid State:               No\n   SMART Status:              Verified"])))->toBe(['The computer has an old style spinning drive'])
            ->and(slowTitles(slowDoc([SLOW_WIN_DISKS => $windows])))->toBe(['The computer has an old style spinning drive']);
    });

    it('does not call a solid state drive or a mixed set slow', function () {
        $mixed = "FriendlyName MediaType BusType SizeGB HealthStatus OperationalStatus\nA SSD NVMe 932 Healthy OK\nB HDD SATA 932 Healthy OK";

        expect(slowTitles(slowDoc([SLOW_MAC_DISKS => '   Solid State:               Yes'])))->toBe([])
            ->and(slowTitles(slowDoc([SLOW_WIN_DISKS => $mixed])))->toBe([])
            ->and(slowTitles(slowDoc([SLOW_MAC_DISKS => '   Solid State:               Unknown'])))->toBe([]);
    });

    it('flags a slow speed test in the slowest direction', function (string $body, array $expected) {
        expect(slowTitles(slowDoc([SLOW_MAC_DISK_SPEED => $body])))->toBe($expected);
    })->with([
        'slow write' => ["536870912 bytes transferred in 10.7 secs (50000000 bytes/sec)\n536870912 bytes transferred in 0.4 secs (1300000000 bytes/sec)", ['The drive is slow to read and write']],
        'slow read' => ["536870912 bytes transferred in 0.4 secs (1300000000 bytes/sec)\n536870912 bytes transferred in 5.4 secs (99000000 bytes/sec)", ['The drive is slow to read and write']],
        'healthy' => ["536870912 bytes transferred in 0.4 secs (1300000000 bytes/sec)\n536870912 bytes transferred in 0.2 secs (2600000000 bytes/sec)", []],
        'exactly at the limit' => ['536870912 bytes transferred in 5.4 secs (100000000 bytes/sec)', []],
        'only one direction was read' => ['536870912 bytes transferred in 10.7 secs (50000000 bytes/sec)', ['The drive is slow to read and write']],
        'the test failed' => ['dd: /Users/x/.speedtest.AbC: No space left on device', []],
        'zero speed' => ['0 bytes transferred in 0.0 secs (0 bytes/sec)', []],
        'a huge number' => ['1 bytes transferred in 0.1 secs (99999999999999999999999 bytes/sec)', []],
        'the closing hint alone' => ['Healthy SSD: several hundred MB/s or more. Under ~100 MB/s means a failing or full disk.', []],
        'empty' => ['', []],
    ]);

    it('does not blame the speed test on a drive already named as spinning', function () {
        $markdown = slowDoc([
            SLOW_MAC_DISKS => 'Solid State: No',
            SLOW_MAC_DISK_SPEED => '536870912 bytes transferred in 10.7 secs (50000000 bytes/sec)',
        ]);

        expect(slowTitles($markdown))->toBe(['The computer has an old style spinning drive']);
    });
});

describe('the processor', function () {
    it('reads how idle the processor was in the ten second sample', function (string $line, array $expected) {
        expect(slowTitles(slowDoc([SLOW_MAC_SAMPLE => "Processes: 400 total\n{$line}\nPhysMem: 7G used"])))->toBe($expected);
    })->with([
        'busy' => ['CPU usage: 60.50% user, 31.50% sys, 8.00% idle', ['The processor is working flat out']],
        'exactly at the limit' => ['CPU usage: 60.00% user, 25.00% sys, 15.00% idle', ['The processor is working flat out']],
        'plenty of room' => ['CPU usage: 10.00% user, 5.00% sys, 85.00% idle', []],
        'not a number' => ['CPU usage: n/a user, n/a sys, n/a idle', []],
        'the line is missing' => ['Load Avg: 1.0, 1.0, 1.0', []],
        'above 100' => ['CPU usage: 0.00% user, 0.00% sys, 900.00% idle', []],
        'a digit run that hides a small number' => ['CPU usage: 0.00% user, 0.00% sys, 1900.00% idle', []],
    ]);

    it('compares the five minute load with the number of cores', function (string $load, string $chip, array $expected) {
        $markdown = slowDoc([
            SLOW_MAC_PERFORMANCE => "vm.loadavg: { {$load} }",
            SLOW_MAC_CHIP => $chip,
        ]);

        expect(slowTitles($markdown))->toBe($expected);
    })->with([
        'overloaded' => ['1.00 4.50 3.00', "MacBookAir7,2\nIntel(R) Core(TM) i5-5250U\n4\n8 GB RAM", ['The processor is working flat out']],
        'exactly the cores' => ['1.00 4.00 3.00', "MacBookAir7,2\nIntel(R) Core(TM) i5-5250U\n4\n8 GB RAM", ['The processor is working flat out']],
        'only the one minute load is high' => ['9.00 1.00 1.00', "MacBookAir7,2\nIntel(R) Core(TM) i5-5250U\n4\n8 GB RAM", []],
        'under the cores' => ['1.00 3.99 3.00', "MacBookAir7,2\nIntel(R) Core(TM) i5-5250U\n4\n8 GB RAM", []],
        'no core count' => ['1.00 40.00 3.00', "MacBookAir7,2\nApple M1", []],
        'zero cores' => ['1.00 40.00 3.00', "MacBookAir7,2\n0", []],
        'not a number' => ['a b c', "MacBookAir7,2\n4", []],
    ]);

    it('ignores the load when the report has no chip section', function () {
        expect(slowTitles(slowDoc([SLOW_MAC_PERFORMANCE => 'vm.loadavg: { 50.00 50.00 50.00 }'])))->toBe([]);
    });

    it('names a Mac program that keeps a core busy', function () {
        $markdown = slowDoc([SLOW_MAC_CPU => slowPs('  412  95.5  3.1 Google Chrome Helper', '  500  70.0  1.0 Finder')]);

        expect(slowTitles($markdown))->toBe(['Google Chrome Helper is working the processor hard']);
    });

    it('leaves a Mac program alone below the limit', function () {
        expect(slowTitles(slowDoc([SLOW_MAC_CPU => slowPs('  412  79.9  3.1 Safari')])))->toBe([]);
    });

    it('names a Windows program that uses a big part of the whole PC', function () {
        $body = "Name      CPU% Count MemoryMB\n----      ---- ----- --------\nchrome    55.2    31     4096\nsvchost    2.0    60      300";

        expect(slowTitles(slowDoc([SLOW_WIN_CPU => $body])))->toBe(['chrome is working the processor hard']);
    });

    it('leaves a Windows program alone below the limit', function () {
        $body = "Name CPU% Count MemoryMB\n---- ---- ----- --------\nchrome 39.9 31 4096";

        expect(slowTitles(slowDoc([SLOW_WIN_CPU => $body])))->toBe([]);
    });

    it('never counts the idle pseudo program, the kernel or Spotlight as a program to close', function () {
        $windows = "Name CPU% Count MemoryMB\nIdle 95.0 8 0";
        $mac = slowPs('  0 300.0 0.1 kernel_task', ' 88  90.0 0.5 mds_stores', ' 89  90.0 0.5 mdworker_shared');

        expect(slowTitles(slowDoc([SLOW_WIN_CPU => $windows])))->toBe([])
            ->and(slowTitles(slowDoc([SLOW_MAC_CPU => $mac])))->toBe([]);
    });

    it('skips rows that are not numbers and keeps reading', function () {
        $markdown = slowDoc([SLOW_MAC_CPU => slowPs('  abc  n/a  n/a Broken', '  412  95.5  3.1 Safari')]);

        expect(slowTitles($markdown))->toBe(['Safari is working the processor hard']);
    });

    it('takes the first row when a program is listed twice', function () {
        $markdown = slowDoc([SLOW_MAC_CPU => slowPs('  412  95.5  3.1 Safari', '  413  99.0  3.1 Safari')]);

        expect(slowTitles($markdown))->toBe(['Safari is working the processor hard']);
    });

    it('names only the program, never a path or a command line', function (string $name, string $expected) {
        $markdown = slowDoc([SLOW_MAC_CPU => slowPs("  412  95.5  3.1 {$name}")]);

        expect(slowTitles($markdown))->toBe(["{$expected} is working the processor hard"]);
    })->with([
        'a full path' => ['/Applications/Safari.app/Contents/MacOS/Safari', 'Safari'],
        'a very long name' => [str_repeat('a', 80), str_repeat('a', 40).'…'],
        'markup' => ['<b>Safari</b>', '<b>Safari</b>'],
    ]);

    it('reports a program that keeps both the processor and the memory busy under two titles', function () {
        $markdown = slowDoc([
            SLOW_MAC_CPU => slowPs('  412  95.5  40.0 Safari'),
            SLOW_MAC_MEM => slowPs('  412  95.5  40.0 Safari'),
        ]);

        expect(slowTitles($markdown))->toBe(['Safari is working the processor hard', 'Safari is using a lot of memory']);
    });
});

describe('heat and power settings', function () {
    it('flags a processor that is slowing itself down', function (string $therm, array $expected) {
        $body = "Note: No thermal warning level has been recorded\nCPU_Scheduler_Limit = 100\nCPU_Available_CPUs = 8\n{$therm}";

        expect(slowTitles(slowDoc([SLOW_MAC_PERFORMANCE => $body])))->toBe($expected);
    })->with([
        'limited' => ['CPU_Speed_Limit = 70', ['The processor is slowing itself down']],
        'not limited' => ['CPU_Speed_Limit = 100', []],
        'not a number' => ['CPU_Speed_Limit = unknown', []],
        'above 100' => ['CPU_Speed_Limit = 250', []],
        'the line is missing' => ['', []],
    ]);

    it('flags Low Power Mode only when it is on', function (string $line, array $expected) {
        expect(slowTitles(slowDoc([SLOW_MAC_PERFORMANCE => $line])))->toBe($expected);
    })->with([
        'on' => [' lowpowermode         1', ['Low Power Mode is on']],
        'off' => [' lowpowermode         0', []],
        'not a number' => [' lowpowermode         maybe', []],
    ]);

    it('flags busy Spotlight indexing and ignores a quiet one', function (string $body, array $expected) {
        expect(slowTitles(slowDoc([SLOW_MAC_SPOTLIGHT => $body])))->toBe($expected);
    })->with([
        'busy' => ["/:\n\tIndexing enabled. \n  345  35.5 mds_stores\n  346   1.0 mdworker_shared", ['The Mac is busy cataloguing its files']],
        'a helper is busy' => ["/:\n\tIndexing enabled. \n  346  25.0 mdworker_shared", ['The Mac is busy cataloguing its files']],
        'quiet' => ["/:\n\tIndexing enabled. \n  345   1.0 mds_stores", []],
        'exactly at the limit' => ["/:\n\tIndexing enabled. \n  345  20.0 mds_stores", ['The Mac is busy cataloguing its files']],
        'no processes listed' => ["/:\n\tIndexing enabled. ", []],
        'indexing is off' => ["/:\n\tIndexing disabled.", []],
        'a command that failed' => ['mdutil: unknown error', []],
        'a different program' => ["/:\n\tIndexing enabled. \n  345  90.0 Safari", []],
    ]);
});

describe('a real scan', function () {
    it('finds nothing slowing down the sample Mac', function () {
        expect(slowTitles(macReportFixture()))->toBe([])
            ->and(slowVerdict(macReportFixture()))->toBe('Nothing obviously slowing it down');
    });

    it('finds nothing slowing down the sample Windows laptop', function () {
        expect(slowTitles(windowsLaptopFixture()))->toBe([])
            ->and(slowVerdict(windowsLaptopFixture()))->toBe('Nothing obviously slowing it down');
    });
});
