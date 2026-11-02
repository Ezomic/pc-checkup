<?php

declare(strict_types=1);

namespace App\Services\Flags;

use App\Enums\FlagSeverity;

final class FlagAdvisor
{
    private const DISK_FULL = 'A full drive slows the machine down and can stop updates. Free up space or move files elsewhere.';

    private const FIREWALL = 'Turn it on so unwanted connections are blocked.';

    private const ENCRYPTION = 'If the machine is lost or stolen, anyone can read its files. Turn disk encryption on.';

    /**
     * The first matching rule wins. Headlines are templates where {1}, {2} stand for the pattern's capture groups.
     *
     * @var list<array{0: string, 1: FlagSeverity, 2: string, 3: string}>
     */
    private const RULES = [
        ['/^Disk (?:SMART status|.+? (?:health|status)) is /', FlagSeverity::High, 'A disk is reporting problems', 'Back up your files now, then have the drive checked or replaced.'],
        ['/disk error event/', FlagSeverity::High, 'Disk errors were logged', 'Bad blocks or controller errors can mean the drive is failing. Back up your files and check the drive.'],
        ['/SSD wear is (\d+)%/', FlagSeverity::High, 'An SSD is {1}% worn out', 'It is near the end of its life. Back up your files and plan a replacement.'],
        ['/^(\d+) unexpected shutdown/', FlagSeverity::High, 'Crashed or lost power {1} times this month', 'Check the power supply, temperatures and memory. Repeated crashes often point to failing hardware.'],
        ['/^(\d+) kernel panic/', FlagSeverity::High, 'The Mac crashed {1} times', 'Repeated kernel panics usually mean a hardware or driver fault.'],
        ['/^Only about (\d+)% of drive (\S+) is free/', FlagSeverity::High, 'Drive {2} is almost full, only {1}% left', self::DISK_FULL],
        ['/^Only about (\d+)% of disk is free/', FlagSeverity::High, 'The disk is almost full, only {1}% left', self::DISK_FULL],
        ['/Defender real-time protection is OFF/', FlagSeverity::High, 'No antivirus is protecting this PC', 'Turn on real-time protection in Windows Security, or install an antivirus.'],
        ['/end of support|out of security support/', FlagSeverity::High, 'The operating system is no longer supported', 'It no longer gets free security updates. Upgrade it or replace the machine.'],
        ['/System Integrity Protection is DISABLED/', FlagSeverity::High, 'A core macOS protection is turned off', 'Turn System Integrity Protection back on unless you disabled it on purpose.'],
        ['/Gatekeeper is DISABLED/', FlagSeverity::High, 'macOS is not checking apps before they run', 'Turn Gatekeeper back on in Privacy and Security.'],
        ['/^Windows is not activated/', FlagSeverity::Medium, 'Windows is not activated', 'Activate it with a valid licence to get every feature and update.'],
        ['/^BitLocker is (?:not protecting|OFF)/', FlagSeverity::Medium, 'Disk encryption is off', self::ENCRYPTION],
        ['/^FileVault is OFF/', FlagSeverity::Medium, 'Disk encryption is off', self::ENCRYPTION],
        ['/^Secure Boot is (?:OFF|not available)/', FlagSeverity::Medium, 'Secure Boot is off', 'It blocks malware that starts before Windows. Turn it on in the BIOS or UEFI settings.'],
        ['/^No TPM found/', FlagSeverity::Medium, 'No TPM found', 'Windows 11 needs TPM 2.0. Look for fTPM or PTT in the BIOS, or this machine cannot officially run Windows 11.'],
        ['/^TPM is present but not ready/', FlagSeverity::Medium, 'The TPM is not ready', 'Initialise it in Windows Security under Device security.'],
        ['/firewall is off/i', FlagSeverity::Medium, 'The firewall is off', self::FIREWALL],
        ['/^Defender signatures are (\d+) days old/', FlagSeverity::Medium, 'Virus definitions are {1} days old', 'Run Windows Update so protection stays current.'],
        ['/^Windows updates are pending.*?(\d+)\.$/', FlagSeverity::Medium, '{1} Windows updates are waiting', 'Install them. Updates carry security fixes.'],
        ['/^Software updates are pending/', FlagSeverity::Medium, 'Software updates are waiting', 'Install them. Updates carry security fixes.'],
        ['/^Only (\d+) GB of RAM/', FlagSeverity::Medium, 'Only {1} GB of RAM', 'Adding memory is the cheapest speed-up for an older machine.'],
        ['/of memory is (?:available|free)|short on RAM/', FlagSeverity::Medium, 'The machine is short on memory', 'Close heavy apps or add RAM.'],
        ['/spinning hard drives/', FlagSeverity::Medium, 'It only has a spinning hard drive', 'An SSD makes an older machine feel much faster.'],
        ['/^(\d+) device\(s\) report a driver problem/', FlagSeverity::Medium, '{1} devices have driver problems', 'Open Device Manager and update or reinstall the drivers.'],
        ['/^Battery /', FlagSeverity::Medium, 'The battery is worn', 'It holds less charge than when it was new. Consider a replacement.'],
        ['/^Activation Lock is ENABLED/', FlagSeverity::Medium, 'Activation Lock is on', 'The previous owner\'s Apple ID must be removed before this Mac can be reset or sold.'],
        ['/tamper protection is off/', FlagSeverity::Low, 'Tamper protection is off', 'Turn it on in Windows Security so malware cannot switch protection off.'],
        ['/^A restart is pending/', FlagSeverity::Low, 'A restart is pending', 'Restart to finish installing updates.'],
        ['/^(?:Up for|Not restarted for) (\d+) days/', FlagSeverity::Low, 'Not restarted in {1} days', 'Restarting now and then clears out problems and applies updates.'],
        ['/^(\d+) programs start with Windows/', FlagSeverity::Low, '{1} apps start with Windows', 'Turn off the ones you do not need in Task Manager under Startup.'],
        ['/agents running/', FlagSeverity::Low, 'Remote access or management software is running', 'Make sure you recognise it. Some tools let other people control the machine.'],
        ['/managed by an organisation|usually an organisation|Configuration profiles|Custom admin-trusted|Enrolled via/', FlagSeverity::Low, 'It looks managed by an organisation', 'Work or school policies may limit what you can change.'],
        ['/load average|thermally throttled|Low Power Mode|Background indexing/', FlagSeverity::Low, 'It is running slowly right now', 'Heavy load, low-power mode or heat is slowing it down. Check again later.'],
    ];

    public function advise(string $flag): FlagAdvice
    {
        foreach (self::RULES as [$pattern, $severity, $headline, $advice]) {
            if (preg_match($pattern, $flag, $matches) === 1) {
                return new FlagAdvice($severity, $this->fill($headline, array_slice($matches, 1)), $advice, $flag);
            }
        }

        return new FlagAdvice(FlagSeverity::Low, rtrim($flag, '.'), null, $flag);
    }

    /**
     * @param  array<int, string>  $captures
     */
    private function fill(string $template, array $captures): string
    {
        $replacements = [];

        foreach ($captures as $index => $value) {
            $replacements['{'.($index + 1).'}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @param  list<string>  $flags
     * @return list<FlagAdvice>
     */
    public function adviseAll(array $flags): array
    {
        $advice = array_map($this->advise(...), $flags);

        usort($advice, fn (FlagAdvice $a, FlagAdvice $b): int => $a->severity->rank() <=> $b->severity->rank());

        return $advice;
    }
}
