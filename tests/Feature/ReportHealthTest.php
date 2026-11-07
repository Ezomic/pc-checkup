<?php

declare(strict_types=1);

use App\Enums\CheckupScript;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportHealth;
use App\Services\Reports\ScriptLayout;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

const HEALTH_CUT = 'The report stops early: its last part is missing, so the scan or the upload was probably cut off.';

const HEALTH_MISSING = 'These sections are missing, although this version of the script always writes them: ';

const HEALTH_CHECKLIST = 'Manual checklist (cannot be done by script)';

/**
 * A report laid out the way the script writes one: the header, the flags, a fenced section per
 * title and the manual checklist at the end.
 *
 * @param  list<string>|null  $titles  Null writes every section the current script writes.
 * @param  array<string, string>  $bodies  Section text by title, for the ones that need more than a line.
 */
function healthReport(CheckupScript $script, ?string $version = 'current', ?array $titles = null, bool $end = true, bool $flags = true, array $bodies = [], ?string $os = null): string
{
    $lines = ['# Checkup: Box', '', '- Format: 1'];

    if ($version !== null) {
        $lines[] = '- Script version: '.($version === 'current' ? $script->version() : $version);
    }

    array_push($lines, '- OS: '.($os ?? ($script === CheckupScript::Mac ? 'macOS' : 'Windows')), '- Serial: ABC123', '');

    if ($flags) {
        array_push($lines, '## Things to look at', '', '- Nothing flagged automatically.');
    }

    foreach ($titles ?? ScriptLayout::of($script)->sections as $title) {
        array_push($lines, '', "## {$title}", '', '```', $bodies[$title] ?? 'read fine', '```');
    }

    if ($end) {
        array_push($lines, '', '## '.HEALTH_CHECKLIST, '', 'Hardware', '- [ ] Keyboard (every key).');
    }

    return implode("\n", $lines)."\n";
}

/**
 * @param  list<string>  $failed
 * @return list<string>
 */
function healthProblems(string $markdown, array $failed = []): array
{
    return (new ReportHealth)->problems($markdown, $failed);
}

/**
 * @return list<string>
 */
function healthWithout(CheckupScript $script, string ...$gone): array
{
    return array_values(array_diff(ScriptLayout::of($script)->sections, $gone));
}

describe('a report from the current script', function () {
    it('shows nothing when every section is there', function (CheckupScript $script) {
        expect(healthProblems(healthReport($script)))->toBe([]);
    })->with([CheckupScript::Mac, CheckupScript::Windows]);

    it('names a section that is missing', function (CheckupScript $script) {
        expect(healthProblems(healthReport($script, titles: healthWithout($script, 'Wi-Fi signal'))))
            ->toBe([HEALTH_MISSING.'Wi-Fi signal.']);
    })->with([CheckupScript::Mac, CheckupScript::Windows]);

    it('expects the list of things to look at too, because the flags are read from it', function () {
        expect(healthProblems(healthReport(CheckupScript::Windows, flags: false)))->toBe([HEALTH_MISSING.'Things to look at.']);
    });

    it('names five missing sections in script order and counts the rest', function () {
        $sections = ScriptLayout::of(CheckupScript::Mac)->sections;
        $kept = array_slice($sections, 0, -8);
        $gone = array_slice($sections, -8, 5);

        expect(healthProblems(healthReport(CheckupScript::Mac, titles: $kept)))
            ->toBe([HEALTH_MISSING.implode(', ', $gone).' and 3 more.']);
    });

    it('matches a heading written in another case', function () {
        $titles = array_map(fn (string $title): string => $title === 'Boot time' ? 'BOOT TIME' : $title, ScriptLayout::of(CheckupScript::Windows)->sections);

        expect(healthProblems(healthReport(CheckupScript::Windows, titles: $titles)))->toBe([]);
    });

    it('does not count a heading that only appears inside a code block', function () {
        $markdown = healthReport(CheckupScript::Windows, titles: healthWithout(CheckupScript::Windows, 'Boot time'), bodies: ['Graphics' => "## Boot time\nboot-latest: 2026-11-01"]);

        expect(healthProblems($markdown))->toBe([HEALTH_MISSING.'Boot time.']);
    });

    it('sees the section an unclosed code block swallowed, and the end it swallowed', function () {
        $sections = ScriptLayout::of(CheckupScript::Mac)->sections;
        [$before, $last] = array_slice($sections, -2);
        $markdown = healthReport(CheckupScript::Mac, bodies: [$before => "Sat Nov  7\n```"]);

        expect(healthProblems($markdown))->toBe([HEALTH_CUT, HEALTH_MISSING.$last.'.']);
    });

    it('says it was cut off and names what is missing when the report stops early', function () {
        $sections = ScriptLayout::of(CheckupScript::Windows)->sections;

        expect(healthProblems(healthReport(CheckupScript::Windows, titles: array_slice($sections, 0, -2), end: false)))
            ->toBe([HEALTH_CUT, HEALTH_MISSING.implode(', ', array_slice($sections, -2)).'.']);
    });

    it('needs no fix log or benchmark, which only some runs write, and does not mind extra sections', function () {
        $titles = [...ScriptLayout::of(CheckupScript::Mac)->sections, 'Fix log', 'Benchmark', 'Something new'];

        expect(healthProblems(healthReport(CheckupScript::Mac, titles: $titles)))->toBe([]);
    });

    it('reads a report stored with Windows line endings', function () {
        expect(healthProblems(str_replace("\n", "\r\n", healthReport(CheckupScript::Windows))))->toBe([]);
    });
});

