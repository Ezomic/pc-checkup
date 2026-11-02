<?php

declare(strict_types=1);

use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportParser;
use Illuminate\Support\Facades\Process;

/**
 * Builds a directory of fake system commands, so the Mac checks read canned output
 * and never the real machine.
 *
 * @param  array<string, string>  $scripts  command name => script body
 */
function fakeMacBin(array $scripts): string
{
    $dir = sys_get_temp_dir().'/pcc-risk-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    foreach ($scripts as $name => $body) {
        file_put_contents($dir.'/'.$name, "#!/bin/bash\n".$body."\n");
        chmod($dir.'/'.$name, 0755);
    }

    return $dir;
}

/**
 * @param  array<string, string>  $env
 * @return array{flags: list<string>, facts: string}
 */
function runMacCheck(string $bin, string $function, array $env = []): array
{
    $result = Process::path(base_path())
        ->env(array_merge(['PATH' => $bin.':/usr/bin:/bin'], $env))
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$function]);

    expect($result->errorOutput())->toBe('');

    $lines = explode("\n", trim($result->output()));
    $flags = array_values(array_map(fn (string $line) => substr($line, 6), array_filter($lines, fn (string $line) => str_starts_with($line, 'FLAG: '))));
    $facts = implode("\n", array_filter($lines, fn (string $line) => ! str_starts_with($line, 'FLAG: ')));

    return ['flags' => $flags, 'facts' => $facts];
}

function daysAgo(int $days): string
{
    return now()->subDays($days)->format('Y-m-d');
}

function snapshotPlist(string $date): string
{
    return "{\n    Destinations =     (\n        {\n            SnapshotDates =             (\n                \"{$date} 09:12:44 +0000\"\n            );\n        }\n    );\n    LastConfigurationTraceDate = \"2020-01-01 00:00:00 +0000\";\n}";
}

function installHistory(string $date, string $name = 'macOS Sequoia', string $process = 'softwareupdated'): string
{
    return "<dict>\n<key>date</key>\n<date>{$date}T10:11:12Z</date>\n<key>displayName</key>\n<string>{$name}</string>\n<key>displayVersion</key>\n<string>15.1</string>\n<key>processName</key>\n<string>{$process}</string>\n</dict>";
}

describe('Mac backup check', function () {
    it('flags a Mac without a Time Machine destination', function () {
        $bin = fakeMacBin(['tmutil' => 'echo "No destinations configured."']);

        $result = runMacCheck($bin, 'pcc_backup_check');

        expect($result['flags'])->toBe(['No backup was found (Time Machine is not set up).'])
            ->and($result['facts'])->toContain('Time Machine: not set up');
    });

    it('flags a last backup older than 30 days', function () {
        $bin = fakeMacBin([
            'tmutil' => 'echo "Name : Backup"',
            'defaults' => 'cat <<EOF
'.snapshotPlist(daysAgo(100)).'
EOF',
        ]);

        $result = runMacCheck($bin, 'pcc_backup_check');

        expect($result['flags'])->toBe(['The last backup was 100 days ago (Time Machine).'])
            ->and($result['facts'])->toContain('Last backup: '.daysAgo(100));
    });

    it('stays quiet about a recent backup and ignores unrelated dates', function () {
        $bin = fakeMacBin([
            'tmutil' => 'echo "Name : Backup"',
            'defaults' => 'cat <<EOF
'.snapshotPlist(daysAgo(3)).'
EOF',
        ]);

        $result = runMacCheck($bin, 'pcc_backup_check');

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Last backup: '.daysAgo(3).' (3 days ago)');
    });

    it('falls back to the latest backup folder name', function () {
        $bin = fakeMacBin([
            'tmutil' => 'case $1 in destinationinfo) echo "Name : Backup";; latestbackup) echo "/Volumes/Backup/Backups.backupdb/Mac/'.daysAgo(50).'-101010";; esac',
            'defaults' => 'exit 1',
        ]);

        expect(runMacCheck($bin, 'pcc_backup_check')['flags'])->toBe(['The last backup was 50 days ago (Time Machine).']);
    });

    it('says it could not read the date and flags nothing', function () {
        $bin = fakeMacBin([
            'tmutil' => 'case $1 in destinationinfo) echo "Name : Backup";; *) exit 1;; esac',
            'defaults' => 'exit 1',
        ]);

        $result = runMacCheck($bin, 'pcc_backup_check');

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Last backup: could not check');
    });

    it('says it could not check when tmutil fails', function () {
        $bin = fakeMacBin(['tmutil' => 'exit 1']);

        $result = runMacCheck($bin, 'pcc_backup_check');

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Time Machine: could not check');
    });
});

