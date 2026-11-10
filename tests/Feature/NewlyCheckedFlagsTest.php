<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Flags\FlagAdvisor;
use App\Services\Flags\FlagChecks;

use function Pest\Laravel\actingAs;

const NC_WINDOWS_BACKUP = 'No backup was found (File History is off and Desktop, Documents and Pictures are not saved to OneDrive).';

const NC_MAC_BACKUP = 'No backup was found (Time Machine is not set up).';

const NC_WINDOWS_RDP = 'Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.';

const NC_MAC_SCREEN_SHARING = 'Screen Sharing is on, so other people on the network can ask to see or control this Mac.';

const NC_WINDOWS_11_ENDED = 'Windows 11 24H2 reached end of support on 13 October 2026, so it no longer gets security updates. Update to the newest version of Windows 11.';

const NC_MACOS_13 = 'macOS 13.6.7 is old and likely out of security support.';

const NC_WINDOWS_NO_UPDATES = 'No Windows update has been installed for 75 days (newest: 2026-08-26).';

// The sections a Windows scan wrote on 1 November 2026, before the backup, remote exposure, administrator and BIOS checks.
const NC_WINDOWS_NOV_1 = [
    'Hardware overview', 'Identity as reported by firmware (before placeholders are removed)', 'Windows version', 'CPU and memory',
    'Battery and power', 'Storage (physical disks)', 'Storage (volumes and free space)', 'Disk wear and errors (SMART counters, needs admin)',
    'Top processes by CPU (5 second sample, grouped by name)', 'Top processes by memory (grouped by name)', 'Graphics', 'Network adapters',
    'Bluetooth, cameras and audio', 'Security: Secure Boot, TPM, BitLocker', 'Security: Defender', 'Security: firewall', 'Activation',
    'Domain, Entra ID and MDM', 'Security and remote access agents running', 'User accounts', 'Windows Update', 'Installed apps',
    'Startup programs', 'Devices with problems (Device Manager)', 'Listening network ports', 'Hosts file (non-default lines)',
    'Crashes and unexpected shutdowns (last 30 days)', 'Disk error events (last 30 days)', 'Locale and time',
];

// The same scan from 3 November 2026: the checks of that release are in, the script version line is not yet.
const NC_WINDOWS_NOV_3 = [...NC_WINDOWS_NOV_1, 'Backup status', 'Remote exposure', 'Administrator accounts', 'BIOS and firmware age'];

// The sections a Mac scan wrote on 1 November 2026.
const NC_MAC_NOV_1 = [
    'Hardware overview', 'macOS version', 'Model identifier and chip', 'Battery and power', 'Battery raw (ioreg)', 'Charger',
    'Storage (volumes)', 'Storage (boot volume)', 'Disk usage', 'Memory pressure', 'Top processes by CPU', 'Top processes by memory',
    'Performance: load, swap, memory, throttling', 'Performance: 10 second CPU sample (second snapshot is the real one)',
    'Performance: Spotlight indexing', 'Performance: disk write and read speed (512 MB test)', 'Performance: security and management agents',
    'Performance: login item and startup counts', 'Performance: biggest folders in home', 'Graphics and displays', 'Network interfaces',
    'Wi-Fi', 'Bluetooth', 'USB devices', 'Thunderbolt', 'Cameras and audio', 'Printers', 'Security: FileVault, SIP, Gatekeeper, firewall',
    'Security: secure boot and firmware', 'Activation Lock and Find My', 'MDM and enrolment', 'Firmware password (Intel only)',
    'Sharing and remote access', 'User accounts', 'Logged in users and recent logins', 'Software updates', 'Installed apps (/Applications)',
    'Installed apps with versions', 'Homebrew', 'Configuration profiles and kernel/system extensions', 'Startup items: LaunchAgents and LaunchDaemons',
    'Login items', 'Background items (macOS 13+)', 'Cron jobs', 'Listening network ports', 'DNS and proxy', 'Hosts file (non-default lines)',
    'Kernel panic reports', 'Recent shutdown causes', 'Time Machine', 'Privacy permissions (TCC), needs Full Disk Access for Terminal',
    'Trusted certificates: custom trust settings', 'Certificates in System keychain', 'Browser extensions: Chrome', 'Browser extensions: Firefox',
    'Browser extensions: Safari', 'Saved Wi-Fi networks and VPNs', 'SSD wear (smartctl, optional)',
    'Intel apps running under Rosetta (Apple silicon only)', 'Reboot history and boot time', 'Fans and temperatures (needs sudo)',
    'Locale and time', 'Developer tools',
];

