<?php

declare(strict_types=1);

namespace App\Services\Flags;

use App\Services\Reports\ReportSections;

/**
 * Which parts of a report show that its scan ran the check behind each FlagAdvisor rule, so two scans are
 * only compared on what both of them could have flagged.
 */
final readonly class FlagChecks
{
    /**
     * The report header line both scripts have written since 4 November 2026.
     */
    public const string SCRIPT_VERSION = 'Script version';

    /**
     * Per FlagAdvisor rule, the section titles any one of which shows the check ran, Windows and Mac alike.
     *
     * @var array<string, non-empty-list<string>>
     */
    public const array SECTIONS = [
        'disk_problem' => ['Storage (physical disks)', 'Storage (boot volume)'],
        'disk_errors' => ['Disk error events (last 30 days)'],
        'ssd_wear' => ['Disk wear and errors (SMART counters, needs admin)'],
        'unexpected_shutdowns' => ['Crashes and unexpected shutdowns (last 30 days)'],
        'kernel_panics' => ['Kernel panic reports'],
        'drive_full' => ['Storage (volumes and free space)'],
        'disk_full' => ['Disk usage'],
        'no_antivirus' => ['Security: Defender'],
        // Out of support Windows 11 versions and the raised macOS cut-off came in just before the version line, with no section of their own.
        'unsupported_os' => [self::SCRIPT_VERSION],
        'sip_disabled' => ['Security: FileVault, SIP, Gatekeeper, firewall'],
        'gatekeeper_disabled' => ['Security: FileVault, SIP, Gatekeeper, firewall'],
        'smbv1' => ['Remote exposure'],
        'rdp' => ['Remote exposure'],
        'no_password_accounts' => ['Administrator accounts'],
        'windows_not_activated' => ['Activation'],
        'encryption_off' => ['Security: Secure Boot, TPM, BitLocker', 'Security: FileVault, SIP, Gatekeeper, firewall'],
        'secure_boot' => ['Security: Secure Boot, TPM, BitLocker'],
        'no_tpm' => ['Security: Secure Boot, TPM, BitLocker'],
        'tpm_not_ready' => ['Security: Secure Boot, TPM, BitLocker'],
        'firewall_off' => ['Security: firewall', 'Security: FileVault, SIP, Gatekeeper, firewall'],
        'defender_old' => ['Security: Defender'],
        'windows_updates_pending' => ['Windows Update'],
        'software_updates_pending' => ['Software updates'],
        'low_ram' => ['CPU and memory'],
        'short_memory' => ['CPU and memory', 'Memory pressure', 'Performance: load, swap, memory, throttling'],
        'spinning_drive' => ['Storage (physical disks)'],
        'driver_problems' => ['Devices with problems (Device Manager)'],
        'battery_worn' => ['Battery and power'],
        'activation_lock' => ['Activation Lock and Find My'],
        'remote_login' => ['Remote exposure'],
        'screen_sharing' => ['Remote exposure'],
        'auto_signin' => ['Remote exposure'],
        'no_backup' => ['Backup status'],
        'old_backup' => ['Backup status'],
        // Windows Update listed the newest install long before Windows flagged its age, which came with the BIOS age check.
        'no_updates_installed' => ['Software update age', 'BIOS and firmware age'],
        'many_admins' => ['Administrator accounts'],
        'old_bios' => ['BIOS and firmware age'],
        'tamper_protection' => ['Security: Defender'],
        'restart_pending' => ['Windows Update'],
        'not_restarted' => ['Windows version', 'macOS version'],
        'startup_programs' => ['Startup programs'],
        'remote_agents' => ['Security and remote access agents running', 'Performance: security and management agents'],
        'managed' => ['Domain, Entra ID and MDM', 'Activation', 'MDM and enrolment', 'Trusted certificates: custom trust settings'],
        'running_slow' => ['Performance: load, swap, memory, throttling', 'Performance: 10 second CPU sample (second snapshot is the real one)', 'Performance: Spotlight indexing'],
    ];

    /**
     * Whether only the report that raised a flag shows its check, so the other scan could not have raised it.
     * A flag no rule matched, or a pair where neither report shows the check (an older layout), gives false.
     */
    public function onlyRanIn(?string $rule, ReportSections $raisedIn, ReportSections $other): bool
    {
        $shownBy = $rule === null ? [] : (self::SECTIONS[$rule] ?? []);

        return $this->ran($shownBy, $raisedIn) && ! $this->ran($shownBy, $other);
    }

    /**
     * @param  list<string>  $shownBy
     */
    private function ran(array $shownBy, ReportSections $report): bool
    {
        foreach ($shownBy as $title) {
            $found = $title === self::SCRIPT_VERSION ? $report->header($title) : $report->text($title);

            if ($found !== null) {
                return true;
            }
        }

        return false;
    }
}
