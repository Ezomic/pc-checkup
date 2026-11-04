<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportStorage;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function storageFile(string $path, int $kib): void
{
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, str_repeat('x', $kib * 1024));
}

function storageTree(): string
{
    $base = sys_get_temp_dir().'/pcc-storage-'.bin2hex(random_bytes(4));
    $home = $base.'/home';

    foreach ([
        'Downloads/photo.JPG' => 1024, 'Downloads/shot.png' => 512, 'Downloads/clip.mp4' => 2048,
        'Downloads/a.zip' => 256, 'Downloads/b.tar.gz' => 136, 'Downloads/my report.pdf' => 64,
        'Downloads/notes.txt' => 4, 'Downloads/setup.exe' => 16, 'Downloads/noext' => 8,
        'Downloads/.hidden' => 4, 'Downloads/weird.a-b' => 4, 'Downloads/long.abcdefghijk' => 4,
        'Downloads/Secret Subfolder/inner.jpg' => 16, 'Documents/doc.docx' => 32,
        'Documents/song.mp3' => 128, 'Pictures/sub/pic.jpeg' => 68, 'Library/cache.bin' => 260,
    ] as $file => $kib) {
        storageFile("{$home}/{$file}", $kib);
    }

    foreach (range(1, 7) as $i) {
        storageFile("{$base}/root/dir{$i}/data", $i * 100);
    }

    storageFile("{$base}/root/with space/data", 52);
    storageFile("{$base}/root/Volumes/huge", 9996);
    storageFile("{$base}/root/file.txt", 8);
    symlink("{$base}/root/dir7", "{$base}/root/link");
    File::ensureDirectoryExists("{$base}/apps");
    storageFile("{$base}/apps/App.app/bin", 40);

    return $base;
}

/**
 * @param  array<string, string>  $env
 */
function runStorage(string $base, array $env = [], ?string $path = null): ProcessResult
{
    return Process::path(base_path())->timeout(120)->env(array_filter(array_merge([
        'PCC_STORAGE_HOME' => $base.'/home',
        'PCC_STORAGE_ROOT' => $base.'/root',
        'PCC_STORAGE_APPS' => $base.'/apps',
        'PATH' => $path,
    ], $env), fn (?string $value): bool => $value !== null))->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_storage_report']);
}

/**
 * @return array<string, list<list<string>>>
 */