/**
 * @return list<string>
 */
function ncScriptTitles(string $os): array
{
    [$path, $pattern] = $os === 'Windows'
        ? ['scripts/windows-checkup.ps1', "/^Add-Section '([^']+)'/m"]
        : ['scripts/mac-checkup.sh', '/^(?:sh_)?section "([^"]+)"/m'];

    preg_match_all($pattern, (string) file_get_contents(base_path($path)), $matches);

    return $matches[1];
}

/**
 * @param  list<string>  $flags
 * @param  list<string>  $sections
 */
function ncMarkdown(string $os, array $flags, array $sections, ?string $version): string
{
    $header = $version === null ? '' : "- Script version: {$version}\n";
    $flagLines = implode("\n", array_map(fn (string $flag): string => "- {$flag}", $flags));
    $body = implode('', array_map(fn (string $title): string => "\n## {$title}\n\n```\n(nothing to show)\n```\n", $sections));

    return "# {$os} checkup: PC\n\n- Format: 1\n{$header}- OS: {$os}\n\n## Things to look at\n\n{$flagLines}\n{$body}";
}

/**
 * @param  list<string>  $flags
 * @param  list<string>  $sections
 */
function ncReport(Machine $machine, string $at, array $flags, array $sections, ?string $version = null, string $os = 'Windows'): Report
{
    return Report::factory()->for($machine)->create([
        'markdown' => ncMarkdown($os, $flags, $sections, $version),
        'flags' => $flags,
        'ran_as_admin' => true,
        'created_at' => $at,
    ]);
}

/**
 * @return array{0: Client, 1: Machine}
 */
function ncSharedMachine(): array
{
    $client = Client::factory()->withShareLink()->create();

    return [$client, Machine::factory()->for($client->user)->create(['client_id' => $client->id])];
}

/**
 * @return array<string, mixed>
 */
function ncProps(?User $user, string $path): array
{
    $response = $user === null ? test()->get($path) : actingAs($user)->get($path);

    return $response->assertOk()->viewData('page')['props'];
}

/**
 * The real pair: a scan from 1 November without the backup check and one from 10 November that found no backup.
 *
 * @return array{0: Report, 1: Report}
 */
function ncBackupPair(Machine $machine, string $os, array $nov1, string $backup): array
{
    return [
        ncReport($machine, '2026-11-01 10:00:00', ['Secure Boot is OFF.'], $nov1, null, $os),
        ncReport($machine, '2026-11-10 10:00:00', ['Secure Boot is OFF.', $backup], ncScriptTitles($os), 'c0ffee12', $os),
    ];
}

dataset('platforms', [
    'Windows' => ['Windows', NC_WINDOWS_NOV_1, NC_WINDOWS_BACKUP],
    'Mac' => ['Mac', NC_MAC_NOV_1, NC_MAC_BACKUP],
]);

