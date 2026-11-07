<?php

declare(strict_types=1);

use App\Enums\CheckupScript;
use App\Services\Reports\ScriptLayout;

const LAYOUT_END = 'Manual checklist (cannot be done by script)';

describe('the sections of the real scripts', function () {
    it('are every section the Mac script always writes, in order', function () {
        $sections = ScriptLayout::of(CheckupScript::Mac)->sections;

        expect($sections[0])->toBe('Hardware overview')
            ->and(end($sections))->toBe('Developer tools')
            ->and($sections)->toContain('Storage breakdown', 'Wi-Fi signal', 'Backup freshness', 'Remote access tools', 'Network settings', 'Recent app crashes')
            ->and($sections)->toBe(array_values(array_unique($sections)));
    });

    it('are every section the Windows script always writes, in order', function () {
        $sections = ScriptLayout::of(CheckupScript::Windows)->sections;

        expect($sections[0])->toBe('Hardware overview')
            ->and(end($sections))->toBe('Antivirus products')
            ->and($sections)->toContain('Storage breakdown', 'Windows 11 readiness', 'Windows health and security', 'Boot time', 'Network settings')
            ->and($sections)->toBe(array_values(array_unique($sections)));
    });

    it('keep the sections whose content depends on the hardware or on admin rights, because their heading is always written', function (CheckupScript $script, string $title) {
        expect(ScriptLayout::of($script)->sections)->toContain($title);
    })->with([
        'Mac battery on a desktop' => [CheckupScript::Mac, 'Battery and power'],
        'Mac charger on a desktop' => [CheckupScript::Mac, 'Charger'],
        'Mac Intel only' => [CheckupScript::Mac, 'Firmware password (Intel only)'],
        'Mac Apple silicon only' => [CheckupScript::Mac, 'Intel apps running under Rosetta (Apple silicon only)'],
        'Mac sudo only' => [CheckupScript::Mac, 'Fans and temperatures (needs sudo)'],
        'Mac macOS 13 and later' => [CheckupScript::Mac, 'Background items (macOS 13+)'],
        'Windows battery on a desktop' => [CheckupScript::Windows, 'Battery and power'],
        'Windows admin only' => [CheckupScript::Windows, 'Disk wear and errors (SMART counters, needs admin)'],
    ]);

    it('leave out what only some runs write and the parts around the sections', function (CheckupScript $script, string $title) {
        expect(ScriptLayout::of($script)->sections)->not->toContain($title);
    })->with([
        'Mac fix mode' => [CheckupScript::Mac, 'Fix log'],
        'Mac benchmark mode' => [CheckupScript::Mac, 'Benchmark'],
        'Mac flags' => [CheckupScript::Mac, 'Things to look at'],
        'Mac checklist' => [CheckupScript::Mac, LAYOUT_END],
        'Windows flags' => [CheckupScript::Windows, 'Things to look at'],
        'Windows checklist' => [CheckupScript::Windows, LAYOUT_END],
    ]);

    it('end with the manual checklist', function (CheckupScript $script) {
        expect(ScriptLayout::of($script)->end)->toBe(LAYOUT_END);
    })->with([CheckupScript::Mac, CheckupScript::Windows]);

    it('know the newest format any section is written in', function (CheckupScript $script) {
        expect(ScriptLayout::of($script)->newestFormat)->toBe(2);
    })->with([CheckupScript::Mac, CheckupScript::Windows]);

    it('carry the version of the script they were read from', function (CheckupScript $script) {
        expect(ScriptLayout::of($script)->version)->toBe($script->version());
    })->with([CheckupScript::Mac, CheckupScript::Windows]);
});

describe('reading a Mac script', function () {
    it('takes only the section calls at the start of a line, which always run', function () {
        $source = implode("\n", [
            'section() {',
            '  local title="$1"; shift',
            '}',
            'sh_section() { section "$1" bash -c "$2"; }',
            'section "Plain" system_profiler SPHardwareDataType',
            'sh_section "Shell: one, two" "uptime"',
            "section 'Single quoted' echo hi",
            'section    "Spaced"    echo hi',
            '  section "Indented in a block" echo hi',
            'if [ -n "$X" ]; then section "Only sometimes" echo hi; fi',
            '[ "$PCC_FIX" = 1 ] && section "After and" echo hi',
            'section "Named after $HOST" echo hi',
            'section "With a `backtick`" echo hi',
            'section "Escaped \\"quote\\"" echo hi',
            '# section "In a comment" echo hi',
            'echo "## Fix log"',
            'section "Plain" echo again',
            'sectioned "Not a call" echo hi',
        ]);

        expect(ScriptLayout::read(CheckupScript::Mac, $source, 'v1')->sections)
            ->toBe(['Plain', 'Shell: one, two', 'Single quoted', 'Spaced']);
    });

    it('reads a script saved with Windows line endings the same way', function () {
        $source = "section \"One\" echo 1\r\nsh_section \"Two\" \"echo 2\"\r\n## End\r\n";

        $layout = ScriptLayout::read(CheckupScript::Mac, $source, 'v1');

        expect($layout->sections)->toBe(['One', 'Two'])->and($layout->end)->toBe('End');
    });
});

describe('reading a Windows script', function () {
    it('takes only the Add-Section calls at the start of a line, which always run', function () {
        $source = implode("\n", [
            'function Add-Section([string]$Title, [scriptblock]$Block) {',
            '    $Body.Add("`n## $Title`n")',
            '}',
            "Add-Section 'Plain' { 'x' }",
            "add-section 'Any case' { 'x' }",
            "Add-Section 'It''s quoted' { 'x' }",
            "    Add-Section 'Indented in a block' { 'x' }",
            "if (\$IsAdmin) { Add-Section 'Only as admin' { 'x' } }",
            'Add-Section "Double $quoted" { \'x\' }',
            "Add-Section -Title 'Named' { 'x' }",
            "# Add-Section 'In a comment' { 'x' }",
            "\$lines.Add('## Things to look at')",
        ]);

        expect(ScriptLayout::read(CheckupScript::Windows, $source, 'v1')->sections)
            ->toBe(['Plain', 'Any case', "It's quoted"]);
    });
});

describe('the end and the formats of a script', function () {
    it('takes the last heading the script writes as its end', function () {
        $source = "echo \"## Things to look at\"\nsection \"A\" echo\ncat <<'EOF'\n\n## The end\n\n- [ ] Check.\nEOF\n";

        expect(ScriptLayout::read(CheckupScript::Mac, $source, 'v1')->end)->toBe('The end');
    });

    it('has no end when the script writes no heading of its own', function () {
        expect(ScriptLayout::read(CheckupScript::Mac, "section \"A\" echo\n", 'v1')->end)->toBeNull();
    });

    it('takes the highest format number the script prints', function () {
        $source = "  echo \"format: 1\"\n  echo \"format: 3\"\n    \$output.Add('format: 2')\n";

        expect(ScriptLayout::read(CheckupScript::Mac, $source, 'v1')->newestFormat)->toBe(3);
    });

    it('knows format 1 when the script prints no format at all', function () {
        expect(ScriptLayout::read(CheckupScript::Windows, "Add-Section 'A' { 'x' }\n", 'v1')->newestFormat)->toBe(1);
    });
});
