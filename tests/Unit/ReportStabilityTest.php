<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

function stabilityDoc(string $generated, string ...$blocks): string
{
    return "# Report\n\n- Format: 1\n- Generated: {$generated}\n\n".implode("\n", $blocks);
}

function stabilityBlock(string $title, string $body): string
{
    return "## {$title}\n\n```\n{$body}\n```\n";
}

/**
 * @param  list<string>  $flags
 * @return array<string, array{value: string, tone: string}>
 */
function stabilityRows(string $markdown, array $flags = []): array
{
    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, $flags, 'laptop') as $card) {
        if ($card['id'] === 'stability') {
            $rows = [];

            foreach ($card['rows'] as $row) {
                $rows[$row['label']] = ['value' => $row['value'], 'tone' => $row['tone']];
            }

            return $rows;
        }
    }

    return [];
}

function macStability(string $title, string $body): array
{
    return stabilityRows(stabilityDoc('Tue Nov  3 09:12:44 CET 2026', stabilityBlock($title, $body)));
}

describe('a Mac', function () {
    it('summarises the sample report', function () {
        expect(stabilityRows(macReportFixture()))->toBe([
            'Kernel panics' => ['value' => '2', 'tone' => 'bad'],
            'Latest kernel panic' => ['value' => '30 October 2026', 'tone' => 'neutral'],
            'Most recent shutdown' => ['value' => 'Unexpected shutdown (code -62)', 'tone' => 'bad'],
            'Last restart' => ['value' => '31 October 2026, 09:12', 'tone' => 'neutral'],
            'Uptime at the scan' => ['value' => '3 days', 'tone' => 'neutral'],
            'Login items' => ['value' => '2', 'tone' => 'neutral'],
            'Background startup items' => ['value' => '4', 'tone' => 'neutral'],
        ]);
    });

    it('counts kernel panics once however many times they are listed', function () {
        $body = "-rw-r--r--  1 root  wheel  10 Nov  1 08:00 /Library/Logs/DiagnosticReports/Kernel_2026-11-01-080000_h.panic\n"
            ."total 8\n-rw-r--r--  1 root  wheel  10 Nov  1 08:00 Kernel_2026-11-01-080000_h.panic\n-rw-r--r--  1 root  admin  10 Nov  1 08:00 Other.crash";
        $rows = macStability('Kernel panic reports', $body);

        expect($rows['Kernel panics'])->toBe(['value' => '1', 'tone' => 'bad'])
            ->and($rows['Latest kernel panic']['value'])->toBe('1 November 2026');
    });

    it('says none were found only when the folder was listed', function () {
        $listed = macStability('Kernel panic reports', "ls: /Library/Logs/DiagnosticReports/*.panic: No such file or directory\ntotal 8\n-rw-r--r--  1 root  admin  10 Nov  1 08:00 Other.crash");
        $unknown = macStability('Kernel panic reports', 'ls: /Library/Logs/DiagnosticReports/*.panic: No such file or directory');

        expect($listed)->toBe(['Kernel panics' => ['value' => 'None found', 'tone' => 'good']])
            ->and($unknown)->toBe([]);
    });

    it('caps a long list of panics', function () {
        $lines = collect(range(10, 19))->map(fn (int $day): string => "-rw-r--r--  1 root  wheel  10 Oct {$day} 08:00 Kernel_2026-10-{$day}-080000_h.panic")->implode("\n");

        expect(macStability('Kernel panic reports', "total 80\n{$lines}")['Kernel panics']['value'])->toBe('10 or more');
    });

    it('shows a panic without a date in its name but no date row', function () {
        $rows = macStability('Kernel panic reports', "total 8\n-rw-r--r--  1 root  wheel  10 Nov  1 08:00 mystery.panic");

        expect($rows)->toBe(['Kernel panics' => ['value' => '1', 'tone' => 'bad']]);
    });

    it('translates only the shutdown codes it knows', function (string $code, string $value, string $tone) {
        $rows = macStability('Recent shutdown causes', "2026-11-01 08:00:00.1+0100 0x0 Default 0x0 0 0 kernel: (AppleSMC) Previous shutdown cause: {$code}");

        expect($rows['Most recent shutdown'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'normal' => ['5', 'Normal shutdown', 'good'],
        'documented bad' => ['-60', 'Unexpected shutdown (code -60)', 'bad'],
        'documented bad at the end' => ['-130', 'Unexpected shutdown (code -130)', 'bad'],
        'documented bad in the middle' => ['-86', 'Unexpected shutdown (code -86)', 'bad'],
        'negative but not documented' => ['-68', 'Code -68', 'neutral'],
        'unknown positive' => ['3', 'Code 3', 'neutral'],
        'zero' => ['0', 'Code 0', 'neutral'],
        'beyond the documented range' => ['-131', 'Code -131', 'neutral'],
    ]);

    it('uses the last shutdown line, which is the most recent', function () {
        $body = "Previous shutdown cause: -62\nPrevious shutdown cause: 5\n\n(last 7 days, capped at 20 seconds)";

        expect(macStability('Recent shutdown causes', $body)['Most recent shutdown']['value'])->toBe('Normal shutdown');
    });

    it('leaves out the shutdown row when the log held nothing', function (string $body) {
        expect(macStability('Recent shutdown causes', $body))->toBe([]);
    })->with([
        'only the note' => ['(last 7 days, capped at 20 seconds)'],
        'a huge code' => ['Previous shutdown cause: 123456789012345678901234567890'],
        'text instead of a number' => ['Previous shutdown cause: banana'],
        'empty' => [''],
    ]);

    it('reads the boot time and works out the uptime', function (string $generated, string $value) {
        $rows = stabilityRows(stabilityDoc($generated, stabilityBlock('Reboot history and boot time', 'kern.boottime: { sec = 1793434364, usec = 5 } Sat Oct 31 09:12:44 2026')));

        expect($rows['Last restart']['value'])->toBe('31 October 2026, 09:12')
            ->and($rows['Uptime at the scan']['value'] ?? null)->toBe($value);
    })->with([
        'minutes' => ['Sat Oct 31 08:42:44 UTC 2026', '30 minutes'],
        'a day' => ['Sun Nov  1 08:12:44 UTC 2026', '1 day'],
        'hours' => ['Sat Oct 31 13:12:44 UTC 2026', '5 hours'],
        'a week' => ['Sat Nov  7 08:12:44 UTC 2026', '7 days'],
    ]);

    it('shows no uptime when the scan date is unusable', function (string $generated) {
        $rows = stabilityRows(stabilityDoc($generated, stabilityBlock('Reboot history and boot time', 'kern.boottime: { sec = 1793434364, usec = 5 } Sat Oct 31 09:12:44 2026')));

        expect($rows)->toHaveKey('Last restart')->not->toHaveKey('Uptime at the scan');
    })->with([
        'garbage' => ['banana'],
        'before the boot' => ['Fri Oct 30 09:12:44 CET 2026'],
        'an hour of nothing' => ['Sat Oct 31 08:12:44 UTC 2026'],
    ]);

    it('shows no restart row for a boot line it cannot read', function (string $body) {
        expect(macStability('Reboot history and boot time', $body))->toBe([]);
    })->with([
        'an impossible date' => ['kern.boottime: { sec = 1, usec = 5 } Sat Feb 31 25:99:00 2026'],
        'other text' => ['reboot    ~     Sat Oct 31 09:12'],
    ]);

    it('works out the uptime without the date text', function () {
        $rows = macStability('Reboot history and boot time', 'kern.boottime: { sec = 1793434364, usec = 5 }');

        expect($rows)->toBe(['Uptime at the scan' => ['value' => '3 days', 'tone' => 'neutral']]);
    });

    it('counts login items by name', function (string $body, ?string $value) {
        $rows = macStability('Login items', $body);

        expect($rows['Login items']['value'] ?? null)->toBe($value);
    })->with([
        'one' => ['Dropbox', '1'],
        'several' => ['Dropbox, Slack,  Zoom', '3'],
        'none' => ['', '0'],
        'stray commas' => [', ,Dropbox,', '1'],
        'permission error' => ['System Events got an error: Not authorized to send Apple events. (-1743)', null],
        'execution error' => ['execution error: bad thing', null],
    ]);

    it('prefers the count line for startup items', function (string $performance, string $startup, ?string $value) {
        $blocks = array_filter([
            $performance === '' ? null : stabilityBlock('Performance: login item and startup counts', $performance),
            $startup === '' ? null : stabilityBlock('Startup items: LaunchAgents and LaunchDaemons', $startup),
        ]);
        $rows = stabilityRows(stabilityDoc('Tue Nov  3 09:12:44 CET 2026', ...$blocks));

        expect($rows['Background startup items']['value'] ?? null)->toBe($value);
    })->with([
        'count line' => ["LaunchAgents/Daemons:\n7", "/Library/LaunchAgents:\na.plist", '7'],
        'count line only' => ["LaunchAgents/Daemons:\n0", '', '0'],
        'count line is not a number' => ["LaunchAgents/Daemons:\nmany", "/Library/LaunchAgents:\na.plist\nb.plist\n\n/Library/LaunchDaemons:\nc.plist", '3'],
        'count line is huge' => ["LaunchAgents/Daemons:\n99999999999999999999", "/Library/LaunchAgents:\na.plist", '1'],
        'no count line, lines are counted' => ['', "/Library/LaunchAgents:\na.plist\nb.plist\n\n/Library/LaunchDaemons:\nc.plist\nls: nope", '3'],
        'only headers' => ['', "/Library/LaunchAgents:\n\n/Library/LaunchDaemons:", '0'],
        'nothing at all' => ['', '', null],
    ]);

    it('shows no card for sections with nothing readable', function () {
        $markdown = stabilityDoc('x', stabilityBlock('Kernel panic reports', ''), stabilityBlock('Startup items: LaunchAgents and LaunchDaemons', ''));

        expect(stabilityRows($markdown))->toBe([]);
    });

    it('survives Windows line endings', function () {
        expect(stabilityRows(str_replace("\n", "\r\n", macReportFixture())))->toBe(stabilityRows(macReportFixture()));
    });

    it('does not read a section heading inside a code fence', function () {
        $body = "total 8\n## Login items\nDropbox";

        expect(macStability('Kernel panic reports', $body))->toBe(['Kernel panics' => ['value' => 'None found', 'tone' => 'good']]);
    });
});

describe('a Windows PC', function () {
    $crashTitle = 'Crashes and unexpected shutdowns (last 30 days)';

    it('summarises the sample report', function () {
        $rows = stabilityRows(windowsLaptopFixture(), ['Up for 41 days since the last full boot (Fast Startup can inflate this).']);

        expect($rows)->toBe([
            'Blue screen crashes' => ['value' => '1', 'tone' => 'bad'],
            'Unexpected shutdowns' => ['value' => '2', 'tone' => 'bad'],
            'Up since the last full boot' => ['value' => '41 days', 'tone' => 'neutral'],
            'Startup programs' => ['value' => '3', 'tone' => 'neutral'],
        ]);
    });

    it('says none only when the event section was read', function () use ($crashTitle) {
        $rows = stabilityRows(stabilityDoc('x', stabilityBlock($crashTitle, '(none)')));

        expect($rows)->toBe([
            'Blue screen crashes' => ['value' => 'None in 30 days', 'tone' => 'good'],
            'Unexpected shutdowns' => ['value' => 'None in 30 days', 'tone' => 'good'],
        ]);
    });

    it('shows no crash rows when the events could not be read', function (string $body) use ($crashTitle) {
        expect(stabilityRows(stabilityDoc('x', stabilityBlock($crashTitle, $body))))->toBe([]);
    })->with([
        'unavailable' => ['unavailable (needs admin)'],
        'nothing to show' => ['(nothing to show)'],
        'rows that are not events' => ["TimeCreated Id ProviderName\n11/1/2026 41x Foo"],
    ]);

    it('counts events from a table in any date format', function () use ($crashTitle) {
        $body = "TimeCreated Id ProviderName\n01-11-2026 03:12:00  41 Microsoft-Windows-Kernel-Power\n2026/11/01 03:13:00 6008 EventLog\n2026/11/01 03:14:00 1001 Microsoft-Windows-WER-SystemErrorReporting\n2026/11/01 03:15:00 1001 Windows Error Reporting";
        $rows = stabilityRows(stabilityDoc('x', stabilityBlock($crashTitle, $body)));

        expect($rows['Blue screen crashes']['value'])->toBe('1')
            ->and($rows['Unexpected shutdowns']['value'])->toBe('1');
    });

    it('falls back to the 6008 events when there are no Kernel-Power ones', function () use ($crashTitle) {
        $body = "01-11-2026 03:13:00 6008 EventLog\n01-11-2026 03:14:00 6008 EventLog";
        $rows = stabilityRows(stabilityDoc('x', stabilityBlock($crashTitle, $body)));

        expect($rows['Unexpected shutdowns']['value'])->toBe('2');
    });

    it('marks a capped table', function () use ($crashTitle) {
        $body = implode("\n", array_fill(0, 15, '11/1/2026 3:12:00 AM  41 Microsoft-Windows-Kernel-Power'));
        $rows = stabilityRows(stabilityDoc('x', stabilityBlock($crashTitle, $body)));

        expect($rows['Unexpected shutdowns']['value'])->toBe('15 or more')
            ->and($rows)->not->toHaveKey('Blue screen crashes');
    });

    it('prefers the exact count from the flag over the capped table', function () use ($crashTitle) {
        $body = implode("\n", array_fill(0, 15, '11/1/2026 3:12:00 AM  41 Microsoft-Windows-Kernel-Power'));
        $flags = ['27 unexpected shutdown(s) in the last 30 days (crash, power loss or forced power off).'];
        $rows = stabilityRows(stabilityDoc('x', stabilityBlock($crashTitle, $body)), $flags);

        expect($rows['Unexpected shutdowns']['value'])->toBe('27');
    });

    it('reads the count from the flag when the section is missing', function () {
        $rows = stabilityRows("# Report\n", ['10 unexpected shutdown(s) in the last 30 days (crash, power loss or forced power off).']);

        expect($rows)->toBe(['Unexpected shutdowns' => ['value' => '10', 'tone' => 'bad']]);
    });

    it('reads the startup count', function (string $body, ?string $value) {
        $rows = stabilityRows(stabilityDoc('x', stabilityBlock('Startup programs', $body)));

        expect($rows['Startup programs']['value'] ?? null)->toBe($value);
    })->with([
        'a count' => ["Count: 12\nName Command", '12'],
        'zero' => ['Count: 0', '0'],
        'text' => ['Count: lots', null],
        'huge' => ['Count: 999999999999', null],
        'unavailable' => ['unavailable (denied)', null],
    ]);

    it('says a single day in the singular and ignores nonsense uptime flags', function (string $flag, ?string $value) {
        $rows = stabilityRows("# Report\n", [$flag]);

        expect($rows['Up since the last full boot']['value'] ?? null)->toBe($value);
    })->with([
        'one day' => ['Up for 1 days since the last full boot (x).', '1 day'],
        'a number' => ['Up for 90 days since the last full boot (x).', '90 days'],
        'not a number' => ['Up for lots days since the last full boot (x).', null],
        'huge' => ['Up for 99999999999 days since the last full boot (x).', null],
    ]);
});

it('has no card for a report without any stability data', function (string $markdown) {
    expect(stabilityRows($markdown))->toBe([]);
})->with([
    'empty' => [''],
    'a title only' => ["# Report\n"],
    'an old script' => ["# Mac checkup: Old\n\n- Format: 1\n- OS: macOS\n- Free disk: 30%\n"],
]);

it('never turns report text into values', function () {
    $rows = macStability('Login items', '<script>alert(1)</script>, <b>x</b>');
    $encoded = (string) json_encode($rows);

    expect($rows['Login items']['value'])->toBe('2')
        ->and($encoded)->not->toContain('<')->not->toContain('undefined')->not->toContain('NaN');
});