describe('a flag the older scan could not raise', function () {
    it('is not new on the machine page but listed as newly checked', function (string $os, array $nov1, string $backup) {
        $machine = Machine::factory()->create();
        ncBackupPair($machine, $os, $nov1, $backup);

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe([])
            ->and($changes['fixed'])->toBe([])
            ->and($changes['open'])->toBe(1)
            ->and($changes['newly_checked'])->toBe(['No backup was found']);
    })->with('platforms');

    it('is not new when comparing the two scans from the history page', function (string $os, array $nov1, string $backup) {
        $machine = Machine::factory()->create();
        [$before, $after] = ncBackupPair($machine, $os, $nov1, $backup);

        $comparison = ncProps($machine->user, "/machines/{$machine->id}/history?from={$after->id}&to={$before->id}")['comparison'];

        expect($comparison['new'])->toBe([])
            ->and($comparison['fixed'])->toBe([])
            ->and($comparison['newly_checked'])->toBe(['No backup was found']);
    })->with('platforms');

    it('is still to do, not new, in the owner before and after document, which keeps its shape', function (string $os, array $nov1, string $backup) {
        $machine = Machine::factory()->create();
        ncBackupPair($machine, $os, $nov1, $backup);

        $comparison = ncProps($machine->user, "/machines/{$machine->id}/before-after?lang=en")['comparison'];

        expect(array_keys($comparison))->toBe(['before', 'after', 'same_rights', 'fixed', 'open', 'new', 'numbers'])
            ->and($comparison['new'])->toBe([])
            ->and($comparison['open'])->toBe(['Secure Boot is off', 'No backup was found']);
    })->with('platforms');

    it('is still to do, not new, on the client before and after page, which keeps its shape', function (string $os, array $nov1, string $backup) {
        [$client, $machine] = ncSharedMachine();
        ncBackupPair($machine, $os, $nov1, $backup);

        $props = ncProps(null, "/c/{$client->share_token}/machines/{$machine->id}/before-after");

        expect(array_keys($props['comparison']))->toBe(['before', 'after', 'same_rights', 'fixed', 'open', 'new', 'numbers'])
            ->and($props['comparison']['new'])->toBe([])
            ->and($props['comparison']['open'])->toBe(['Secure Boot staat uit', 'Er is geen back-up gevonden'])
            ->and(json_encode($props))->not->toContain('newly')->not->toContain('File History');
    })->with('platforms');

    it('keeps the before and after document from calling the machine clear', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', ['Secure Boot is OFF.'], NC_WINDOWS_NOV_1);
        ncReport($machine, '2026-11-10 10:00:00', [NC_WINDOWS_BACKUP], ncScriptTitles('Windows'), 'c0ffee12');

        $comparison = ncProps($machine->user, "/machines/{$machine->id}/before-after?lang=en")['comparison'];

        expect($comparison['fixed'])->toBe(['Secure Boot is off'])
            ->and($comparison['new'])->toBe([])
            ->and($comparison['open'])->toBe(['No backup was found']);
    });

    it('adds nothing to the client machine page', function (string $os, array $nov1, string $backup) {
        [$client, $machine] = ncSharedMachine();
        ncBackupPair($machine, $os, $nov1, $backup);

        $props = ncProps(null, "/c/{$client->share_token}/machines/{$machine->id}");

        expect(array_keys($props['report']))->toBe(['scanned_at', 'facts', 'advice', 'fixed_count'])
            ->and($props['report']['fixed_count'])->toBe(0)
            ->and(json_encode($props))->not->toContain('newly');
    })->with('platforms');

    it('is not new when the older scan only mentions the section inside a code block', function () {
        $machine = Machine::factory()->create();
        Report::factory()->for($machine)->create([
            'markdown' => ncMarkdown('Windows', [], NC_WINDOWS_NOV_1, null)."\n## Installed apps\n\n```\n## Backup status\n```\n",
            'flags' => [],
            'created_at' => '2026-11-01 10:00:00',
        ]);
        ncReport($machine, '2026-11-10 10:00:00', [NC_WINDOWS_BACKUP], ncScriptTitles('Windows'), 'c0ffee12');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe([])->and($changes['newly_checked'])->toBe(['No backup was found']);
    });
});

describe('a flag whose check both scans ran', function () {
    it('is new as before', function (string $os, array $nov1, string $backup) {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-08 10:00:00', ['Secure Boot is OFF.'], ncScriptTitles($os), 'aaaa1111', $os);
        ncReport($machine, '2026-11-10 10:00:00', ['Secure Boot is OFF.', $backup], ncScriptTitles($os), 'c0ffee12', $os);

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe(['No backup was found'])
            ->and($changes['fixed'])->toBe([])
            ->and($changes['newly_checked'])->toBe([]);
    })->with('platforms');

    it('is fixed as before', function (string $os, array $nov1, string $backup) {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-08 10:00:00', [$backup], ncScriptTitles($os), 'aaaa1111', $os);
        ncReport($machine, '2026-11-10 10:00:00', [], ncScriptTitles($os), 'c0ffee12', $os);

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['fixed'])->toBe(['No backup was found'])->and($changes['new'])->toBe([]);
    })->with('platforms');

    it('is new when the older scan from 1 November already had its section', function (string $os, array $nov1) {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', [], $nov1, null, $os);
        ncReport($machine, '2026-11-10 10:00:00', ['Battery has 900 cycles (high).'], ncScriptTitles($os), 'c0ffee12', $os);

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe(['The battery is worn'])->and($changes['newly_checked'])->toBe([]);
    })->with('platforms');
});

