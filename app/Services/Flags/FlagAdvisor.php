<?php

declare(strict_types=1);

namespace App\Services\Flags;

use App\Enums\FlagSeverity;
use App\Services\Reports\MacUpdateOutlook;
use App\Services\Reports\ReportSections;

final class FlagAdvisor
{
    public const UNSUPPORTED_OS = '/end of support|out of security support/';

    private const NO_UPDATES = 'no_updates_installed';

    /**
     * The first matching rule wins. The third entry is the key of its texts in lang/{locale}/flags.php, the fourth names the pattern's capture groups as placeholders in those texts.
     *
     * @var list<array{0: string, 1: FlagSeverity, 2: string, 3: list<string>}>
     */
    private const RULES = [
        ['/^Disk (?:SMART status|.+? (?:health|status)) is /', FlagSeverity::High, 'disk_problem', []],
        ['/disk error event/', FlagSeverity::High, 'disk_errors', []],
        ['/SSD wear is (\d+)%/', FlagSeverity::High, 'ssd_wear', ['percent']],
        ['/^(\d+) unexpected shutdown/', FlagSeverity::High, 'unexpected_shutdowns', ['count']],
        ['/^(\d+) kernel panic/', FlagSeverity::High, 'kernel_panics', ['count']],
        ['/^Only about (\d+)% of drive (\S+) is free/', FlagSeverity::High, 'drive_full', ['percent', 'drive']],
        ['/^Only about (\d+)% of disk is free/', FlagSeverity::High, 'disk_full', ['percent']],
        ['/Defender real-time protection is OFF/', FlagSeverity::High, 'no_antivirus', []],
        [self::UNSUPPORTED_OS, FlagSeverity::High, 'unsupported_os', []],
        ['/System Integrity Protection is DISABLED/', FlagSeverity::High, 'sip_disabled', []],
        ['/Gatekeeper is DISABLED/', FlagSeverity::High, 'gatekeeper_disabled', []],
        ['/^SMBv1 is enabled/', FlagSeverity::High, 'smbv1', []],
        ['/^Remote Desktop \(RDP\) is enabled/', FlagSeverity::High, 'rdp', []],
        ['/^(\d+) enabled account\(s\) are not required to have a password/', FlagSeverity::Medium, 'no_password_accounts', ['count']],
        ['/^Windows is not activated/', FlagSeverity::Medium, 'windows_not_activated', []],
        ['/^BitLocker is (?:not protecting|OFF)/', FlagSeverity::Medium, 'encryption_off', []],
        ['/^FileVault is OFF/', FlagSeverity::Medium, 'encryption_off', []],
        ['/^Secure Boot is (?:OFF|not available)/', FlagSeverity::Medium, 'secure_boot', []],
        ['/^No TPM found/', FlagSeverity::Medium, 'no_tpm', []],
        ['/^TPM is present but not ready/', FlagSeverity::Medium, 'tpm_not_ready', []],
        ['/firewall is off/i', FlagSeverity::Medium, 'firewall_off', []],
        ['/^Defender signatures are (\d+) days old/', FlagSeverity::Medium, 'defender_old', ['days']],
        ['/^Windows updates are pending.*?(\d+)\.$/', FlagSeverity::Medium, 'windows_updates_pending', ['count']],
        ['/^Software updates are pending/', FlagSeverity::Medium, 'software_updates_pending', []],
        ['/^Only (\d+) GB of RAM/', FlagSeverity::Medium, 'low_ram', ['gb']],
        ['/of memory is (?:available|free)|short on RAM/', FlagSeverity::Medium, 'short_memory', []],
        ['/spinning hard drives/', FlagSeverity::Medium, 'spinning_drive', []],
        ['/^(\d+) device\(s\) report a driver problem/', FlagSeverity::Medium, 'driver_problems', ['count']],
        ['/^Battery /', FlagSeverity::Medium, 'battery_worn', []],
        ['/^Activation Lock is ENABLED/', FlagSeverity::Medium, 'activation_lock', []],
        ['/^Remote Login \(SSH\) is on/', FlagSeverity::Medium, 'remote_login', []],
        ['/^Screen Sharing is on/', FlagSeverity::Medium, 'screen_sharing', []],
        ['/^Automatic sign-in is on/', FlagSeverity::Medium, 'auto_signin', []],
        ['/^No backup was found/', FlagSeverity::Medium, 'no_backup', []],
        ['/^The last backup was (\d+) days ago/', FlagSeverity::Medium, 'old_backup', ['days']],
        ['/^No (?:Windows|macOS) update has been installed for (\d+) days/', FlagSeverity::Medium, self::NO_UPDATES, ['days']],
        ['/^(\d+) accounts have administrator rights/', FlagSeverity::Low, 'many_admins', ['count']],
        ['/^The BIOS \(firmware\) is (\d+) years old/', FlagSeverity::Low, 'old_bios', ['years']],
        ['/tamper protection is off/', FlagSeverity::Low, 'tamper_protection', []],
        ['/^A restart is pending/', FlagSeverity::Low, 'restart_pending', []],
        ['/^(?:Up for|Not restarted for) (\d+) days/', FlagSeverity::Low, 'not_restarted', ['days']],
        ['/^(\d+) programs start with Windows/', FlagSeverity::Low, 'startup_programs', ['count']],
        ['/agents running/', FlagSeverity::Low, 'remote_agents', []],
        ['/managed by an organisation|usually an organisation|Configuration profiles|Custom admin-trusted|Enrolled via/', FlagSeverity::Low, 'managed', []],
        ['/load average|thermally throttled|Low Power Mode|Background indexing/', FlagSeverity::Low, 'running_slow', []],
    ];

