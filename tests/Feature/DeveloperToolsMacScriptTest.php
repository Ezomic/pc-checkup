<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

/**
 * The commands in /usr/bin that only stand in for Xcode, the command line developer tools or a JDK.
 * On a Mac without those, running one opens a dialog that offers to download and install them.
 *
 * @return list<string>
 */
function dtsStubs(): array
{
    return [
        'ar', 'as', 'asa', 'bison', 'BuildStrings', 'c++', 'c89', 'c99', 'cc', 'clang', 'clang++', 'cmpdylib',
        'codesign_allocate', 'CpMac', 'cpp', 'ctags', 'ctf_insert', 'DeRez', 'dsymutil', 'dwarfdump', 'flex', 'flex++',
        'g++', 'gatherheaderdoc', 'gcc', 'gcov', 'GetFileInfo', 'git', 'git-receive-pack', 'git-shell', 'git-upload-archive',
        'git-upload-pack', 'gm4', 'gnumake', 'gperf', 'hdxml2manxml', 'headerdoc2html', 'indent', 'install_name_tool', 'jar',
        'java', 'javac', 'ld', 'lex', 'libtool', 'lipo', 'lldb', 'llvm-g++', 'llvm-gcc', 'lorder', 'm4', 'make', 'MergePef',
        'mig', 'mkdep', 'MvMac', 'nm', 'nmedit', 'objdump', 'opendiff', 'otool', 'pagestuff', 'pip3', 'pydoc3', 'python3',
        'ranlib', 'resolveLinks', 'ResMerger', 'Rez', 'rpcgen', 'segedit', 'SetFile', 'size', 'SplitForks', 'strings', 'strip',
        'svn', 'svnadmin', 'svndumpfilter', 'svnlook', 'svnrdump', 'svnserve', 'svnsync', 'svnversion', 'swift', 'swiftc',
        'unifdef', 'unifdefall', 'xcodebuild', 'xcrun', 'xed', 'xml2man', 'yacc',
    ];
}

const DTS_GUARD_HINT = 'On a Mac without the developer tools these commands open an install dialog. Run one only after '
    .'"dir=$(pcc_devtools_dir) || { ...; return 0; }" earlier in the same function, or after "pcc_devtools_dir > /dev/null &&" on its line.';

/**
 * Every place shell code runs one of the stubs unguarded, as "name (line N)". Comment lines are
 * skipped, and so is a name that is assigned to, as awk does with "size = ...".
 *
 * @return list<string>
 */
function dtsUnguarded(string $script): array
{
    $script = (string) preg_replace('/^[ \t]*#.*$/m', '', str_replace("\r\n", "\n", $script));
    $stubs = implode('|', array_map(fn (string $stub): string => preg_quote($stub, '/'), dtsStubs()));
    $keywords = '(?:[ \t]*(?:(?:then|do|else|if|elif|while|until|time|command|exec|env|sudo|nohup|nice|xargs)\b|!))*';
    $lead = '(?:(?:^|;|&&|\|\||\||\$\(|`|\{[ \t]|^[ \t]*\()'.$keywords.'|-exec\b|\bpcc_capped[ \t]+\S+'
        .'|\b(?:ba)?sh[ \t]+-c[ \t]+["\']|\beval[ \t]+["\']|\b(?:sh_)?section[ \t]+"(?:[^"\\\\\n]|\\\\.)*"[ \t]+["\']?)';
    preg_match_all('/'.$lead.'[ \t]*(?:\/usr\/bin\/)?('.$stubs.')(?=[ \t;&|)"\'`]|$)(?![ \t]*[-+*\/%]?=)/m', $script, $calls, PREG_OFFSET_CAPTURE);
    preg_match_all('/^[A-Za-z_][A-Za-z0-9_]*\(\) \{(?:[^\n]*\}$|.*?^\}$)/ms', $script, $functions, PREG_OFFSET_CAPTURE);

    $found = [];

    foreach ($calls[1] as [$name, $offset]) {
        $before = substr($script, 0, $offset);
        $guarded = preg_match('/\bpcc_devtools_dir\b[^\n]*&&[^\n]*$/', $before) === 1;

        foreach ($functions[0] as [$body, $start]) {
            if ($offset > $start && $offset < $start + strlen($body)) {
                $guarded = $guarded || preg_match('/\bpcc_devtools_dir\b[^\n]*\|\|[^\n]*\breturn\b/', substr($before, $start)) === 1;
            }
        }

        if (! $guarded) {
            $found[] = $name.' (line '.(substr_count($before, "\n") + 1).')';
        }
    }

    return $found;
}