describe('a check the newer scan no longer runs', function () {
    dataset('dropped checks', [
        'Windows' => ['Windows', NC_WINDOWS_RDP],
        'Mac' => ['Mac', NC_MAC_SCREEN_SHARING],
    ]);

    it('never shows its flag as fixed', function (string $os, string $flag) {
        $machine = Machine::factory()->create();
        $today = ncScriptTitles($os);
        ncReport($machine, '2026-11-08 10:00:00', [$flag], $today, 'aaaa1111', $os);
        ncReport($machine, '2026-11-10 10:00:00', [], array_values(array_diff($today, ['Remote exposure'])), 'c0ffee12', $os);

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['fixed'])->toBe([])
            ->and($changes['new'])->toBe([])
            ->and($changes['open'])->toBe(0)
            ->and($changes['newly_checked'])->toBe([]);
    })->with('dropped checks');

    it('does not count it as fixed for the client', function (string $os, string $flag) {
        [$client, $machine] = ncSharedMachine();
        $today = ncScriptTitles($os);
        ncReport($machine, '2026-11-08 10:00:00', [$flag, 'Secure Boot is OFF.'], $today, 'aaaa1111', $os);
        ncReport($machine, '2026-11-10 10:00:00', [], array_values(array_diff($today, ['Remote exposure'])), 'c0ffee12', $os);

        $report = ncProps(null, "/c/{$client->share_token}/machines/{$machine->id}")['report'];
        $comparison = ncProps(null, "/c/{$client->share_token}/machines/{$machine->id}/before-after")['comparison'];

        expect($report['fixed_count'])->toBe(1)
            ->and($comparison['fixed'])->toBe(['Secure Boot staat uit']);
    })->with('dropped checks');
});

describe('old reports without a script version', function () {
    it('lists an out of support Windows 11 version as newly checked', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-03 10:00:00', [], NC_WINDOWS_NOV_3);
        ncReport($machine, '2026-11-10 10:00:00', [NC_WINDOWS_11_ENDED], ncScriptTitles('Windows'), 'c0ffee12');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe([])->and($changes['newly_checked'])->toBe(['The operating system is no longer supported']);
    });

    it('lists the raised macOS cut-off as newly checked', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', [], NC_MAC_NOV_1, null, 'Mac');
        ncReport($machine, '2026-11-10 10:00:00', [NC_MACOS_13], ncScriptTitles('Mac'), 'c0ffee12', 'Mac');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe([])->and($changes['newly_checked'])->toBe(['The operating system is no longer supported']);
    });

    it('still counts a supported operating system as fixed', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-03 10:00:00', ['Microsoft Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.'], NC_WINDOWS_NOV_3);
        ncReport($machine, '2026-11-10 10:00:00', [], ncScriptTitles('Windows'), 'c0ffee12');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['fixed'])->toBe(['The operating system is no longer supported']);
    });

    it('lists a Windows update age as newly checked only for a scan from before that check', function (array $older, array $new, array $newlyChecked) {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', [], $older);
        ncReport($machine, '2026-11-10 10:00:00', [NC_WINDOWS_NO_UPDATES], ncScriptTitles('Windows'), 'c0ffee12');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe($new)->and($changes['newly_checked'])->toBe($newlyChecked);
    })->with([
        'scan from 1 November' => [NC_WINDOWS_NOV_1, [], ['No updates installed for 75 days']],
        'scan from 3 November' => [NC_WINDOWS_NOV_3, ['No updates installed for 75 days'], []],
    ]);

    it('compares as before when neither scan has a script version', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', [], NC_WINDOWS_NOV_1);
        ncReport($machine, '2026-11-03 10:00:00', [NC_WINDOWS_11_ENDED], NC_WINDOWS_NOV_3);

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe(['The operating system is no longer supported'])->and($changes['newly_checked'])->toBe([]);
    });
});

