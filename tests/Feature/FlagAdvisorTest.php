<?php

declare(strict_types=1);

use App\Enums\FlagSeverity;
use App\Services\Flags\FlagAdvisor;

dataset('flags', [
    ["Disk 'Samsung SSD 990' health is 'Warning'.", FlagSeverity::High, 'A disk is reporting problems'],
    ["Disk 'WDC WD10' status is 'Pred Fail'.", FlagSeverity::High, 'A disk is reporting problems'],
    ["Disk SMART status is 'Failing'.", FlagSeverity::High, 'A disk is reporting problems'],
    ['1 disk error event(s) in the last 30 days (bad blocks or controller errors).', FlagSeverity::High, 'Disk errors were logged'],
    ['Disk 0 SSD wear is 85% (near end of life).', FlagSeverity::High, 'An SSD is 85% worn out'],
    ['10 unexpected shutdown(s) in the last 30 days (crash, power loss or forced power off).', FlagSeverity::High, 'Crashed or lost power 10 times this month'],
    ['3 kernel panic report(s) found in /Library/Logs/DiagnosticReports.', FlagSeverity::High, 'The Mac crashed 3 times'],
    ['Only about 5% of drive C: is free.', FlagSeverity::High, 'Drive C: is almost full, only 5% left'],
    ['Only about 9% of disk is free.', FlagSeverity::High, 'The disk is almost full, only 9% left'],
    ['Defender real-time protection is OFF and no other antivirus was found.', FlagSeverity::High, 'No antivirus is protecting this PC'],
    ['Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.', FlagSeverity::High, 'The operating system is no longer supported'],
    ['macOS 12.7 is old and likely out of security support.', FlagSeverity::High, 'The operating system is no longer supported'],
    ['System Integrity Protection is DISABLED.', FlagSeverity::High, 'A core macOS protection is turned off'],
    ['Gatekeeper is DISABLED.', FlagSeverity::High, 'macOS is not checking apps before they run'],
    ['Windows is not activated (status: Notification).', FlagSeverity::Medium, 'Windows is not activated'],
    ['BitLocker is not protecting C: (Off).', FlagSeverity::Medium, 'Disk encryption is off'],
    ['BitLocker is OFF on C:.', FlagSeverity::Medium, 'Disk encryption is off'],
    ['FileVault is OFF.', FlagSeverity::Medium, 'Disk encryption is off'],
    ['Secure Boot is OFF.', FlagSeverity::Medium, 'Secure Boot is off'],
    ['Secure Boot is not available (legacy BIOS boot or unsupported firmware).', FlagSeverity::Medium, 'Secure Boot is off'],
    ['No TPM found (Windows 11 needs TPM 2.0).', FlagSeverity::Medium, 'No TPM found'],
    ['TPM is present but not ready.', FlagSeverity::Medium, 'The TPM is not ready'],
    ['Windows Firewall is off for the Public profile.', FlagSeverity::Medium, 'The firewall is off'],
    ['Application firewall is off.', FlagSeverity::Medium, 'The firewall is off'],
    ['Defender signatures are 12 days old.', FlagSeverity::Medium, 'Virus definitions are 12 days old'],
    ['Windows updates are pending (see Windows Update section): 6.', FlagSeverity::Medium, '6 Windows updates are waiting'],
    ['Software updates are pending (see Software updates section).', FlagSeverity::Medium, 'Software updates are waiting'],
    ['Only 4 GB of RAM.', FlagSeverity::Medium, 'Only 4 GB of RAM'],
    ['Only 9% of memory is available (high memory pressure).', FlagSeverity::Medium, 'The machine is short on memory'],
    ['Swap in use is 4096 MB, so the Mac is short on RAM.', FlagSeverity::Medium, 'The machine is short on memory'],
    ['Only spinning hard drives found (no SSD), so the PC will feel slow.', FlagSeverity::Medium, 'It only has a spinning hard drive'],
    ['2 device(s) report a driver problem in Device Manager (see Devices with problems section).', FlagSeverity::Medium, '2 devices have driver problems'],
    ['Battery has 950 cycles (high).', FlagSeverity::Medium, 'The battery is worn'],
    ["Battery condition is 'Service Recommended'.", FlagSeverity::Medium, 'The battery is worn'],
    ["Activation Lock is ENABLED. Needs the previous owner's Apple ID to be removed.", FlagSeverity::Medium, 'Activation Lock is on'],
    ['Defender tamper protection is off.', FlagSeverity::Low, 'Tamper protection is off'],
    ['A restart is pending (Windows Update).', FlagSeverity::Low, 'A restart is pending'],
    ['Up for 45 days since the last full boot (Fast Startup can inflate this).', FlagSeverity::Low, 'Not restarted in 45 days'],
    ['Not restarted for 60 days.', FlagSeverity::Low, 'Not restarted in 60 days'],
    ['17 programs start with Windows (slows boot).', FlagSeverity::Low, '17 apps start with Windows'],
    ['Security or management agents running (can slow a Mac): PasscodeSettingsSubscriber', FlagSeverity::Low, 'Remote access or management software is running'],
    ['Joined to the domain corp.example (managed by an organisation).', FlagSeverity::Low, 'It looks managed by an organisation'],
    ['Custom admin-trusted certificates are installed (see Trusted certificates section).', FlagSeverity::Low, 'It looks managed by an organisation'],
    ['CPU is thermally throttled to 70% (check vents, dust, heavy load).', FlagSeverity::Low, 'It is running slowly right now'],
]);

it('turns a raw flag into plain advice', function (string $flag, FlagSeverity $severity, string $headline) {
    $advice = (new FlagAdvisor)->advise($flag);

    expect($advice->severity)->toBe($severity)
        ->and($advice->headline)->toBe($headline)
        ->and($advice->advice)->not->toBeNull()
        ->and($advice->raw)->toBe($flag);
})->with('flags');

it('falls back to the raw text for a flag it does not know', function () {
    $advice = (new FlagAdvisor)->advise('The toaster is on fire.');

    expect($advice->severity)->toBe(FlagSeverity::Low)
        ->and($advice->headline)->toBe('The toaster is on fire')
        ->and($advice->advice)->toBeNull()
        ->and($advice->raw)->toBe('The toaster is on fire.');
});

it('puts the worst problem first and keeps the order within a severity', function () {
    $advice = (new FlagAdvisor)->adviseAll([
        '17 programs start with Windows (slows boot).',
        'Secure Boot is OFF.',
        'BitLocker is OFF on C:.',
        'Only about 5% of drive C: is free.',
    ]);

    expect(array_map(fn ($a) => $a->headline, $advice))->toBe([
        'Drive C: is almost full, only 5% left',
        'Secure Boot is off',
        'Disk encryption is off',
        '17 apps start with Windows',
    ]);
});

it('serialises the advice for the front end', function () {
    expect((new FlagAdvisor)->advise('FileVault is OFF.')->toArray())->toBe([
        'severity' => 'medium',
        'headline' => 'Disk encryption is off',
        'advice' => 'If the machine is lost or stolen, anyone can read its files. Turn disk encryption on.',
        'raw' => 'FileVault is OFF.',
    ]);
});
