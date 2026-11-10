<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function bsdScript(): string
{
    return (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
}

// Inside double quotes the shell only drops the backslash before \, ", $ and `.
function bsdUnquote(string $text): string
{
    return (string) preg_replace('/\\\\([\\\\"$`])/', '$1', $text);
}

/**
 * The words the shell hands to a command that starts at $at, up to the end of that command.
 *
 * @return list<string>
 */
function bsdWords(string $code, int $at): array
{
    $words = [];
    $length = strlen($code);
    $ends = " \t|;&<>)\n`";

    while (true) {
        while ($at < $length && ($code[$at] === ' ' || $code[$at] === "\t")) {
            $at++;
        }

        if ($at >= $length || str_contains($ends, $code[$at])) {
            return $words;
        }

        $word = '';

        while ($at < $length && ! str_contains($ends, $code[$at])) {
            if ($code[$at] === "'") {
                $end = strpos($code, "'", $at + 1);
                $end = $end === false ? $length : $end;
                $word .= substr($code, $at + 1, $end - $at - 1);
                $at = $end + 1;
            } elseif (preg_match('/\G"((?:[^"\\\\]|\\\\.)*)"/s', $code, $quoted, 0, $at) === 1) {
                $word .= bsdUnquote($quoted[1]);
                $at += strlen($quoted[0]);
            } else {
                $word .= $code[$at] === '\\' && $at + 1 < $length ? $code[++$at] : $code[$at];
                $at++;
            }
        }

        $words[] = $word;
    }
}

/**
 * The escapes in a sed or grep expression ("bre" or "ere") or an awk program ("awk") that macOS
 * 10.14 reads differently from GNU: BSD sed and grep take \r, \t and \n for the letters r, t and n,
 * and the awk of 10.14 reads an interval such as {2,4} as plain text.
 *
 * @return list<string>
 */
function bsdEscapes(string $text, string $mode, string $delimiter = ''): array
{
    $unknown = $mode === 'awk' ? 'sSwWyB<>`\'' : 'rtnsSwWbB<>`\''.($mode === 'bre' ? '+?|' : '');
    $found = $mode === 'awk' && preg_match('/\{[0-9]+(,[0-9]*)?\}/', $text) === 1 ? ['an interval like {2,4}'] : [];

    for ($i = 0; $i < strlen($text) - 1; $i++) {
        if ($text[$i] === '\\') {
            if ($delimiter === '' || $text[$i + 1] !== $delimiter) {
                $found[] = str_contains($unknown, $text[++$i]) ? '\\'.$text[$i] : null;
            } else {
                $i++;
            }
        }
    }

    return array_values(array_filter($found));
}

/**
 * What one sed, grep or awk call does differently on macOS 10.14: GNU-only flags and escapes.
 *
 * @param  list<string>  $words  the words after the command name
 * @return list<string>
 */
function bsdCall(string $tool, array $words): array
{
    $found = [];
    $expressions = [];
    $extended = false;
    $fixed = false;
    $i = 0;

    for (; $i < count($words); $i++) {
        $word = $words[$i];

        if ($word === '--') {
            $i++;

            break;
        }

        if (strlen($word) < 2 || $word[0] !== '-') {
            break;
        }

        if ($tool === 'awk') {
            if (str_starts_with($word, '-F')) {
                $found = [...$found, ...bsdEscapes(strlen($word) > 2 ? substr($word, 2) : ($words[++$i] ?? ''), 'awk')];
            } elseif ($word === '-v' || $word === '-f') {
                $i++;
            }

            continue;
        }

        if (str_starts_with($word, '--')) {
            $found[] = in_array($word, ['--regexp-extended', '--in-place', '--perl-regexp'], true) || str_starts_with($word, '--in-place=') ? $word : null;

            continue;
        }

        for ($j = 1; $j < strlen($word); $j++) {
            $flag = $word[$j];
            $rest = substr($word, $j + 1);

            if ($flag === 'E') {
                $extended = true;
            } elseif ($flag === 'F' && $tool === 'grep') {
                $fixed = true;
            } elseif (($flag === 'r' && $tool === 'sed') || ($flag === 'P' && $tool === 'grep')) {
                $found[] = '-'.$flag;
            } elseif ($flag === 'i' && $tool === 'sed') {
                $found[] = $rest === '' ? '-i without a suffix' : null;

                break;
            } elseif ($flag === 'e') {
                $expressions[] = $rest !== '' ? $rest : ($words[++$i] ?? '');

                break;
            } elseif ($tool === 'grep' && str_contains('mABCf', $flag)) {
                $i += $rest === '' ? 1 : 0;

                break;
            }
        }
    }

    if ($expressions === [] && isset($words[$i])) {
        $expressions[] = $words[$i];
    }

    foreach ($expressions as $expression) {
        if ($tool === 'awk') {
            $found = [...$found, ...bsdEscapes($expression, 'awk')];
        } elseif (! $fixed) {
            $delimiter = $tool === 'sed' && preg_match('/^\s*s([^\w\s\\\\])/', $expression, $s) === 1 ? $s[1] : '';
            $found = [...$found, ...bsdEscapes($expression, $extended ? 'ere' : 'bre', $delimiter)];
        }
    }

    return array_values(array_unique(array_filter($found)));
}

/**
 * Every sed, grep and awk call in the script that macOS 10.14 runs differently from GNU, as
 * "tool (line N): what". The command string of an sh_section is read as the shell code it is.
 *
 * @return list<string>
 */
function bsdUnportable(string $script): array
{
    $script = (string) preg_replace('/^[ \t]*#.*$/m', '', str_replace("\r\n", "\n", $script));
    $script = (string) preg_replace_callback(
        '/^sh_section[ \t]+"(?:[^"\\\\\n]|\\\\.)*"[ \t]+"((?:[^"\\\\\n]|\\\\.)*)"[ \t]*$/m',
        fn (array $line): string => bsdUnquote($line[1]),
        $script,
    );
    preg_match_all('/(?<![\w.$-])(sed|grep|awk)(?=[ \t]+[-\'"])/', $script, $calls, PREG_OFFSET_CAPTURE);

    $found = [];

    foreach ($calls[1] as [$tool, $offset]) {
        foreach (bsdCall($tool, bsdWords($script, $offset + strlen($tool))) as $what) {
            $found[] = $tool.' (line '.(substr_count(substr($script, 0, $offset), "\n") + 1).'): '.$what;
        }
    }

    return $found;
}

/**
 * Runs the real section() of the script on what $text holds. With $macSed the sed on PATH reads
 * \r as the letter r, as the BSD sed of macOS does, which GNU sed cannot be made to do.
 */
function bsdSection(string $text, bool $macSed = true, string $command = 'cat "$INPUT"'): string
{
    preg_match('/^section\(\) \{.*?^\}$/ms', bsdScript(), $function);
    expect($function)->not->toBeEmpty();

    $dir = ScriptTemp::dir('pcc-bsd');
    file_put_contents($dir.'/input', $text);
    mkdir($dir.'/bin');
    file_put_contents($dir.'/bin/sed', "#!/bin/bash\nargs=()\nfor a in \"\$@\"; do args+=(\"\${a//\\\\r/r}\"); done\nexec /usr/bin/sed \"\${args[@]}\"\n");
    chmod($dir.'/bin/sed', 0755);

    return Process::path(base_path())->timeout(60)
        ->env(['INPUT' => $dir.'/input', 'PATH' => ($macSed ? $dir.'/bin:' : '').'/usr/bin:/bin'])
        ->run(['bash', '-c', $function[0]."\nBODY=\$(mktemp)\nsection \"Hardware overview\" {$command}\ncat \"\$BODY\"\nrm -f \"\$BODY\""])
        ->throw()->output();
}

function bsdFenced(string $body): string
{
    return "\n## Hardware overview\n\n```\n{$body}```\n";
}

/**
 * A synthetic Mac report shaped like a real one, with the last letter of every line inside a
 * section dropped when $damaged, as the scripts before PCC-90 wrote it on a Mac.
 */
function bsdReport(bool $damaged): string
{
    $report = <<<'MD'
# Mac checkup: Synthetic-Air

- Format: 1
- OS: macOS
- Device type: laptop
- Serial: SYNTHSERIAL090
- Model: MacBook Air (MacBookAir7,2)
- Chip/CPU: Intel Core i5
- Memory: 8 GB
- macOS: 10.14.6 (18G9323)
- Free disk: 40%

## Things to look at

- Nothing flagged automatically.

## Hardware overview

```
Hardware:

    Hardware Overview:

      Model Name: MacBook Air
      Model Identifier: MacBookAir7,2
      Processor Name: Intel Core i5
      Total Number of Cores: 2
      Memory: 8 GB

```

## Top processes by CPU

```
  PID  %CPU %MEM COMM
  186  95,0  0,6 WindowServer
  254  16,5  0,1 tccd
 1510   3,7  0,7 Finder
```

## Storage breakdown

```
scan: complete
seconds: 40
limit: 150
format: 2
root|Users|52499440
root|Applications|13333212
roots_total|65832652
home|52499428
folder|Downloads|248536
library|Caches|1766520
missing|library/Developer
missing|root/usr
```

MD;

    if (! $damaged) {
        return $report;
    }

    return (string) preg_replace_callback('/^```\n(.*?)^```$/ms', fn (array $block): string => "```\n".preg_replace('/r$/m', '', $block[1]).'```', $report);
}

describe('the section of a Mac report', function () {
    it('keeps the last letter of every line, with a sed on the path that reads \r as macOS does', function () {
        $text = "      Model Name: MacBook Air\n      Identified Developer\n  186   1,6  0,6 WindowServer\nLoudspeaker\n";

        expect(bsdSection($text))->toBe(bsdFenced($text));
    })->skipOnWindows('Needs bash');

    it('drops a carriage return at the end of a line or inside it', function (bool $macSed) {
        expect(bsdSection("MacBook Air\r\nWindowServer\r\nhalf\rway\n", $macSed))->toBe(bsdFenced("MacBook Air\nWindowServer\nhalfway\n"));
    })->with(['macOS sed on the path' => [true], 'GNU sed on the path' => [false]])->skipOnWindows('Needs bash');

    it('leaves tabs, accents, other control characters and a lone r alone', function () {
        $text = "Café\tUnicode ✓ r\nr\n\x01bell\x07\nrrr\n\n";

        expect(bsdSection($text))->toBe(bsdFenced($text));
    })->skipOnWindows('Needs bash');

    it('passes bytes that are not valid UTF-8 through as they are', function () {
        $text = "Caf\xE9 in Latin-1\r\n\xFF\xFE half\xC3\n";

        expect(bsdSection($text))->toBe(bsdFenced("Caf\xE9 in Latin-1\n\xFF\xFE half\xC3\n"));
    })->skipOnWindows('Needs bash');

    it('cleans what the command prints on its error output too', function () {
        expect(bsdSection('', true, "bash -c 'printf \"Docker\\r\\n\" >&2'"))->toBe(bsdFenced("Docker\n"));
    })->skipOnWindows('Needs bash');

    it('writes only the fences for a command that prints nothing', function () {
        expect(bsdSection(''))->toBe("\n## Hardware overview\n\n```\n```\n");
    })->skipOnWindows('Needs bash');

    it('keeps every line of a long output whole', function () {
        $output = bsdSection(str_repeat("      Identified Developer\r\n", 50000));

        expect(strlen($output))->toBe(strlen(bsdFenced(str_repeat("      Identified Developer\n", 50000))))
            ->and(substr_count($output, "      Identified Developer\n"))->toBe(50000);
    })->skipOnWindows('Needs bash');
});

describe('the sed, grep and awk calls of the Mac script', function () {
    it('use nothing that BSD sed, grep or awk on macOS 10.14 reads differently', function () {
        expect(bsdUnportable(bsdScript()))->toBe([], 'BSD sed and grep read \r, \t and \n as the letters r, t and n and know none of \s, \w, \b, \< or \>; a basic expression has no \+, \? or \|; sed has no -r and no -i without a suffix; grep has no -P.');
    });

    it('are found when they would behave differently on a Mac', function (string $code, array $expected) {
        expect(bsdUnportable($code))->toBe($expected);
    })->with([
        'the carriage return of PCC-90' => ["  \"\$@\" 2>&1 | sed 's/\\r\$//'\n", ['sed (line 1): \r']],
        'a tab in a double quoted sed' => ["x=\$(sed -e \"s/\\\\t/ /g\" f)\n", ['sed (line 1): \t']],
        'a newline in a sed replacement' => ["sed 's/,/\\n/g'\n", ['sed (line 1): \n']],
        'a word class' => ["grep -E '^\\s*name\\w+'\n", ['grep (line 1): \s', 'grep (line 1): \w']],
        'a word boundary' => ["grep -q '\\bname\\b' f\n", ['grep (line 1): \b']],
        'one or more in a basic expression' => ["grep 'a\\+b' f\n", ['grep (line 1): \+']],
        'an alternative in a basic expression' => ["sed -n 's/a\\|b/c/p'\n", ['sed (line 1): \|']],
        'an optional in a basic expression' => ["grep -c 'ab\\?' f\n", ['grep (line 1): \?']],
        'sed -r' => ["sed -r 's/a+//'\n", ['sed (line 1): -r']],
        'sed -i without a suffix' => ["sed -i 's/a/b/' f\n", ['sed (line 1): -i without a suffix']],
        'a long option' => ["sed --regexp-extended 's/a+//'\n", ['sed (line 1): --regexp-extended']],
        'grep -P' => ["grep -oP '\\d+' f\n", ['grep (line 1): -P']],
        'a gawk word class' => ["awk '/^\\s+x/ { print }'\n", ['awk (line 1): \s']],
        'a gawk word boundary in a field separator' => ["awk -F'\\y' '{ print \$1 }'\n", ['awk (line 1): \y']],
        'an awk interval' => ["awk '\$1 ~ /^[0-9]{4}\$/ { n++ }'\n", ['awk (line 1): an interval like {2,4}']],
        'in a time cap' => ["pcc_capped 5 grep -Pq x f\n", ['grep (line 1): -P']],
        'in an sh_section string' => ["sh_section \"Chrome\" \"echo x | sed 's/.*: *\\\"\\\\(.*\\\\)\\\".*/\\\\1/;s/\\\\r//'\"\n", ['sed (line 1): \r']],
        'on a later line' => ["true\nfalse\nx | sed -e 's/a/b/' -e 's/\\r//'\n", ['sed (line 3): \r']],
    ]);

    it('leave alone what macOS reads the same way', function (string $code) {
        expect(bsdUnportable($code))->toBe([]);
    })->with([
        'tr with a carriage return' => ["\"\$@\" 2>&1 | tr -d '\\r'\n"],
        'awk with a carriage return' => ["awk '{ gsub(/\\r/, \"\") }'\n"],
        'awk with a tab separator' => ["awk -F'\\t' '{ print \$1 }'\n"],
        'an extended alternative and plus' => ["grep -E '^(home|folder)\\|' f; sed -E 's/a+|b?//'\n"],
        'a grep interval and awk blocks' => ["grep -Eo '[0-9]{4}-[0-9]{2}' f | awk '{ n++ } END { print n }'\n"],
        'a pipe delimiter' => ["sed -e 's|.*/||' -e 's|a\\|b||'\n"],
        'escaped special characters' => ["sed -e 's/[][*?\\\\]/\\\\&/g'; grep -v '127\\.0\\.0\\.1\\.'\n"],
        'basic groups' => ["sed -n 's/^{ sec = \\([0-9][0-9]*\\),.*/\\1/p'\n"],
        'a fixed string' => ["grep -Fxq -- '\\r' f\n"],
        'a comment' => ["  # sed 's/\\r\$//' broke reports\n"],
        'perl' => ["perl -e 'my \$x = grep { /\\s/ } @y;'\n"],
        'sed -i with a suffix' => ["sed -i.bak 's/a/b/' f\n"],
        'grep with a count argument' => ["grep -m 1 'x' f; grep -m1 '\"name\"' f\n"],
    ]);
});

describe('the cards of a Mac report', function () {
    it('read the whole words of a new report', function () {
        $built = (new ReportDetails(new ReportFacts))->build(bsdReport(false), [], 'laptop');
        $cards = (string) json_encode($built['cards']);

        expect($built['failed'])->toBe([])
            ->and($cards)->toContain('WindowServer is working the processor hard')
            ->toContain('Could not be read: Library: Developer, usr.');
    });

    it('still build from a stored report whose lines lost their last r, showing the words as they were stored', function () {
        $built = (new ReportDetails(new ReportFacts))->build(bsdReport(true), [], 'laptop');
        $cards = (string) json_encode($built['cards']);

        expect(bsdReport(true))->toContain("WindowServe\n")->toContain("missing|library/Develope\n")
            ->and($built['failed'])->toBe([])
            ->and($cards)->toContain('WindowServe is working the processor hard')
            ->not->toContain('Could not be read');
    });
});