describe('a report from another version of the script', function () {
    it('is never said to miss sections, because older scripts wrote fewer', function (string $version) {
        expect(healthProblems(healthReport(CheckupScript::Mac, version: $version, titles: ['Hardware overview'])))->toBe([]);
    })->with(['00000000', 'ffffffff', '2099-01-01.9']);

    it('is still said to be cut off when it stops early', function (CheckupScript $script) {
        expect(healthProblems(healthReport($script, version: '00000000', titles: ['Hardware overview'], end: false)))->toBe([HEALTH_CUT]);
    })->with([CheckupScript::Mac, CheckupScript::Windows]);
});

describe('a report without a script version', function () {
    it('is not judged on its sections or its end, because it may be an old scan or a pasted part', function () {
        expect(healthProblems(healthReport(CheckupScript::Mac, version: null, titles: [], end: false, flags: false)))->toBe([]);
    });

    it('still has its format lines checked', function () {
        $markdown = healthReport(CheckupScript::Windows, version: null, titles: ['Wi-Fi signal'], bodies: ['Wi-Fi signal' => "format: 9\nstatus: complete"]);

        expect(healthProblems($markdown))->toBe(['The Wi-Fi signal section is in a format this app does not know (format: 9), so what the page shows about it may be wrong.']);
    });
});

describe('a report from a system without a script', function () {
    it('is not judged on its structure at all', function (?string $os) {
        $markdown = healthReport(CheckupScript::Mac, titles: ['Wi-Fi signal'], end: false, bodies: ['Wi-Fi signal' => 'format: 9'], os: $os);

        expect(healthProblems($markdown))->toBe([]);
    })->with(['Linux', 'Macrium Reflect', '']);

    it('has nothing to say about an empty report or one without a header', function (string $markdown) {
        expect(healthProblems($markdown))->toBe([]);
    })->with(['', "\n\n", "# A report\n\nNo header at all.\n", "## Wi-Fi signal\n\n```\nformat: 9\n```\n"]);

    it('still lists the cards that could not be read', function () {
        expect(healthProblems("# A report\n", ['Startup items']))
            ->toBe(['The Startup items card could not be read from this report, so it is left out. The error was logged.']);
    });
});

