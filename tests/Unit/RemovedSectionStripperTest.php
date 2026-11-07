<?php

declare(strict_types=1);

use App\Services\Reports\RemovedSectionStripper;

function rssSection(string $title, string $body): string
{
    return "\n## {$title}\n\n```\n{$body}\n```\n";
}

function rssReport(string ...$sections): string
{
    return "# Mac checkup: Test\n\n- Format: 1\n- OS: macOS\n\n## Things to look at\n\n- Nothing flagged automatically.\n".implode('', $sections);
}

/**
 * @return array{markdown: string, removed: list<string>, kept: list<string>}
 */
function rssStrip(string $markdown): array
{
    return (new RemovedSectionStripper)->strip($markdown);
}

function rssOldTrust(): string
{
    return "-- admin\nCert 0: Contoso Inspection Root CA\n-- system\nSecTrustSettingsCopyCertificates: No Trust Settings were found.\n-- user\nSecTrustSettingsCopyCertificates: No Trust Settings were found.";
}

describe('the sections it cuts', function () {
    it('cuts a removed section from its heading to the next heading and keeps every other byte', function (string $title) {
        $before = rssReport(rssSection('Disk usage', '/dev/disk1s1  466Gi  344Gi  107Gi  77%  /'));
        $after = rssSection('Memory pressure', 'System-wide memory free percentage: 47%');

        expect(rssStrip($before.rssSection($title, "private line 1\nprivate line 2").$after))
            ->toBe(['markdown' => $before.$after, 'removed' => [$title], 'kept' => []]);
    })->with([
        'raw Wi-Fi dump' => 'Wi-Fi',
        'raw DNS and proxy' => 'DNS and proxy',
        'raw hosts file' => 'Hosts file (non-default lines)',
        'privacy permissions' => 'Privacy permissions (TCC), needs Full Disk Access for Terminal',
        'System keychain' => 'Certificates in System keychain',
        'saved networks and VPNs' => 'Saved Wi-Fi networks and VPNs',
    ]);

    it('cuts the old trusted certificates section that listed names', function (string $body) {
        $before = rssReport(rssSection('Time Machine', 'No destinations configured.'));
        $after = rssSection('Browser extensions: Safari', 'none');

        expect(rssStrip($before.rssSection('Trusted certificates: custom trust settings', $body).$after))
            ->toBe(['markdown' => $before.$after, 'removed' => ['Trusted certificates: custom trust settings'], 'kept' => []]);
    })->with([
        'with a certificate' => rssOldTrust(),
        'with none' => "-- admin\nSecTrustSettingsCopyCertificates: No Trust Settings were found.\n-- system\nSecTrustSettingsCopyCertificates: No Trust Settings were found.\n-- user\nSecTrustSettingsCopyCertificates: No Trust Settings were found.",
        'after a locale warning from bash' => "bash: warning: setlocale: LC_ALL: cannot change locale (nl_NL.UTF-8)\n".rssOldTrust(),
    ]);

    it('cuts every old section of one report, in the order they were written', function () {
        $kept = [
            rssSection('Wi-Fi signal', "format: 1\nsignal-dbm: -58\nband: 5 GHz"),
            rssSection('Remote access tools', "format: 1\nscan: complete"),
            rssSection('Network settings', "format: 1\nhosts: complete\nhosts-entries: 2\nproxy: off"),
            rssSection('Reboot history and boot time', 'kern.boottime: { sec = 1790243460, usec = 412001 }'),
        ];
        $markdown = rssReport(
            rssSection('Wi-Fi', 'Other Local Wi-Fi Networks:'),
            $kept[0],
            rssSection('DNS and proxy', 'nameserver[0] : 10.0.0.1'),
            rssSection('Hosts file (non-default lines)', '10.0.0.5 nas.home'),
            $kept[1],
            $kept[2],
            rssSection('Privacy permissions (TCC), needs Full Disk Access for Terminal', 'kTCCServiceCamera|us.zoom.xos|allowed'),
            rssSection('Trusted certificates: custom trust settings', rssOldTrust()),
            rssSection('Certificates in System keychain', '    "labl"<blob>="Contoso Root"'),
            rssSection('Saved Wi-Fi networks and VPNs', "Preferred networks on en0:\n\tHome"),
            $kept[3],
        );

        expect(rssStrip($markdown))->toBe([
            'markdown' => rssReport(...$kept),
            'removed' => [
                'Wi-Fi',
                'DNS and proxy',
                'Hosts file (non-default lines)',
                'Privacy permissions (TCC), needs Full Disk Access for Terminal',
                'Trusted certificates: custom trust settings',
                'Certificates in System keychain',
                'Saved Wi-Fi networks and VPNs',
            ],
            'kept' => [],
        ]);
    });

    it('cuts a section that appears twice both times', function () {
        $markdown = rssReport(rssSection('Wi-Fi', 'one'), rssSection('Disk usage', 'disk'), rssSection('Wi-Fi', 'two'), rssSection('Bluetooth', 'bt'));

        expect(rssStrip($markdown))->toBe([
            'markdown' => rssReport(rssSection('Disk usage', 'disk'), rssSection('Bluetooth', 'bt')),
            'removed' => ['Wi-Fi', 'Wi-Fi'],
            'kept' => [],
        ]);
    });

    it('cuts a last section to the end of the report', function () {
        $before = rssReport(rssSection('Disk usage', 'disk'));

        expect(rssStrip($before.rssSection('Saved Wi-Fi networks and VPNs', "Preferred networks on en0:\n\tHome")))
            ->toBe(['markdown' => $before."\n", 'removed' => ['Saved Wi-Fi networks and VPNs'], 'kept' => []]);
    });

    it('cuts a section that a cut-off upload ended early', function (string $tail) {
        $before = rssReport(rssSection('Disk usage', 'disk'));

        expect(rssStrip($before."\n## Wi-Fi".$tail))
            ->toBe(['markdown' => $before."\n", 'removed' => ['Wi-Fi'], 'kept' => []]);
    })->with([
        'inside the fence' => ["\n\n```\nWi-Fi:\n      Current Network Information:\n        HomeNet"],
        'right after the fence opened' => ["\n\n```\n"],
        'right after the heading' => ["\n"],
        'on the heading' => [''],
    ]);

    it('cuts an empty section and one whose closing fence is indented, as the card parser reads them', function (string $body) {
        $before = rssReport(rssSection('Disk usage', 'disk'));
        $after = rssSection('Bluetooth', 'bt');

        expect(rssStrip($before."\n## Hosts file (non-default lines)\n\n".$body."\n".$after))
            ->toBe(['markdown' => $before.$after, 'removed' => ['Hosts file (non-default lines)'], 'kept' => []]);
    })->with([
        'empty fence' => ["```\n```\n"],
        'nothing to show' => ["```\n(nothing to show)\n```\n"],
        'indented closing fence' => ["```\n10.0.0.5 nas.home\n  ```\n"],
    ]);

    it('keeps Windows line endings and every other byte as they were', function () {
        $markdown = "# Windows checkup: PC\r\n\r\n## Disk usage\r\n\r\n```\r\nC: 22%\r\n```\r\n\r\n## Hosts file (non-default lines)\r\n\r\n```\r\n10.0.0.5 nas.home\r\n```\r\n\r\n## Wi-Fi signal\r\n\r\n```\r\nformat: 1\r\n```\r\n";

        expect(rssStrip($markdown))->toBe([
            'markdown' => "# Windows checkup: PC\r\n\r\n## Disk usage\r\n\r\n```\r\nC: 22%\r\n```\r\n\r\n## Wi-Fi signal\r\n\r\n```\r\nformat: 1\r\n```\r\n",
            'removed' => ['Hosts file (non-default lines)'],
            'kept' => [],
        ]);
    });

    it('leaves unicode, control characters and invalid bytes elsewhere untouched', function () {
        $odd = "Naïve café ✓ 日本語 \x07\x1b[0m \xff\xfe tab\there";
        $before = rssReport(rssSection('Login items', $odd));
        $after = rssSection('Cron jobs', $odd);

        expect(rssStrip($before.rssSection('Wi-Fi', "Ünïcödé-Net 📶\n\x00\x07").$after))
            ->toBe(['markdown' => $before.$after, 'removed' => ['Wi-Fi'], 'kept' => []]);
    });

    it('cuts a section as big as the largest upload quickly', function () {
        $before = rssReport(rssSection('Disk usage', 'disk'));
        $after = rssSection('Bluetooth', 'bt');
        $started = microtime(true);

        $result = rssStrip($before.rssSection('Wi-Fi', str_repeat("            Neighbour-Net:\n              Channel: 6\n", 40000)).$after);

        expect($result['markdown'])->toBe($before.$after)
            ->and(microtime(true) - $started)->toBeLessThan(5.0);
    });

    it('finds nothing more to cut the second time', function () {
        $once = rssStrip(rssReport(rssSection('Wi-Fi', 'x'), rssSection('Disk usage', 'disk'), rssSection('DNS and proxy', 'y')))['markdown'];

        expect(rssStrip($once))->toBe(['markdown' => $once, 'removed' => [], 'kept' => []]);
    });
});

