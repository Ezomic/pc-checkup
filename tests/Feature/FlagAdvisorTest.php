<?php

declare(strict_types=1);

use App\Enums\FlagSeverity;
use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\ReportSections;

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
    ['SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).', FlagSeverity::High, 'An outdated file sharing feature is on'],
    ['Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.', FlagSeverity::High, 'Remote Desktop is turned on'],
    ['2 enabled account(s) are not required to have a password (see Administrator accounts section).', FlagSeverity::Medium, 'Some accounts need no password'],
    ['Remote Login (SSH) is on, so other computers can try to sign in to this Mac.', FlagSeverity::Medium, 'Remote Login is turned on'],
    ['Screen Sharing is on, so other people on the network can ask to see or control this Mac.', FlagSeverity::Medium, 'Screen Sharing is turned on'],
    ['Automatic sign-in is on, so anyone who starts this computer gets in without a password.', FlagSeverity::Medium, 'The computer signs in by itself'],
    ['No backup was found (File History is off and Desktop, Documents and Pictures are not saved to OneDrive).', FlagSeverity::Medium, 'No backup was found'],
    ['No backup was found (Time Machine is not set up).', FlagSeverity::Medium, 'No backup was found'],
    ['The last backup was 45 days ago (File History).', FlagSeverity::Medium, 'The last backup was 45 days ago'],
    ['The last backup was 61 days ago (Time Machine).', FlagSeverity::Medium, 'The last backup was 61 days ago'],
    ['No Windows update has been installed for 75 days (newest: 2026-08-19).', FlagSeverity::Medium, 'No updates installed for 75 days'],
    ['No macOS update has been installed for 90 days (newest: 2026-08-03).', FlagSeverity::Medium, 'No updates installed for 90 days'],
    ['4 accounts have administrator rights (see Administrator accounts section).', FlagSeverity::Low, '4 accounts have administrator rights'],
    ['The BIOS (firmware) is 7 years old (released 2019-05-02).', FlagSeverity::Low, 'The BIOS is 7 years old'],
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

it('gives the same issue the same key when only a number changed', function () {
    $advisor = new FlagAdvisor;

    expect($advisor->advise('Only about 7% of drive C: is free.')->key)
        ->toBe($advisor->advise('Only about 9% of drive C: is free.')->key)
        ->and($advisor->advise('Only 4 GB of RAM.')->key)->toBe($advisor->advise('Only 8 GB of RAM.')->key);
});

it('keeps the drive in the key so C: and D: are different issues', function () {
    $advisor = new FlagAdvisor;

    expect($advisor->advise('Only about 7% of drive C: is free.')->key)
        ->not->toBe($advisor->advise('Only about 7% of drive D: is free.')->key);
});

it('gives rules that share a headline the same key', function () {
    $advisor = new FlagAdvisor;

    expect($advisor->advise('FileVault is OFF.')->key)->toBe($advisor->advise('BitLocker is OFF on C:.')->key);
});

it('keys a flag no rule recognises by its text without digits', function () {
    $advisor = new FlagAdvisor;

    expect($advisor->advise('The toaster ran 5 times.')->key)
        ->toBe($advisor->advise('The toaster ran 9 times.')->key)
        ->not->toBe($advisor->advise('The kettle ran 5 times.')->key);
});

it('keeps the key out of the front end payload', function () {
    expect((new FlagAdvisor)->advise('FileVault is OFF.')->toArray())->not->toHaveKey('key');
});

it('translates every known flag into Dutch', function (string $flag, mixed $severity, string $english) {
    $dutch = (new FlagAdvisor)->advise($flag, 'nl');
    $en = (new FlagAdvisor)->advise($flag, 'en');

    expect($en->headline)->toBe($english)
        ->and($dutch->headline)->not->toBe($en->headline)
        ->and($dutch->advice)->not->toBeNull()->not->toBe($en->advice)
        ->and($dutch->key)->toBe($en->key)
        ->and($dutch->severity)->toBe($en->severity);
})->with('flags');