describe('Mac remote exposure check', function () {
    it('flags Remote Login, Screen Sharing and automatic login', function () {
        $bin = fakeMacBin([
            'netstat' => 'printf "tcp4  0  0  *.22   *.*  LISTEN\ntcp4  0  0  *.5900   *.*  LISTEN\ntcp4  0  0  *.8022   *.*  LISTEN\n"',
            'defaults' => 'echo alice',
        ]);

        $result = runMacCheck($bin, 'pcc_remote_check');

        expect($result['flags'])->toBe([
            'Remote Login (SSH) is on, so other computers can try to sign in to this Mac.',
            'Screen Sharing is on, so other people on the network can ask to see or control this Mac.',
            'Automatic sign-in is on, so anyone who starts this computer gets in without a password.',
        ])->and($result['facts'])->toContain('Remote Login (SSH): on')
            ->toContain('Automatic login: on')
            ->not->toContain('alice');
    });

    it('stays quiet when nothing is listening and automatic login is off', function () {
        $bin = fakeMacBin([
            'netstat' => 'printf "tcp4  0  0  *.8022   *.*  LISTEN\n"',
            'defaults' => 'exit 1',
        ]);

        $result = runMacCheck($bin, 'pcc_remote_check');

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Remote Login (SSH): off')
            ->toContain('Screen Sharing: off')
            ->toContain('Automatic login: off');
    });

    it('says it could not check when netstat fails', function () {
        $bin = fakeMacBin(['netstat' => 'exit 1', 'defaults' => 'exit 1']);

        $result = runMacCheck($bin, 'pcc_remote_check');

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Remote Login (SSH): could not check')
            ->toContain('Screen Sharing: could not check');
    });
});