describe('format lines', function () {
    it('accepts the formats the script writes', function (string $line) {
        $markdown = healthReport(CheckupScript::Mac, bodies: ['Storage breakdown' => "root|Users|4\nscan: complete\n{$line}", 'Wi-Fi signal' => "format: 1\nstatus: complete"]);

        expect(healthProblems($markdown))->toBe([]);
    })->with(['format: 1', 'format: 2', 'format:2', 'format: 2   ', "format:\t2", 'format: 01']);

    it('names a format the app does not know, wherever the line is in the section', function (string $line, string $shown) {
        $markdown = healthReport(CheckupScript::Mac, bodies: ['Storage breakdown' => "root|Users|4\nscan: complete\n{$line}\nhome|1968"]);

        expect(healthProblems($markdown))->toBe(["The Storage breakdown section is in a format this app does not know ({$shown}), so what the page shows about it may be wrong."]);
    })->with([
        'newer than the script' => ['format: 3', 'format: 3'],
        'zero' => ['format: 0', 'format: 0'],
        'negative' => ['format: -1', 'format: -1'],
        'a word' => ['format: abc', 'format: abc'],
        'empty' => ['format:', 'no number'],
        'blank' => ['format:    ', 'no number'],
        'a fraction' => ['format: 1.5', 'format: 1.5'],
        'a decimal comma' => ['format: 1,0', 'format: 1,0'],
        'a number and a word' => ['format: 2 beta', 'format: 2 beta'],
        'arabic digits' => ['format: ٣', 'format: ٣'],
        'too many digits' => ['format: 1234', 'format: 1234'],
        'very long' => ['format: '.str_repeat('9', 5000), 'format: '.str_repeat('9', 20).'...'],
        'html' => ['format: <b>9</b>', 'format: <b>9</b>'],
        'control characters' => ["format: 9\x07\x1b[31m", 'format: 9[31m'],
        'a direction override and zero-width characters' => ["format: \u{202E}9\u{200B}1\u{FEFF}", 'format: 91'],
        'only invisible characters' => ["format: \u{200B}\u{202E}", 'no number'],
    ]);

    it('takes direction overrides and invisible characters out of a section title it names', function () {
        $title = "Wi-Fi \u{202E}langis\u{200B}";
        $markdown = healthReport(CheckupScript::Mac, version: '00000000', titles: [$title], bodies: [$title => 'format: 5']);

        expect(healthProblems($markdown))->toBe(['The Wi-Fi langis section is in a format this app does not know (format: 5), so what the page shows about it may be wrong.']);
    });

    it('ignores lines that only look like a format line', function (string $line) {
        $markdown = healthReport(CheckupScript::Mac, bodies: ['Storage (boot volume)' => "Device Node: /dev/disk3s1s1\n{$line}"]);

        expect(healthProblems($markdown))->toBe([]);
    })->with(['Format: APFS', '   format: 9', 'Volume format: 9', 'format 9']);

    it('only reads the first format line of a section', function () {
        $markdown = healthReport(CheckupScript::Windows, bodies: ['Network settings' => "format: 1\nhosts: complete\nformat: 7"]);

        expect(healthProblems($markdown))->toBe([]);
    });

    it('checks sections the current script no longer writes, and cuts a long title short', function () {
        $title = 'Old section '.str_repeat('x', 200);
        $markdown = healthReport(CheckupScript::Mac, version: '00000000', titles: [$title], bodies: [$title => 'format: 4']);

        expect(healthProblems($markdown))->toBe(['The Old section '.str_repeat('x', 68).'... section is in a format this app does not know (format: 4), so what the page shows about it may be wrong.']);
    });

    it('takes the header format line for what it is, not for a section', function () {
        expect(healthProblems(str_replace('- Format: 1', "- Format: 9\nformat: 9", healthReport(CheckupScript::Mac))))->toBe([]);
    });
});

describe('cards that could not be read', function () {
    it('are listed after what is wrong with the report itself', function () {
        $markdown = healthReport(CheckupScript::Mac, version: '00000000', end: false);

        expect(healthProblems($markdown, ['Startup items', 'Worth keeping?']))->toBe([
            HEALTH_CUT,
            'The Startup items card could not be read from this report, so it is left out. The error was logged.',
            'The Worth keeping? card could not be read from this report, so it is left out. The error was logged.',
        ]);
    });
});