describe('no updates on a macOS that gets no more updates', function () {
    function faNoUpdates(): string
    {
        return 'No macOS update has been installed for 1751 days (newest: 2022-01-24).';
    }

    function faOldMac(string $macos = '10.14.6 (18G9323)', string $updates = "No new software available.\nSoftware Update Tool\n\nFinding available software", string $model = 'MacBook Air (MacBookAir7,2)'): ReportSections
    {
        return ReportSections::parse("# Mac checkup: Test-Mac\n\n- Model: {$model}\n- macOS: {$macos}\n\n## Software updates\n\n```\n{$updates}\n```\n");
    }

    it('says what is true instead of asking to install updates', function (string $locale, string $text) {
        $advice = (new FlagAdvisor)->advise(faNoUpdates(), $locale, faOldMac());

        expect($advice->advice)->toBe($text)
            ->and($advice->headline)->toBe((new FlagAdvisor)->advise(faNoUpdates(), $locale)->headline)
            ->and($advice->severity)->toBe(FlagSeverity::Medium);
    })->with([
        'English' => ['en', 'No more updates come out for macOS 10.14 Mojave. The newest macOS this Mac can run, macOS 12 Monterey, no longer gets security updates either.'],
        'Dutch' => ['nl', 'Voor macOS 10.14 Mojave komen geen updates meer uit. De nieuwste macOS die op deze Mac kan draaien, macOS 12 Monterey, krijgt ook geen beveiligingsupdates meer.'],
    ]);

    it('names a newest macOS that Apple still supports', function (string $locale, string $text) {
        expect((new FlagAdvisor)->advise(faNoUpdates(), $locale, faOldMac('12.7.6 (21H1320)', model: 'MacBook Air (MacBookAir8,1)'))->advice)->toBe($text);
    })->with([
        'English' => ['en', 'No more updates come out for macOS 12 Monterey. The newest macOS this Mac can run is macOS 14 Sonoma.'],
        'Dutch' => ['nl', 'Voor macOS 12 Monterey komen geen updates meer uit. De nieuwste macOS die op deze Mac kan draaien, is macOS 14 Sonoma.'],
    ]);

    it('says when the Mac already runs the newest macOS it can', function (string $locale, string $text) {
        expect((new FlagAdvisor)->advise(faNoUpdates(), $locale, faOldMac('12.7.6 (21H1320)'))->advice)->toBe($text);
    })->with([
        'English' => ['en', 'This Mac already runs the newest macOS it can, macOS 12 Monterey, and it no longer gets security updates.'],
        'Dutch' => ['nl', 'Deze Mac heeft al de nieuwste macOS die erop kan draaien, macOS 12 Monterey, en krijgt geen beveiligingsupdates meer.'],
    ]);

    it('passes the report through when advising every flag', function () {
        $advice = (new FlagAdvisor)->adviseAll(['macOS 10.14.6 is old and likely out of security support.', faNoUpdates()], faOldMac());

        expect(array_column(array_map(fn ($a) => $a->toArray(), $advice), 'advice', 'raw')[faNoUpdates()])
            ->toBe('No more updates come out for macOS 10.14 Mojave. The newest macOS this Mac can run, macOS 12 Monterey, no longer gets security updates either.');
    });

    it('keeps the usual advice when it cannot tell', function (?ReportSections $sections) {
        expect((new FlagAdvisor)->advise(faNoUpdates(), 'en', $sections)->advice)->toBe('Install the available updates. They carry security fixes.');
    })->with([
        'no report' => null,
        'updates waiting' => fn () => faOldMac(updates: "Software Update found the following new or updated software:\n   * Security Update 2021-005-10.14.6"),
        'the check did not finish' => fn () => faOldMac(updates: 'Software Update Tool'),
        'an unknown model' => fn () => faOldMac(model: 'MacBook Air (MacBookAir99,9)'),
        'a macOS that still gets updates' => fn () => faOldMac('14.7.1 (23H222)', model: 'MacBook Air (MacBookAir9,1)'),
        'a Windows report' => fn () => ReportSections::parse("# Windows checkup: PC\n\n- Windows: Windows 10 Pro 22H2\n"),
    ]);

    it('leaves every other flag and the key alone', function () {
        $advisor = new FlagAdvisor;

        expect($advisor->advise('FileVault is OFF.', 'en', faOldMac()))->toEqual($advisor->advise('FileVault is OFF.', 'en'))
            ->and($advisor->advise(faNoUpdates(), 'en', faOldMac())->key)->toBe($advisor->advise(faNoUpdates(), 'en')->key);
    });
});