describe('Mac software update age check', function () {
    it('flags when the newest macOS update is older than 60 days', function () {
        $bin = fakeMacBin([
            'plutil' => 'cat <<EOF
'.installHistory(daysAgo(200), 'macOS Sonoma').installHistory(daysAgo(90)).installHistory(daysAgo(2), 'XProtectPlistConfigData').installHistory(daysAgo(1), 'Slack', 'appstoreagent').'
EOF',
        ]);

        $result = runMacCheck($bin, 'pcc_updates_check', ['PCC_INSTALL_HISTORY' => __FILE__]);

        expect($result['flags'])->toBe(['No macOS update has been installed for 90 days (newest: '.daysAgo(90).').'])
            ->and($result['facts'])->toContain('Newest installed macOS update: '.daysAgo(90));
    });

    it('stays quiet about a recent update', function () {
        $bin = fakeMacBin(['plutil' => 'cat <<EOF
'.installHistory(daysAgo(10)).'
EOF']);

        expect(runMacCheck($bin, 'pcc_updates_check', ['PCC_INSTALL_HISTORY' => __FILE__])['flags'])->toBe([]);
    });

    it('says it could not check when there is no usable history', function (string $plutil) {
        $bin = fakeMacBin(['plutil' => $plutil]);

        $result = runMacCheck($bin, 'pcc_updates_check', ['PCC_INSTALL_HISTORY' => __FILE__]);

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Newest installed macOS update: could not check');
    })->with([
        'unreadable' => 'exit 1',
        'only background items' => 'cat <<EOF
'.installHistory('2026-01-01', 'XProtectPlistConfigData').'
EOF',
    ]);

    it('says it could not check when the history file is missing', function () {
        $bin = fakeMacBin(['plutil' => 'exit 1']);

        $result = runMacCheck($bin, 'pcc_updates_check', ['PCC_INSTALL_HISTORY' => '/nonexistent/InstallHistory.plist']);

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('could not check');
    });
});

describe('Mac administrator accounts check', function () {
    it('flags three or more administrators and ignores root', function () {
        $bin = fakeMacBin(['dscl' => 'echo "GroupMembership: root alice bob carol"']);

        $result = runMacCheck($bin, 'pcc_admins_check');

        expect($result['flags'])->toBe(['3 accounts have administrator rights (see Administrator accounts section).'])
            ->and($result['facts'])->toContain('Administrator accounts: 3');
    });

    it('stays quiet for one or two administrators', function () {
        $bin = fakeMacBin(['dscl' => 'echo "GroupMembership: root alice bob"']);

        $result = runMacCheck($bin, 'pcc_admins_check');

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Administrator accounts: 2');
    });

    it('says it could not check when dscl fails', function () {
        $bin = fakeMacBin(['dscl' => 'exit 1']);

        $result = runMacCheck($bin, 'pcc_admins_check');

        expect($result['flags'])->toBe([])
            ->and($result['facts'])->toContain('Administrator accounts: could not check');
    });
});

describe('days since helper', function () {
    it('counts whole days from a date', function () {
        $bin = fakeMacBin([]);

        expect(trim(Process::path(base_path())->env(['PATH' => $bin.':/usr/bin:/bin'])
            ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_days_since '.daysAgo(12)])->output()))->toBe('12');
    });
});

describe('scan scripts and the advisor', function () {
    it('sends every flag a Mac check can emit to a rule that knows it', function () {
        $bin = fakeMacBin([
            'tmutil' => 'echo "No destinations configured."',
            'netstat' => 'printf "tcp4 0 0 *.22 *.* LISTEN\ntcp4 0 0 *.5900 *.* LISTEN\n"',
            'defaults' => 'echo alice',
            'plutil' => 'cat <<EOF
'.installHistory(daysAgo(90)).'
EOF',
            'dscl' => 'echo "GroupMembership: root a b c"',
        ]);
        $flags = [
            ...runMacCheck($bin, 'pcc_backup_check')['flags'],
            ...runMacCheck($bin, 'pcc_remote_check')['flags'],
            ...runMacCheck($bin, 'pcc_updates_check', ['PCC_INSTALL_HISTORY' => __FILE__])['flags'],
            ...runMacCheck($bin, 'pcc_admins_check')['flags'],
        ];

        expect($flags)->toHaveCount(6);

        foreach ($flags as $flag) {
            expect((new FlagAdvisor)->advise($flag)->advice)->not->toBeNull();
        }
    });

    it('emits the exact Windows flag texts the advisor has rules for', function (string $literal, string $example) {
        expect((string) file_get_contents(base_path('scripts/windows-checkup.ps1')))->toContain($literal)
            ->and((new FlagAdvisor)->advise($example)->advice)->not->toBeNull();
    })->with([
        ['Add-Flag "No backup was found (File History is off and Desktop, Documents and Pictures are not saved to OneDrive)."', 'No backup was found (File History is off and Desktop, Documents and Pictures are not saved to OneDrive).'],
        ['Add-Flag "The last backup was $backupAge days ago (File History)."', 'The last backup was 40 days ago (File History).'],
        ['Add-Flag \'Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.\'', 'Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.'],
        ['Add-Flag \'SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).\'', 'SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).'],
        ['Add-Flag \'Automatic sign-in is on, so anyone who starts this computer gets in without a password.\'', 'Automatic sign-in is on, so anyone who starts this computer gets in without a password.'],
        ['Add-Flag "No Windows update has been installed for $installAge days (newest: $($lastInstall.ToString(\'yyyy-MM-dd\')))."', 'No Windows update has been installed for 75 days (newest: 2026-08-19).'],
        ['Add-Flag "$($noPassword.Count) enabled account(s) can sign in without a password (see Administrator accounts section)."', '1 enabled account(s) can sign in without a password (see Administrator accounts section).'],
        ['Add-Flag "$adminCount accounts have administrator rights (see Administrator accounts section)."', '3 accounts have administrator rights (see Administrator accounts section).'],
        ['Add-Flag "The BIOS (firmware) is $biosYears years old (released $($bios.ReleaseDate.ToString(\'yyyy-MM-dd\')))."', 'The BIOS (firmware) is 6 years old (released 2020-01-02).'],
    ]);
});

describe('honesty about what the scan does', function () {
    it('states in both scripts that the scan only reads', function (string $file) {
        $script = (string) file_get_contents(base_path($file));

        expect(substr_count($script, 'only reads information'))->toBeGreaterThanOrEqual(1)
            ->and($script)->toContain('only read information, and installed and changed nothing');
    })->with(['scripts/windows-checkup.ps1', 'scripts/mac-checkup.sh']);

    it('does not run, change or install anything in the new Windows checks', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect($script)->not->toMatch('/\b(?:Set|New)-ItemProperty|\b(?:Set|Start|Stop|New|Restart)-Service|\b(?:Enable|Disable)-WindowsOptionalFeature|\bSet-SmbServerConfiguration|\bRegister-ScheduledTask|\bschtasks\b|\breg(?:\.exe)? add\b|\b(?:Set|New|Enable|Disable)-LocalUser\b|\bAdd-LocalGroupMember\b/i');
    });
});

describe('reports from before the new checks', function () {
    it('still parse and advise the old fixture', function () {
        $markdown = (string) file_get_contents(base_path('tests/Fixtures/windows-checkup-desktop.md'));

        $parsed = (new ReportParser)->parse($markdown);

        expect($parsed->flags)->toHaveCount(6)
            ->and((new ReportFacts)->from($markdown)['memory'])->not->toBeNull()
            ->and((new FlagAdvisor)->adviseAll($parsed->flags))->toHaveCount(6);
    });

    it('read a new report with the intro line and new sections like any other', function () {
        $markdown = "# Windows checkup: TEST-PC\n\nThis report comes from one scan that ran once, only read information, and installed and changed nothing on this PC.\n\n- Format: 1\n- OS: Windows\n- Memory: 16 GB\n- Free disk: 40%\n\n## Things to look at\n\n- Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.\n- The last backup was 40 days ago (File History).\n\n## Backup status\n\n```\nFile History: set up\n```\n";

        $parsed = (new ReportParser)->parse($markdown);

        expect($parsed->name)->toBe('TEST-PC')
            ->and($parsed->os)->toBe('Windows')
            ->and($parsed->flags)->toBe([
                'Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.',
                'The last backup was 40 days ago (File History).',
            ])->and((new ReportFacts)->from($markdown)['free_disk'])->toBe('40%');
    });
});
