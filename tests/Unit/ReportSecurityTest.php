<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

const SECURITY_TITLE = 'Security: FileVault, SIP, Gatekeeper, firewall';

function securityDoc(string ...$blocks): string
{
    return "# Report\n\n".implode("\n", $blocks);
}

function securityBlock(string $title, string $body): string
{
    return "## {$title}\n\n```\n{$body}\n```\n";
}

/**
 * @return array<string, array{value: string, tone: string}>
 */
function securityRows(string $markdown): array
{
    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop') as $card) {
        if ($card['id'] === 'security') {
            $rows = [];

            foreach ($card['rows'] as $row) {
                $rows[$row['label']] = ['value' => $row['value'], 'tone' => $row['tone']];
            }

            return $rows;
        }
    }

    return [];
}

function macSecurity(string $body): array
{
    return securityRows(securityDoc(securityBlock(SECURITY_TITLE, $body)));
}

describe('a Mac', function () {
    it('summarises the real sample report', function () {
        expect(securityRows(macReportFixture()))->toBe([
            'FileVault' => ['value' => 'Off', 'tone' => 'bad'],
            'Application firewall' => ['value' => 'Off', 'tone' => 'bad'],
            'System Integrity Protection' => ['value' => 'On', 'tone' => 'good'],
            'Gatekeeper' => ['value' => 'On', 'tone' => 'good'],
            'Automatic login' => ['value' => 'On', 'tone' => 'bad'],
            'Backup' => ['value' => 'Not set up', 'tone' => 'bad'],
            'Remote Login' => ['value' => 'Off', 'tone' => 'good'],
            'Screen Sharing' => ['value' => 'Off', 'tone' => 'good'],
            'Find My Mac' => ['value' => 'On', 'tone' => 'good'],
        ]);
    });

    it('reads FileVault in every wording', function (string $line, string $value, string $tone) {
        expect(macSecurity($line)['FileVault'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'on' => ['FileVault is On.', 'On', 'good'],
        'off' => ['FileVault is Off.', 'Off', 'bad'],
        'on with a restart note' => ["FileVault is On.\nPlease restart the computer to complete the encryption.", 'On', 'good'],
        'off but pending' => ['FileVault is Off, but will be enabled after the next restart.', 'Turns on after the next restart', 'neutral'],
        'lower case' => ['filevault is on.', 'On', 'good'],
        'an error' => ['Error: unable to read the FileVault state', 'Not checked', 'neutral'],
        'could not check' => ['FileVault: could not check', 'Not checked', 'neutral'],
        'nothing' => ['Stealth mode is off', 'Not checked', 'neutral'],
    ]);

    it('reads the firewall states', function (string $line, string $value, string $tone) {
        expect(macSecurity($line)['Application firewall'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'enabled' => ['Firewall is enabled. (State = 1)', 'On', 'good'],
        'blocking everything' => ['Firewall is enabled. (State = 2)', 'On', 'good'],
        'disabled' => ['Firewall is disabled. (State = 0)', 'Off', 'bad'],
        'enabled without a state' => ['Firewall is enabled.', 'On', 'good'],
        'disabled without a state' => ['Firewall is disabled.', 'Off', 'bad'],
        'a state it does not know' => ['Firewall is enabled. (State = 7)', 'Not checked', 'neutral'],
        'a word it does not know' => ['Firewall is unknown. (State = 1)', 'Not checked', 'neutral'],
        'a missing bracket' => ['Firewall is enabled. (State = 1', 'On', 'good'],
        'nothing' => ['Stealth mode is off', 'Not checked', 'neutral'],
    ]);

    it('reads System Integrity Protection and Gatekeeper', function (string $line, string $label, string $value, string $tone) {
        expect(macSecurity($line)[$label])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'sip on' => ['System Integrity Protection status: enabled.', 'System Integrity Protection', 'On', 'good'],
        'sip off' => ['System Integrity Protection status: disabled.', 'System Integrity Protection', 'Off', 'bad'],
        'sip custom' => ['System Integrity Protection status: enabled (Custom Configuration).', 'System Integrity Protection', 'On with custom settings', 'neutral'],
        'sip unknown' => ['System Integrity Protection status: unknown', 'System Integrity Protection', 'Not checked', 'neutral'],
        'gatekeeper on' => ['assessments enabled', 'Gatekeeper', 'On', 'good'],
        'gatekeeper off' => ['assessments disabled', 'Gatekeeper', 'Off', 'bad'],
        'gatekeeper unreadable' => ['spctl: command not found', 'Gatekeeper', 'Not checked', 'neutral'],
    ]);

    it('takes the first of two contradicting lines', function () {
        $rows = macSecurity("FileVault is Off.\nFileVault is On.\nFirewall is enabled. (State = 1)\nFirewall is disabled. (State = 0)");

        expect($rows['FileVault']['value'])->toBe('Off')
            ->and($rows['Application firewall']['value'])->toBe('On');
    });

    it('ignores a line that is only part of another sentence', function () {
        expect(macSecurity('Note that FileVault is On. somewhere')['FileVault']['value'])->toBe('Not checked');
    });

    it('reads the remote exposure lines', function (string $body, array $expected) {
        $rows = securityRows(securityDoc(securityBlock('Remote exposure', $body)));

        expect($rows)->toBe($expected);
    })->with([
        'all on' => [
            "Remote Login (SSH): on (something is listening on port 22)\nScreen Sharing: on (something is listening on port 5900)\nAutomatic login: on (the account name is not recorded)",
            [
                'Automatic login' => ['value' => 'On', 'tone' => 'bad'],
                'Remote Login' => ['value' => 'On', 'tone' => 'bad'],
                'Screen Sharing' => ['value' => 'On', 'tone' => 'bad'],
            ],
        ],
        'all off' => [
            "Remote Login (SSH): off\nScreen Sharing: off\nAutomatic login: off",
            [
                'Automatic login' => ['value' => 'Off', 'tone' => 'good'],
                'Remote Login' => ['value' => 'Off', 'tone' => 'good'],
                'Screen Sharing' => ['value' => 'Off', 'tone' => 'good'],
            ],
        ],
        'netstat did not answer' => [
            "Remote Login (SSH): could not check (netstat did not answer)\nScreen Sharing: could not check (netstat did not answer)\nAutomatic login: off",
            [
                'Automatic login' => ['value' => 'Off', 'tone' => 'good'],
                'Remote Login' => ['value' => 'Not checked', 'tone' => 'neutral'],
                'Screen Sharing' => ['value' => 'Not checked', 'tone' => 'neutral'],
            ],
        ],
        'only one line' => [
            'Automatic login: off',
            ['Automatic login' => ['value' => 'Off', 'tone' => 'good']],
        ],
    ]);

    it('reads Time Machine', function (string $line, string $value, string $tone) {
        $rows = securityRows(securityDoc(securityBlock('Backup status', $line)));

        expect($rows['Backup'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'set up' => ["Time Machine: set up\nLast backup: 2026-10-30 (4 days ago)", 'Set up', 'good'],
        'not set up' => ['Time Machine: not set up', 'Not set up', 'bad'],
        'could not check' => ['Time Machine: could not check (tmutil did not answer)', 'Not checked', 'neutral'],
        'something else' => ['Time Machine: maybe', 'Not checked', 'neutral'],
    ]);

    it('reads Find My Mac', function (string $body, string $value, string $tone) {
        $rows = securityRows(securityDoc(securityBlock('Activation Lock and Find My', $body)));

        expect($rows['Find My Mac'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'on' => ["Activation Lock Status: Disabled\nFind My Mac: on", 'On', 'good'],
        'off' => ['Find My Mac: off', 'Off', 'bad'],
        'could not check' => ['Find My Mac: could not check', 'Not checked', 'neutral'],
        'only the activation lock line' => ['Activation Lock Status: Disabled', 'Not checked', 'neutral'],
    ]);

    it('leaves out the rows of a section the report does not have', function () {
        $rows = securityRows(securityDoc(securityBlock('Backup status', 'Time Machine: set up')));

        expect(array_keys($rows))->toBe(['Backup']);
    });

    it('does not read a section heading inside a code fence', function () {
        $body = "FileVault is On.\n## Remote exposure\nAutomatic login: on";

        expect(array_keys(macSecurity($body)))->not->toContain('Automatic login');
    });

    it('survives Windows line endings', function () {
        expect(securityRows(str_replace("\n", "\r\n", macReportFixture())))->toBe(securityRows(macReportFixture()));
    });

    it('never turns report text into values', function () {
        $rows = macSecurity("<script>alert(1)</script>\nFileVault is Off.");
        $encoded = (string) json_encode($rows);

        expect($rows['FileVault']['value'])->toBe('Off')
            ->and($encoded)->not->toContain('<')->not->toContain('undefined')->not->toContain('NaN');
    });
});

describe('a Windows PC', function () {
    it('summarises the sample laptop report', function () {
        expect(securityRows(windowsLaptopFixture()))->toBe([
            'Disk encryption' => ['value' => 'On', 'tone' => 'good'],
            'Windows Firewall' => ['value' => 'Off for Public', 'tone' => 'bad'],
            'Defender real-time protection' => ['value' => 'On', 'tone' => 'good'],
            'Automatic sign-in' => ['value' => 'Off', 'tone' => 'good'],
            'Remote Desktop' => ['value' => 'On', 'tone' => 'bad'],
            'SMBv1' => ['value' => 'Off', 'tone' => 'good'],
            'Backup' => ['value' => 'OneDrive folders', 'tone' => 'good'],
        ]);
    });

    it('reads BitLocker in every wording', function (string $line, string $value, string $tone) {
        $rows = securityRows(securityDoc(securityBlock('Security: Secure Boot, TPM, BitLocker', "Secure Boot: on\n{$line}")));

        expect($rows['Disk encryption'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'explorer on' => ['BitLocker:   on (C:)', 'On', 'good'],
        'explorer off' => ['BitLocker:   OFF (C:)', 'Off', 'bad'],
        'admin on' => ['BitLocker:   On (FullyEncrypted) (C:)', 'On', 'good'],
        'admin off' => ['BitLocker:   Off (FullyDecrypted) (C:)', 'Off', 'bad'],
        'needs admin' => ['BitLocker:   unavailable (needs admin) (C:)', 'Not checked', 'neutral'],
        'state code' => ['BitLocker:   state code 4 (see Explorer drive properties) (C:)', 'Not checked', 'neutral'],
        'missing' => ['Run as administrator: True', 'Not checked', 'neutral'],
    ]);

    it('reads the firewall profiles', function (string $body, string $value, string $tone) {
        $rows = securityRows(securityDoc(securityBlock('Security: firewall', $body)));

        expect($rows['Windows Firewall'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'all on' => ["Name    Enabled\n----    -------\nDomain     True\nPrivate    True\nPublic     True", 'On', 'good'],
        'two off' => ["Domain    False\nPrivate   True\nPublic    False", 'Off for Domain and Public', 'bad'],
        'one profile only' => ['Domain True', 'Not checked', 'neutral'],
        'unavailable' => ['unavailable (needs admin)', 'Not checked', 'neutral'],
    ]);

    it('reads Defender', function (string $body, string $value, string $tone) {
        $rows = securityRows(securityDoc(securityBlock('Security: Defender', $body)));

        expect($rows['Defender real-time protection'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'on' => ['RealTimeProtectionEnabled     : True', 'On', 'good'],
        'off' => ['RealTimeProtectionEnabled     : False', 'Off', 'bad'],
        'off with another antivirus' => ["RealTimeProtectionEnabled     : False\nOther antivirus registered: Contoso AV", 'Off, another antivirus is registered', 'neutral'],
        'unavailable' => ['unavailable (needs admin)', 'Not checked', 'neutral'],
    ]);

    it('reads the remote exposure lines', function (string $body, array $expected) {
        $rows = securityRows(securityDoc(securityBlock('Remote exposure', $body)));

        expect($rows)->toBe($expected);
    })->with([
        'all bad' => [
            "Remote Desktop (RDP): ENABLED (network level authentication required: no)\nSMBv1: ENABLED\nAutomatic sign-in: ON (the stored password is not read)",
            [
                'Automatic sign-in' => ['value' => 'On', 'tone' => 'bad'],
                'Remote Desktop' => ['value' => 'On', 'tone' => 'bad'],
                'SMBv1' => ['value' => 'On', 'tone' => 'bad'],
            ],
        ],
        'all good' => [
            "Remote Desktop (RDP): off\nSMBv1: off\nAutomatic sign-in: off",
            [
                'Automatic sign-in' => ['value' => 'Off', 'tone' => 'good'],
                'Remote Desktop' => ['value' => 'Off', 'tone' => 'good'],
                'SMBv1' => ['value' => 'Off', 'tone' => 'good'],
            ],
        ],
        'all unavailable' => [
            "Remote Desktop (RDP): unavailable (needs admin)\nSMBv1: unavailable (administrator rights are needed to see whether SMBv1 is on)\nAutomatic sign-in: unavailable (denied)",
            [
                'Automatic sign-in' => ['value' => 'Not checked', 'tone' => 'neutral'],
                'Remote Desktop' => ['value' => 'Not checked', 'tone' => 'neutral'],
                'SMBv1' => ['value' => 'Not checked', 'tone' => 'neutral'],
            ],
        ],
    ]);

    it('reads the backup lines', function (string $body, string $value, string $tone) {
        $rows = securityRows(securityDoc(securityBlock('Backup status', $body)));

        expect($rows['Backup'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'file history only' => ["File History: set up\nLast File History backup: 2026-10-30 (4 days ago)\nOneDrive signed in: no\nFolders saved to OneDrive (Windows Backup): none", 'File History', 'good'],
        'both' => ["File History: set up\nOneDrive signed in: yes\nFolders saved to OneDrive (Windows Backup): Desktop, Pictures", 'File History and OneDrive folders', 'good'],
        'neither' => ["File History: not set up\nOneDrive signed in: no\nFolders saved to OneDrive (Windows Backup): none", 'Not set up', 'bad'],
        'file history unreadable, onedrive has folders' => ["File History: unavailable (denied)\nOneDrive signed in: yes\nFolders saved to OneDrive (Windows Backup): Documents", 'OneDrive folders', 'good'],
        'file history unreadable, nothing else' => ["File History: unavailable (denied)\nOneDrive signed in: no\nFolders saved to OneDrive (Windows Backup): none", 'Not checked', 'neutral'],
        'only the file history line' => ['File History: not set up', 'Not checked', 'neutral'],
    ]);
});

it('has no card for a report without any security data', function (string $markdown) {
    expect(securityRows($markdown))->toBe([]);
})->with([
    'empty' => [''],
    'a title only' => ["# Report\n"],
    'an old script' => ["# Mac checkup: Old\n\n- Format: 1\n- OS: macOS\n- Free disk: 30%\n"],
    'an empty section' => [securityDoc("## Remote exposure\n\n```\n```\n")],
    'a heading in a fence' => ["# Report\n\n## Disk usage\n\n```\n## Remote exposure\nAutomatic login: on\n```\n"],
]);