describe('what keeps the plain comparison', function () {
    it('compares a flag no rule recognises as before', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', ["The kettle\u{0007} is loud."], NC_WINDOWS_NOV_1);
        ncReport($machine, '2026-11-10 10:00:00', ['Het koffiezetapparaat lekt ☕.'], ncScriptTitles('Windows'), 'c0ffee12');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe(['Het koffiezetapparaat lekt ☕'])
            ->and($changes['fixed'])->toBe(["The kettle\u{0007} is loud"])
            ->and($changes['newly_checked'])->toBe([]);
    });

    it('counts a flag as new when the newer scan does not show its check either', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', [], NC_WINDOWS_NOV_1);
        ncReport($machine, '2026-11-10 10:00:00', [NC_WINDOWS_BACKUP], NC_WINDOWS_NOV_1, 'c0ffee12');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe(['No backup was found'])->and($changes['newly_checked'])->toBe([]);
    });

    it('counts a flag as fixed when the older scan did not show its check either', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', [NC_WINDOWS_BACKUP], NC_WINDOWS_NOV_1);
        ncReport($machine, '2026-11-10 10:00:00', [], NC_WINDOWS_NOV_1, 'c0ffee12');

        expect(ncProps($machine->user, "/machines/{$machine->id}")['changes']['fixed'])->toBe(['No backup was found']);
    });

    it('compares reports without any sections as before', function () {
        $machine = Machine::factory()->create();
        ncReport($machine, '2026-11-01 10:00:00', ['FileVault is OFF.'], [], null, 'Mac');
        ncReport($machine, '2026-11-10 10:00:00', [NC_MAC_BACKUP], [], null, 'Mac');

        $changes = ncProps($machine->user, "/machines/{$machine->id}")['changes'];

        expect($changes['new'])->toBe(['No backup was found'])
            ->and($changes['fixed'])->toBe(['Disk encryption is off'])
            ->and($changes['newly_checked'])->toBe([]);
    });
});