function storageRows(string $output): array
{
    $rows = [];

    foreach (explode("\n", trim($output)) as $line) {
        $parts = explode('|', $line);

        if (count($parts) > 1) {
            $rows[array_shift($parts)][] = $parts;
        }
    }

    return $rows;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/pcc-storage-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('the Mac step on a tree with known files', function () {
    it('measures the well-known folders, leaving out the ones that do not exist', function () {
        $result = runStorage(storageTree());
        $folders = array_column(storageRows($result->output())['folder'], 1, 0);

        expect($result->successful())->toBeTrue()
            ->and(array_keys($folders))->toBe(['Downloads', 'Documents', 'Pictures', 'Applications', 'Library'])
            ->and((int) $folders['Downloads'])->toBeBetween(4096, 4096 + 32)
            ->and((int) $folders['Documents'])->toBeBetween(160, 160 + 16)
            ->and((int) $folders['Applications'])->toBeBetween(40, 40 + 16);
    });

    it('lists the five biggest folders at the top of the drive, without volumes, links and loose files', function () {
        $roots = storageRows(runStorage(storageTree())->output())['root'];

        expect(array_column($roots, 0))->toBe(['dir7', 'dir6', 'dir5', 'dir4', 'dir3'])
            ->and((int) $roots[0][1])->toBeBetween(700, 700 + 16);
    });

    it('groups the user folder into kinds of file with sizes and counts', function () {
        $types = storageRows(runStorage(storageTree())->output())['type'];

        expect($types)->toBe([
            ['Photos', '1620', '4'],
            ['Videos', '2048', '1'],
            ['Music', '128', '1'],
            ['Documents', '100', '3'],
            ['Archives', '392', '2'],
            ['Apps', '16', '1'],
            ['Other', '280', '5'],
        ]);
    });

    it('keeps the eight biggest extensions, lower case, and drops odd ones', function () {
        $extensions = storageRows(runStorage(storageTree())->output())['ext'];

        expect($extensions)->toBe([
            ['mp4', '2048', '1'],
            ['jpg', '1040', '2'],
            ['png', '512', '1'],
            ['bin', '260', '1'],
            ['zip', '256', '1'],
            ['gz', '136', '1'],
            ['mp3', '128', '1'],
            ['jpeg', '68', '1'],
        ]);
    });

    it('states the limit, how long it took and that it finished', function () {
        $lines = explode("\n", trim(runStorage(storageTree())->output()));

        expect($lines[0])->toBe('scan: complete')
            ->and($lines[1])->toMatch('/^seconds: \d+$/')
            ->and($lines[2])->toBe('limit: 90');
    });

    it('prints only the fixed kinds of line and never a file or subfolder name', function () {
        $output = runStorage(storageTree())->output();

        foreach (explode("\n", trim($output)) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: \d+|folder\|[A-Za-z ]+\|\d+|root\|[A-Za-z0-9 ._()+-]{1,40}\|\d+|type\|[A-Za-z]+\|\d+\|\d+|ext\|[a-z0-9]{1,10}\|\d+\|\d+)$/');
        }

        expect($output)->not->toContain('Secret')->not->toContain('inner')->not->toContain('photo')->not->toContain('report')->not->toContain('sub')->not->toContain('hidden');
    });

    it('reads what the report parser expects', function () {
        $output = runStorage(storageTree())->output();
        $read = (new ReportStorage)->read(ReportSections::parse("## Storage breakdown\n\n```\n{$output}\n```\n"));

        expect($read['partial'])->toBeFalse()
            ->and(array_column($read['folders'], 'name'))->toBe(['Downloads', 'Library', 'Documents', 'Pictures', 'Applications'])
            ->and(array_column($read['categories'], 'name'))->toBe(['Videos', 'Photos', 'Archives', 'Other', 'Music', 'Documents', 'Apps'])
            ->and($read['roots'])->toHaveCount(5);
    });

    it('does not follow a symbolic link out of the user folder', function () {
        $base = storageTree();
        storageFile("{$base}/outside/big.mp4", 8192);
        symlink("{$base}/outside", "{$base}/home/Documents/linked");
        symlink("{$base}/outside/big.mp4", "{$base}/home/Documents/linked-file.mp4");
        symlink("{$base}/outside", "{$base}/home/Music");
        $rows = storageRows(runStorage($base)->output());

        expect($rows['type'][1])->toBe(['Videos', '2048', '1'])
            ->and((int) array_column($rows['folder'], 1, 0)['Documents'])->toBeLessThan(1000)
            ->and(array_column($rows['folder'], 0))->not->toContain('Music');
    });

    it('shows progress on the screen and keeps it out of the report text', function () {
        $result = runStorage(storageTree());

        expect($result->errorOutput())->toContain('Measuring')
            ->and($result->output())->not->toContain('Measuring');
    });

    it('is quiet about folders it cannot read', function () {
        $base = storageTree();
        mkdir("{$base}/home/Downloads/locked");
        file_put_contents("{$base}/home/Downloads/locked/x", 'x');
        chmod("{$base}/home/Downloads/locked", 0);
        $result = runStorage($base);
        chmod("{$base}/home/Downloads/locked", 0755);

        expect($result->output())->not->toContain('denied')->and($result->errorOutput())->not->toContain('denied')
            ->and($result->output())->toContain('scan: complete');
    });

    it('prints a scan with nothing in it for an empty home', function () {
        $base = sys_get_temp_dir().'/pcc-storage-'.bin2hex(random_bytes(4));
        mkdir($base.'/home', 0777, true);
        mkdir($base.'/root');
        $output = runStorage($base, ['PCC_STORAGE_APPS' => $base.'/none'])->output();

        expect(storageRows($output))->toBe([])
            ->and($output)->toContain('scan: complete');
    });
});