describe('the sections it keeps', function () {
    it('keeps a section whose title only shares words with a removed one', function (string $title) {
        $markdown = rssReport(rssSection($title, "format: 1\nvalue: 1"), rssSection('Disk usage', 'disk'));

        expect(rssStrip($markdown))->toBe(['markdown' => $markdown, 'removed' => [], 'kept' => []]);
    })->with([
        'Wi-Fi signal (PCC-66)' => 'Wi-Fi signal',
        'Network settings (PCC-74)' => 'Network settings',
        'Remote access tools (PCC-74)' => 'Remote access tools',
        'Network interfaces' => 'Network interfaces',
        'other case' => 'wi-fi',
        'upper case' => 'WI-FI',
        'a longer title' => 'Wi-Fi networks',
        'a shorter title' => 'Hosts file',
        'DNS alone' => 'DNS',
        'saved networks without VPNs' => 'Saved Wi-Fi networks',
        'privacy permissions without the note' => 'Privacy permissions (TCC)',
        'keychain with more words' => 'Certificates in System keychain (summary)',
    ]);

    it('keeps the trusted certificates section of today, which holds only counts', function (string $body) {
        $markdown = rssReport(rssSection('Trusted certificates: custom trust settings', $body), rssSection('Browser extensions: Safari', 'none'));

        expect(rssStrip($markdown))->toBe(['markdown' => $markdown, 'removed' => [], 'kept' => []]);
    })->with([
        'counts' => "format: 1\nadmin: 2\nsystem: 0\nuser: 1",
        'none' => "format: 1\nadmin: 0\nsystem: 0\nuser: 0",
        'could not check' => "format: 1\nadmin: could not check\nsystem: could not check\nuser: could not check",
        'empty' => '',
        'a line that only mentions admin' => "format: 1\n--admin\nadmin -- 1",
    ]);

    it('ignores a heading that is not a level 2 heading at the start of a line', function (string $line) {
        $markdown = rssReport(rssSection('Disk usage', 'disk'))."\n{$line}\n\n```\nprivate\n```\n";

        expect(rssStrip($markdown))->toBe(['markdown' => $markdown, 'removed' => [], 'kept' => []]);
    })->with([
        'level 1' => '# Wi-Fi',
        'level 3' => '### Wi-Fi',
        'no space' => '##Wi-Fi',
        'indented' => ' ## Wi-Fi',
        'in a list' => '- ## Wi-Fi',
    ]);

    it('ignores a removed heading inside the code fence of another section', function () {
        $markdown = rssReport(rssSection('Login items', "## Wi-Fi\nHomeNet"), rssSection('Disk usage', 'disk'));

        expect(rssStrip($markdown))->toBe(['markdown' => $markdown, 'removed' => [], 'kept' => []]);
    });

    it('leaves a report without sections or without text alone', function (string $markdown) {
        expect(rssStrip($markdown))->toBe(['markdown' => $markdown, 'removed' => [], 'kept' => []]);
    })->with([
        'empty' => '',
        'only a header' => "# Mac checkup: Test\n\n- Format: 1\n",
        'only the flags' => rssReport(),
        'only a line break' => "\n",
    ]);

    it('keeps a section, and says so, when a line inside its fence starts a heading', function (string $body) {
        $markdown = rssReport(rssSection('Saved Wi-Fi networks and VPNs', $body), rssSection('Disk usage', 'disk'), rssSection('Bluetooth', 'bt'));

        expect(rssStrip($markdown))->toBe(['markdown' => $markdown, 'removed' => [], 'kept' => ['Saved Wi-Fi networks and VPNs']]);
    })->with([
        'a network named like a fence' => "Preferred networks on en0:\n\t```evil\n\tHome",
        'a line that looks like a heading' => "Preferred networks on en0:\n## Things to look at\n- Not a flag",
        'two lines like fences around one like a heading' => "Preferred networks on en0:\n```odd\n## Fake heading\n```odd\n\tHome",
        'a line exactly like a fence' => "Preferred networks on en0:\n```\n\tHome",
    ]);

    it('keeps a section, and says so, when its text is not fenced the way the scripts write it', function (string $body) {
        $markdown = rssReport(rssSection('Disk usage', 'disk'))."\n## Wi-Fi\n\n{$body}\n".rssSection('Bluetooth', 'bt');

        expect(rssStrip($markdown))->toBe(['markdown' => $markdown, 'removed' => [], 'kept' => ['Wi-Fi']]);
    })->with([
        'no fence' => ["HomeNet\n"],
        'text before the fence' => ["HomeNet\n```\nNeighbour-Net\n```\n"],
        'text after the closing fence' => ["```\nHomeNet\n```\nNeighbour-Net\n"],
        'a fence with a language' => ["```text\nHomeNet\n```\n"],
    ]);

    it('still cuts the other sections of a report when it keeps one', function () {
        $markdown = rssReport(rssSection('Wi-Fi', 'x'), rssSection('Hosts file (non-default lines)', '```odd'), rssSection('Disk usage', 'disk'));

        expect(rssStrip($markdown))->toBe([
            'markdown' => rssReport(rssSection('Hosts file (non-default lines)', '```odd'), rssSection('Disk usage', 'disk')),
            'removed' => ['Wi-Fi'],
            'kept' => ['Hosts file (non-default lines)'],
        ]);
    });
});