    public function advise(string $flag, ?string $locale = null, ?ReportSections $report = null): FlagAdvice
    {
        foreach (self::RULES as [$pattern, $severity, $key, $names]) {
            if (preg_match($pattern, $flag, $matches) === 1) {
                $captures = array_slice($matches, 1, count($names));
                $values = array_combine($names, $captures);

                return new FlagAdvice(
                    $severity,
                    trans("flags.$key.headline", $values, $locale),
                    $this->advice($key, $locale, $report),
                    $flag,
                    trans("flags.$key.headline", array_map($this->withoutNumbers(...), $values), 'en'),
                    $key,
                );
            }
        }

        return new FlagAdvice(FlagSeverity::Low, rtrim($flag, '.'), null, $flag, $this->withoutNumbers(rtrim($flag, '.')));
    }

    private function advice(string $key, ?string $locale, ?ReportSections $report): string
    {
        $outlook = $key === self::NO_UPDATES && $report !== null ? (new MacUpdateOutlook)->read($report) : null;

        return match (true) {
            $outlook === null => trans("flags.$key.advice", [], $locale),
            $outlook['newest'] === null => trans("flags.$key.advice_already_newest", ['running' => $outlook['running']], $locale),
            ! $outlook['newestSupported'] => trans("flags.$key.advice_newest_unsupported", ['running' => $outlook['running'], 'newest' => $outlook['newest']], $locale),
            default => trans("flags.$key.advice_no_more_updates", ['running' => $outlook['running'], 'newest' => $outlook['newest']], $locale),
        };
    }

    private function withoutNumbers(string $text): string
    {
        return preg_replace('/\d+(?:[.,]\d+)*/', '#', $text) ?? $text;
    }

    /**
     * @param  list<string>  $flags
     * @return list<FlagAdvice>
     */
    public function adviseAll(array $flags, ?ReportSections $report = null): array
    {
        $advice = array_map(fn (string $flag): FlagAdvice => $this->advise($flag, null, $report), $flags);

        usort($advice, fn (FlagAdvice $a, FlagAdvice $b): int => $a->severity->rank() <=> $b->severity->rank());

        return $advice;
    }
}