describe('the time limit', function () {
    it('stops a slow disk at the limit and says so', function () {
        $bin = fakeMacBin(['du' => 'exec sleep 30', 'find' => 'exec sleep 30']);
        $start = microtime(true);
        $result = runStorage(storageTree(), ['PCC_STORAGE_CAP' => '3'], $bin.':/usr/bin:/bin');
        $took = microtime(true) - $start;

        expect($took)->toBeLessThan(10)
            ->and($result->output())->toContain('scan: partial (time limit of 3 seconds reached)')
            ->and($result->output())->toContain('limit: 3')
            ->and($result->errorOutput())->toContain('time limit')
            ->and(storageRows($result->output()))->toBe([]);
    });

    it('keeps what it measured before the time ran out and marks the scan partial', function () {
        $bin = fakeMacBin(['find' => 'exec sleep 30']);
        $result = runStorage(storageTree(), ['PCC_STORAGE_CAP' => '4'], $bin.':/usr/bin:/bin');
        $rows = storageRows($result->output());

        expect($result->output())->toContain('scan: partial')
            ->and($rows['folder'])->not->toBe([])
            ->and($rows['root'])->not->toBe([])
            ->and($rows)->not->toHaveKey('type');
    });

    it('falls back to the default limit for a value that is not a positive whole number', function (string $cap) {
        expect(runStorage(storageTree(), ['PCC_STORAGE_CAP' => $cap])->output())->toContain('limit: 90');
    })->with(['abc', '0', '-5', '1.5', '']);

    it('defaults to ninety seconds', function () {
        expect((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('PCC_STORAGE_CAP:-90');
    });
});

describe('what the scripts contain', function () {
    $functions = function (string $file, string $pattern): string {
        preg_match_all($pattern, (string) file_get_contents(base_path($file)), $found);

        return implode("\n", $found[0]);
    };

    it('runs the Mac step as its own section with a progress line, after the disk usage', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain('section "Storage breakdown" echo "$STORAGE_BREAKDOWN"')
            ->and(strpos($script, 'section "Storage breakdown"'))->toBeGreaterThan(strpos($script, 'section "Disk usage"'))
            ->and($script)->toContain('Measuring storage');
    });

    it('runs the Windows step as its own section, after the volumes', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect($script)->toContain("Add-Section 'Storage breakdown'")
            ->and(strpos($script, "Add-Section 'Storage breakdown'"))->toBeGreaterThan(strpos($script, "Add-Section 'Storage (volumes and free space)'"))
            ->and($script)->toContain('Measuring storage');
    });

    it('never touches the network, deletes, writes or schedules anything from the storage step', function () use ($functions) {
        $mac = $functions('scripts/mac-checkup.sh', '/^pcc_storage_\w+\(\) \{.*?^\}/ms');
        $windows = $functions('scripts/windows-checkup.ps1', '/^function (?:Get|Measure|Test)-Storage\w+.*?^\}/ms');

        expect($mac)->not->toBe('')->and($windows)->not->toBe('');
        expect($mac)->not->toMatch('/\b(curl|wget|nc|ssh|scp|mv|cp|chmod|chown|crontab|launchctl)\b|>\s*\/dev\/tcp|\$\{?PCC_TOKEN/');
        expect($windows)->not->toMatch('/Invoke-WebRequest|Invoke-RestMethod|WebClient|BitsTransfer|Send-|Remove-Item|Set-Content|Out-File|Add-Content|New-Item|Register-ScheduledTask|schtasks|Start-Process|\$Token|\$ScanCode/i');
    });

    it('keeps the Mac step to what bash 3.2 and macOS 10.14 have', function () use ($functions) {
        $mac = $functions('scripts/mac-checkup.sh', '/^pcc_storage_\w+\(\) \{.*?^\}/ms');

        expect($mac)->not->toMatch('/declare -[Aan]|local -[An]|mapfile|readarray|\$\{[^}]*(,,|\^\^)[^}]*\}|&>>|\|&|find [^\n]*-(printf|maxdepth)|stat -c|sort -V|date -d|readlink -f|xargs -r|grep -[a-zA-Z]*P|sed -r|timeout |gawk|--max-depth|du -[a-z]*b\b|-print0/');
    });

    it('is valid bash', function () {
        $result = Process::path(base_path())->run(['bash', '-n', 'scripts/mac-checkup.sh']);

        expect($result->successful())->toBeTrue()->and($result->errorOutput())->toBe('');
    });

    it('keeps the Windows step to Windows PowerShell 5.1 and plain ASCII', function () use ($functions) {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));
        $windows = $functions('scripts/windows-checkup.ps1', '/^function (?:Get|Measure|Test)-Storage\w+.*?^\}/ms');

        expect($script)->toMatch('/\A[\x00-\x7F]*\z/')
            ->and($windows)->not->toMatch('/\?\?|\?\.|&&|\|\||-Parallel|Get-ChildItem|\[System\.IO\.Enumeration|-AsHashtable|\$IsWindows/')
            ->and($windows)->toContain('ReparsePoint')
            ->and($windows)->toContain('$Stats')
            ->and($windows)->toContain('Stopwatch');
    });

    it('uses a ninety second limit in both scripts', function () {
        expect((string) file_get_contents(base_path('scripts/windows-checkup.ps1')))->toContain('PCC_STORAGE_CAP')->toContain('$StorageCapSeconds = 90')
            ->and((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('limit: ');
    });
});

describe('the Windows step under PowerShell', function () {
    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));

    it('measures a temp folder with known files', function () use ($pwsh) {
        $base = storageTree();
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));
        preg_match_all('/^function (?:Get|Measure|Test)-Storage\w+.*?^\}/ms', $script, $found);
        file_put_contents($base.'/step.ps1', implode("\n", $found[0])."\nGet-StorageBreakdown\n");

        $result = Process::timeout(120)->env([
            'PCC_STORAGE_PROFILE' => $base.'/home',
            'PCC_STORAGE_ROOT' => $base.'/root',
            'PCC_STORAGE_PROGRAMS' => $base.'/apps',
        ])->run([$pwsh, '-NoProfile', '-File', $base.'/step.ps1']);
        $rows = storageRows($result->output());

        expect($result->output())->toContain('scan: complete')
            ->and($rows['type'][0])->toBe(['Photos', '1620', '4']);
    })->skip($pwsh === '', 'PowerShell is not installed here');
});