/**
 * Runs the Developer tools step with nothing on PATH but the fake commands given. "{dir}" in a body
 * is the folder of the run, and the fake git and python3 note each call in its "ran" file.
 *
 * @param  array<string, string>  $commands  command name => script body
 * @param  list<string>  $folders  folders to create in the folder of the run first
 * @return array{output: string, ran: string, dir: string}
 */
function dtsRun(array $commands, array $folders = []): array
{
    $dir = ScriptTemp::dir('pcc-dts');
    mkdir($dir.'/bin');

    foreach ($folders as $folder) {
        mkdir($dir.'/'.$folder, 0777, true);
    }

    foreach ($commands as $name => $body) {
        file_put_contents("{$dir}/bin/{$name}", "#!/bin/bash\n".str_replace('{dir}', $dir, $body)."\n");
        chmod("{$dir}/bin/{$name}", 0755);
    }

    $result = Process::path(base_path())->timeout(30)->env(['DTS_BIN' => $dir.'/bin'])
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; PATH=$DTS_BIN; pcc_devtools_report'])->throw();

    return ['output' => $result->output(), 'ran' => is_file($dir.'/ran') ? (string) file_get_contents($dir.'/ran') : '', 'dir' => $dir];
}

/**
 * @return array<string, string>
 */
function dtsTools(): array
{
    return [
        'git' => 'echo "git $*" >> "${0%/*}/../ran"; echo "git version 2.39.5 (Apple Git-154)"',
        'python3' => 'echo "python3 $*" >> "${0%/*}/../ran"; echo "Python 3.9.6"',
    ];
}

const DTS_NOT_INSTALLED = "Command line developer tools: not installed\n";

describe('the Mac developer tools step', function () {
    it('runs neither git nor python3 when xcode-select finds no developer tools, as on the Mac of the real report', function () {
        $run = dtsRun([
            'xcode-select' => 'echo "xcode-select: error: unable to get active developer directory, use \`sudo xcode-select --switch path/to/Xcode.app\` to set one" >&2; exit 2',
            ...dtsTools(),
        ]);

        expect($run['ran'])->toBe('')
            ->and($run['output'])->toBe(DTS_NOT_INSTALLED);
    })->skipOnWindows('Needs bash');

    it('treats an answer from xcode-select that is not an existing folder as not installed', function (string $xcodeSelect) {
        $run = dtsRun(['xcode-select' => $xcodeSelect, ...dtsTools()]);

        expect($run['ran'])->toBe('')
            ->and($run['output'])->toBe(DTS_NOT_INSTALLED);
    })->with([
        'a folder that was removed' => ['echo "{dir}/CommandLineTools"'],
        'nothing at all' => ['exit 0'],
        'an empty line' => ['echo'],
        'an existing folder with a failing exit code' => ['echo "{dir}"; exit 1'],
        'two lines' => ['printf "%s\n%s\n" "{dir}" "{dir}"'],
        'a file instead of a folder' => ['echo "{dir}/bin/git"'],
        'a control character' => ['printf "{dir}\001\n"'],
    ])->skipOnWindows('Needs bash');

    it('treats a Mac without xcode-select as not installed', function () {
        $run = dtsRun(dtsTools());

        expect($run['ran'])->toBe('')
            ->and($run['output'])->toBe(DTS_NOT_INSTALLED);
    })->skipOnWindows('Needs bash');

    it('prints the developer folder and the versions of git and python3 when the tools are installed', function () {
        $run = dtsRun(['xcode-select' => 'echo "{dir}"', ...dtsTools()]);

        expect($run['ran'])->toBe("git --version\npython3 --version\n")
            ->and($run['output'])->toBe($run['dir']."\ngit version 2.39.5 (Apple Git-154)\nPython 3.9.6\n");
    })->skipOnWindows('Needs bash');

    it('prints a developer folder with spaces and accents as it is', function () {
        $folder = 'Xcode bèta.app/Contents/Developer';
        $run = dtsRun(['xcode-select' => 'echo "{dir}/'.$folder.'"', ...dtsTools()], [$folder]);

        expect(explode("\n", $run['output'])[0])->toBe($run['dir'].'/'.$folder)
            ->and($run['ran'])->toBe("git --version\npython3 --version\n");
    })->skipOnWindows('Needs bash');

    it('says which tool is missing on a Mac whose developer tools have no python3, like macOS 10.14', function () {
        $run = dtsRun(['xcode-select' => 'echo "{dir}"', 'git' => dtsTools()['git']]);

        expect($run['ran'])->toBe("git --version\n")
            ->and(explode("\n", trim($run['output'])))->toBe([$run['dir'], 'git version 2.39.5 (Apple Git-154)', 'python3: not installed']);
    })->skipOnWindows('Needs bash');

    it('says git is missing instead of failing when it is not on the path', function () {
        $run = dtsRun(['xcode-select' => 'echo "{dir}"', 'python3' => dtsTools()['python3']]);

        expect(explode("\n", trim($run['output'])))->toBe([$run['dir'], 'git: not installed', 'Python 3.9.6']);
    })->skipOnWindows('Needs bash');

    it('is the Developer tools section of the report', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain("\nsection \"Developer tools\" pcc_devtools_report\n")
            ->not->toContain('git --version 2>&1; python3');
    });
});