describe('the flag to section mapping', function () {
    it('names every rule of the advisor and nothing else', function () {
        $rules = array_unique(array_column((new ReflectionClassConstant(FlagAdvisor::class, 'RULES'))->getValue(), 2));

        expect(array_keys(FlagChecks::SECTIONS))->toEqualCanonicalizing(array_values($rules));
    });

    it('names only sections the scripts write today', function () {
        $written = [...ncScriptTitles('Windows'), ...ncScriptTitles('Mac')];
        $named = array_merge(...array_values(FlagChecks::SECTIONS));

        expect(array_diff($named, [...$written, FlagChecks::SCRIPT_VERSION]))->toBe([]);
    });

    it('finds the check behind every Windows flag in a Windows scan', function (string $flag) {
        $rule = (new FlagAdvisor)->advise($flag)->rule;

        expect($rule)->not->toBeNull()
            ->and(array_intersect(FlagChecks::SECTIONS[$rule], [...ncScriptTitles('Windows'), FlagChecks::SCRIPT_VERSION]))->not->toBe([]);
    })->with([
        'Battery has 900 cycles (high).',
        'Battery full-charge capacity is 70% of design (below 80%).',
        "Disk 'Samsung SSD 870' health is 'Warning'.",
        "Disk 'WDC WD10EZEX' status is 'Pred Fail'.",
        'Only spinning hard drives found (no SSD), so the PC will feel slow.',
        'Only about 7% of drive C: is free.',
        'Disk 0 SSD wear is 85% (near end of life).',
        'Only 9% of memory is available (high memory pressure).',
        'Only 4 GB of RAM.',
        'Up for 41 days since the last full boot (Fast Startup can inflate this).',
        'Secure Boot is not available (legacy BIOS boot or unsupported firmware).',
        'Secure Boot is OFF.',
        'TPM is present but not ready.',
        'No TPM found (Windows 11 needs TPM 2.0).',
        'BitLocker is not protecting C: (Off).',
        'BitLocker is OFF on C:.',
        'Windows Firewall is off for the Public profile.',
        'Defender real-time protection is OFF and no other antivirus was found.',
        'Defender signatures are 12 days old.',
        'Defender tamper protection is off.',
        'Windows is not activated (status: Notification).',
        "Windows uses a volume licence (Windows(R) Operating System, VOLUME_KMSCLIENT channel), usually an organisation's.",
        'Security, management or remote access agents running (can slow a PC or give someone else access): teamviewer',
        'Joined to Entra ID / Azure AD (managed by an organisation).',
        'Enrolled in MDM (managed by an organisation).',
        'Joined to the domain CORP (managed by an organisation).',
        'Microsoft Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.',
        NC_WINDOWS_11_ENDED,
        'A restart is pending (Windows Update is waiting for a restart).',
        NC_WINDOWS_NO_UPDATES,
        'Windows updates are pending (see Windows Update section): 3.',
        '18 programs start with Windows (slows boot).',
        '2 device(s) report a driver problem in Device Manager (see Devices with problems section).',
        '3 unexpected shutdown(s) in the last 30 days (crash, power loss or forced power off).',
        '4 disk error event(s) in the last 30 days (bad blocks or controller errors).',
        NC_WINDOWS_BACKUP,
        'The last backup was 45 days ago (File History).',
        NC_WINDOWS_RDP,
        'SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).',
        'Automatic sign-in is on, so anyone who starts this computer gets in without a password.',
        '4 accounts have administrator rights (see Administrator accounts section).',
        '1 enabled account(s) are not required to have a password (see Administrator accounts section).',
        'The BIOS (firmware) is 7 years old (released 2019-03-01).',
    ]);

    it('finds the check behind every Mac flag in a Mac scan', function (string $flag) {
        $rule = (new FlagAdvisor)->advise($flag)->rule;

        expect($rule)->not->toBeNull()
            ->and(array_intersect(FlagChecks::SECTIONS[$rule], [...ncScriptTitles('Mac'), FlagChecks::SCRIPT_VERSION]))->not->toBe([]);
    })->with([
        'Battery has 900 cycles (high).',
        "Battery condition is 'Service Recommended'.",
        'Battery maximum capacity is 75% (below 80%).',
        'FileVault is OFF.',
        'System Integrity Protection is DISABLED.',
        'Gatekeeper is DISABLED.',
        'Application firewall is off.',
        'Enrolled in MDM (managed by an organisation).',
        'Enrolled via Apple Business/School Manager (DEP/ADE).',
        "Activation Lock is ENABLED. Needs the previous owner's Apple ID to be removed.",
        "Disk SMART status is 'Failing'.",
        'Only about 12% of disk is free.',
        '2 kernel panic report(s) found in /Library/Logs/DiagnosticReports.',
        'Configuration profiles are installed (see MDM and enrolment section).',
        'Custom admin-trusted certificates are installed (see Trusted certificates section).',
        'Software updates are pending (see Software updates section).',
        NC_MACOS_13,
        '1 minute load average 9.1 exceeds the 8 CPU cores.',
        'Swap in use is 3100 MB, so the Mac is short on RAM.',
        'Only 9% of memory is free (high memory pressure).',
        'Low Power Mode is on, which throttles performance.',
        'CPU is thermally throttled to 80% (check vents, dust, heavy load).',
        'Not restarted for 41 days.',
        'Security or management agents running (can slow a Mac): falcon ',
        'Background indexing/backup process is using heavy CPU (Spotlight, Photos or Time Machine).',
        NC_MAC_BACKUP,
        'The last backup was 45 days ago (Time Machine).',
        'Remote Login (SSH) is on, so other computers can try to sign in to this Mac.',
        NC_MAC_SCREEN_SHARING,
        'Automatic sign-in is on, so anyone who starts this computer gets in without a password.',
        'No macOS update has been installed for 90 days (newest: 2026-08-12).',
        '4 accounts have administrator rights (see Administrator accounts section).',
    ]);
});