describe('a section another change adds to a script', function () {
    it('is expected in reports from that script version without any change here', function (CheckupScript $script, string $call) {
        $realBase = base_path();
        $before = $script->version();
        $base = ScriptTemp::dir('pcc-layout');
        File::copyDirectory($realBase.'/scripts', $base.'/scripts');
        $path = $base.'/scripts/'.$script->filename();
        $sections = ScriptLayout::of($script)->sections;
        $last = preg_quote((string) end($sections), '/');
        $pattern = $script === CheckupScript::Mac ? "/^((?:sh_)?section \"{$last}\")/m" : "/^(Add-Section '{$last}')/m";
        file_put_contents($path, preg_replace($pattern, "{$call}\n$1", (string) file_get_contents($path), -1, $count));

        try {
            $this->app->setBasePath($base);
            $layout = ScriptLayout::of($script);

            expect($count)->toBe(1)
                ->and($layout->sections)->toContain('Parts and upgrades')
                ->and($layout->version)->not->toBe($before)
                ->and(healthProblems(healthReport($script, titles: healthWithout($script, 'Parts and upgrades'))))->toBe([HEALTH_MISSING.'Parts and upgrades.'])
                ->and(healthProblems(healthReport($script)))->toBe([]);
        } finally {
            $this->app->setBasePath($realBase);
        }
    })->with([
        'Mac' => [CheckupScript::Mac, 'section "Parts and upgrades" pcc_parts_report'],
        'Windows' => [CheckupScript::Windows, "Add-Section 'Parts and upgrades' { Get-PartsLines }"],
    ]);
});

describe('the reports we hold as fixtures', function () {
    it('look fine unless they really stop early', function (string $file, array $expected) {
        $markdown = (string) file_get_contents(__DIR__."/../Fixtures/{$file}");
        $built = (new ReportDetails(new ReportFacts))->build($markdown, [], 'laptop');

        expect($built['failed'])->toBe([])
            ->and(healthProblems($markdown, $built['failed']))->toBe($expected);
    })->with(function (): array {
        $cases = [];

        foreach (glob(__DIR__.'/../Fixtures/*.md') ?: [] as $path) {
            $file = basename($path);
            // An excerpt of a real report, cut after the antivirus section when it was saved here.
            $cases[$file] = [$file, $file === 'windows-checkup-eigenaar.md' ? [HEALTH_CUT] : []];
        }

        return $cases;
    });
});

describe('a full run of the current Mac script', function () {
    it('writes every section that is expected of it, so its report shows nothing', function () {
        $base = ScriptTemp::dir('pcc-health-run');
        File::ensureDirectoryExists($base.'/home/Desktop');
        File::ensureDirectoryExists($base.'/none');
        file_put_contents($base.'/mac-checkup.sh', CheckupScript::Mac->contents());

        $result = Process::path($base)->timeout(120)->env([
            'PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $base.'/home', 'PCC_SKIP_UPDATE_CHECK' => '1',
            'PCC_STORAGE_HOME' => $base.'/home', 'PCC_STORAGE_ROOT' => $base.'/none', 'PCC_STORAGE_DATA' => $base.'/none',
            'PCC_STORAGE_FIRMLINKS' => $base.'/none', 'PCC_STORAGE_CAP' => '5', 'PCC_SPACE_HOME' => $base.'/home', 'PCC_SPACE_CAP' => '5',
            'PCC_APPS_HOME' => $base.'/home', 'PCC_APPS_DIR' => $base.'/none', 'PCC_APPS_CAP' => '5', 'PCC_CACHE_DIR' => $base.'/none',
            'PCC_CRASH_USER_DIR' => $base.'/none', 'PCC_CRASH_SYSTEM_DIR' => $base.'/none', 'PCC_REMOTE_DIRS' => $base.'/none',
            'PCC_HOSTS_FILE' => $base.'/none/hosts', 'PCC_WIFI_CAP' => '5', 'PCC_CRASH_CAP' => '5', 'PCC_REMOTE_CAP' => '5',
            'PCC_NETWORK_CAP' => '5', 'PCC_TRUST_CAP' => '5',
        ])->input('')->run(['bash', 'mac-checkup.sh']);
        $reports = glob($base.'/home/Desktop/mac-checkup-*.md') ?: [];
        $markdown = (string) file_get_contents($reports[0] ?? '/dev/null');

        expect($result->successful())->toBeTrue()
            ->and($reports)->toHaveCount(1)
            ->and($markdown)->toContain('- Script version: '.CheckupScript::Mac->version())
            ->and(healthProblems($markdown))->toBe([])
            ->and(healthProblems(substr($markdown, 0, (int) strpos($markdown, '## '.HEALTH_CHECKLIST))))->toBe([HEALTH_CUT]);
    })->skipOnMac('A real Mac would run every slow system command for real')->skipOnWindows('Needs bash');
});