describe('the Apple developer stubs in the Mac script', function () {
    it('are only run after pcc_devtools_dir found the developer tools', function () {
        expect(dtsUnguarded((string) file_get_contents(base_path('scripts/mac-checkup.sh'))))->toBe([], DTS_GUARD_HINT);
    });

    it('are found wherever shell code would run them', function (string $code, array $expected) {
        expect(dtsUnguarded($code))->toBe($expected);
    })->with([
        'a line of its own' => ["foo() {\n  git status\n}\n", ['git (line 2)']],
        'after a semicolon' => ["foo() { echo hi; make all; }\n", ['make (line 1)']],
        'after and' => ["true && python3 -V\n", ['python3 (line 1)']],
        'after or' => ["false || clang -v\n", ['clang (line 1)']],
        'in a pipe' => ["echo x | xcrun --find cc\n", ['xcrun (line 1)']],
        'in a command substitution' => ["v=\$(svn --version)\n", ['svn (line 1)']],
        'in backticks' => ["v=`lldb --version`\n", ['lldb (line 1)']],
        'by its full path' => ["/usr/bin/git log\n", ['git (line 1)']],
        'after then' => ["if true; then swift --version; fi\n", ['swift (line 1)']],
        'in a time cap' => ["pcc_capped 5 otool -L /bin/ls\n", ['otool (line 1)']],
        'in a bash -c string' => ["bash -c \"pip3 list\"\n", ['pip3 (line 1)']],
        'in an sh_section string' => ["sh_section \"Tools\" \"xcode-select -p; gcc --version\"\n", ['gcc (line 1)']],
        'as a section command' => ["section \"Tools\" java -version\n", ['java (line 1)']],
        'from find' => ["find . -exec strings {} +\n", ['strings (line 1)']],
        'as an if condition' => ["if git status > /dev/null; then echo dirty; fi\n", ['git (line 1)']],
        'as a negated loop condition' => ["while ! svn update; do sleep 1; done\n", ['svn (line 1)']],
        'through command' => ["command git --version\n", ['git (line 1)']],
        'after a line continuation' => ["echo x; \\\n  make all\n", ['make (line 2)']],
        'after a section title with quotes' => ["sh_section \"The \\\"dev\\\" tools\" \"python3 -V\"\n", ['python3 (line 1)']],
        'in a function that never asks' => ["pcc_x() {\n  xcode-select -p\n  git --version\n}\n", ['git (line 3)']],
        'before the function asks' => ["pcc_x() {\n  git --version\n  pcc_devtools_dir || return 0\n}\n", ['git (line 2)']],
        'after a check that does not stop' => ["pcc_x() {\n  pcc_devtools_dir || echo none\n  git --version\n}\n", ['git (line 3)']],
        'after a check in a comment' => ["pcc_x() {\n  # pcc_devtools_dir || return 0\n  git --version\n}\n", ['git (line 3)']],
        'after a check in another function' => ["pcc_x() {\n  pcc_devtools_dir || return 0\n}\npcc_y() {\n  git --version\n}\n", ['git (line 5)']],
    ]);

    it('leave alone what does not run them', function (string $code) {
        expect(dtsUnguarded($code))->toBe([]);
    })->with([
        'a guarded call' => ["pcc_x() {\n  dir=\$(pcc_devtools_dir) || { echo none; return 0; }\n  git --version\n}\n"],
        'a one line guarded function' => ["pcc_x() { pcc_devtools_dir > /dev/null && make -v; }\n"],
        'a comment' => ["  # git and python3 are stubs\n"],
        'a lookup only' => ["command -v git > /dev/null\n"],
        'an awk variable' => ["awk '{ size = \"\"; type = \"\" }'\n"],
        'an awk sum' => ["awk '{ size += \$1 }'\n"],
        'an awk function' => ["awk 'function indent(s) { return 1 } { i = indent(\$0) }'\n"],
        'a word in a sentence' => ["echo \"Use git or make to build\"\n"],
        'a keyword in a sentence' => ["echo \"nothing to do make sense of\"\n"],
        'a longer name' => ["gitk; makefile_check; xcode-select -p\n"],
    ]);
});
