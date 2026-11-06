<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * @phpstan-import-type Row from ReportDetails
 */
final readonly class ReportSecurity
{
    private const NOT_CHECKED = 'Not checked';

    private const PROFILES = ['Domain', 'Private', 'Public'];

    /**
     * @return list<Row>
     */
    public function rows(ReportSections $sections): array
    {
        $mac = $this->text($sections, 'Security: FileVault, SIP, Gatekeeper, firewall');
        $remote = $this->text($sections, 'Remote exposure');
        $backup = $this->text($sections, 'Backup status');
        $windowsSecurity = $this->text($sections, 'Security: Secure Boot, TPM, BitLocker');
        $health = (new ReportWindowsSecurity)->rows($sections);
        $known = array_column($health, 'label');

        return array_values(array_filter([
            $this->fileVault($mac),
            $this->macFirewall($mac),
            $this->integrityProtection($mac),
            $this->toggle('Gatekeeper', $mac, '/^\s*assessments (enabled|disabled)\b/im', true),
            $this->toggle('Automatic login', $remote, '/^\s*Automatic login:\s*(on|off)\b/im', false, '/^\s*Automatic login:/im'),
            $this->timeMachine($backup),
            $this->toggle('Remote Login', $remote, '/^\s*Remote Login \(SSH\):\s*(on|off)\b/im', false, '/^\s*Remote Login \(SSH\):/im'),
            $this->toggle('Screen Sharing', $remote, '/^\s*Screen Sharing:\s*(on|off)\b/im', false, '/^\s*Screen Sharing:/im'),
            $this->toggle('Find My Mac', $this->text($sections, 'Activation Lock and Find My'), '/^\s*Find My Mac:\s*(on|off)\b/im', true),
            ...$health,
            $this->covered($known, 'Disk encryption') ? null : $this->toggle('Disk encryption', $windowsSecurity, '/^\s*BitLocker:\s*(on|off)\b/im', true),
            $this->covered($known, 'Windows Firewall') ? null : $this->windowsFirewall($this->text($sections, 'Security: firewall')),
            $this->covered($known, 'Antivirus') ? null : $this->defender($this->text($sections, 'Security: Defender')),
            $this->toggle('Automatic sign-in', $remote, '/^\s*Automatic sign-in:\s*(on|off)\b/im', false, '/^\s*Automatic sign-in:/im'),
            $this->covered($known, 'Remote Desktop') ? null : $this->toggle('Remote Desktop', $remote, '/^\s*Remote Desktop \(RDP\):\s*(enabled|off)\b/im', false, '/^\s*Remote Desktop \(RDP\):/im'),
            $this->covered($known, 'SMBv1') ? null : $this->toggle('SMBv1', $remote, '/^\s*SMBv1:\s*(enabled|off)\b/im', false, '/^\s*SMBv1:/im'),
            $this->windowsBackup($backup),
        ]));
    }

    /**
     * @param  list<string>  $known
     */
    private function covered(array $known, string $label): bool
    {
        return in_array($label, $known, true);
    }

    private function text(ReportSections $sections, string $title): ?string
    {
        $text = $sections->text($title);

        return $text === null || $text === '' ? null : $text;
    }

    /**
     * @return Row|null
     */
    private function fileVault(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }

        if (preg_match('/^\s*FileVault is (on|off)\b(,? but will be enabled)?/im', $text, $match) !== 1) {
            return $this->notChecked('FileVault');
        }

        if (isset($match[2])) {
            return $this->row('FileVault', 'Turns on after the next restart', 'neutral');
        }

        return $this->state('FileVault', $match[1], true);
    }

    /**
     * @return Row|null
     */
    private function macFirewall(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }

        if (preg_match('/^\s*Firewall is (enabled|disabled)\b[^\n(]*(?:\(State = (\d))?/im', $text, $match) !== 1) {
            return $this->notChecked('Application firewall');
        }

        $state = $match[2] ?? null;

        return match (true) {
            $state === '0' => $this->state('Application firewall', 'off', true),
            $state === '1', $state === '2' => $this->state('Application firewall', 'on', true),
            $state !== null => $this->notChecked('Application firewall'),
            default => $this->state('Application firewall', $match[1], true),
        };
    }

    /**
     * @return Row|null
     */
    private function integrityProtection(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }

        $label = 'System Integrity Protection';

        if (preg_match('/^\s*System Integrity Protection status:\s*(enabled|disabled)\b(\s*\(Custom Configuration\))?/im', $text, $match) !== 1) {
            return $this->notChecked($label);
        }

        if (strtolower($match[1]) === 'enabled' && isset($match[2])) {
            return $this->row($label, 'On with custom settings', 'neutral');
        }

        return $this->state($label, $match[1], true);
    }

    /**
     * @return Row|null
     */
    private function timeMachine(?string $text): ?array
    {
        if ($text === null || preg_match('/^\s*Time Machine:/im', $text) !== 1) {
            return null;
        }

        if (preg_match('/^\s*Time Machine:\s*(not set up|set up)\b/im', $text, $match) !== 1) {
            return $this->notChecked('Backup');
        }

        return strtolower($match[1]) === 'set up'
            ? $this->row('Backup', 'Set up', 'good')
            : $this->row('Backup', 'Not set up', 'bad');
    }

    /**
     * @return Row|null
     */
    private function windowsFirewall(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }

        $profiles = [];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(Domain|Private|Public)\s+(True|False)\s*$/i', $line, $match) === 1) {
                $profiles[ucfirst(strtolower($match[1]))] ??= strtolower($match[2]) === 'true';
            }
        }

        $off = array_values(array_filter(self::PROFILES, fn (string $name): bool => ($profiles[$name] ?? true) === false));

        return match (true) {
            $off !== [] => $this->row('Windows Firewall', 'Off for '.$this->join($off), 'bad'),
            count($profiles) === count(self::PROFILES) => $this->row('Windows Firewall', 'On', 'good'),
            default => $this->notChecked('Windows Firewall'),
        };
    }

    /**
     * @return Row|null
     */
    private function defender(?string $text): ?array
    {
        $label = 'Defender real-time protection';

        if ($text === null) {
            return null;
        }

        if (preg_match('/^\s*RealTimeProtectionEnabled\s*:\s*(True|False)\s*$/im', $text, $match) !== 1) {
            return $this->notChecked($label);
        }

        if (strtolower($match[1]) === 'true') {
            return $this->row($label, 'On', 'good');
        }

        return preg_match('/^\s*Other antivirus registered:\s*\S/im', $text) === 1
            ? $this->row($label, 'Off, another antivirus is registered', 'neutral')
            : $this->row($label, 'Off', 'bad');
    }

    /**
     * @return Row|null
     */
    private function windowsBackup(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }

        $history = preg_match('/^\s*File History:\s*(not set up|set up)\b/im', $text, $match) === 1 ? strtolower($match[1]) === 'set up' : null;
        $oneDrive = preg_match('/^\s*Folders saved to OneDrive \(Windows Backup\):\s*(\S.*?)\s*$/im', $text, $match) === 1 ? strtolower($match[1]) !== 'none' : null;

        if ($history === null && $oneDrive === null && preg_match('/^\s*File History:/im', $text) !== 1) {
            return null;
        }

        return match (true) {
            $history === true && $oneDrive === true => $this->row('Backup', 'File History and OneDrive folders', 'good'),
            $history === true => $this->row('Backup', 'File History', 'good'),
            $oneDrive === true => $this->row('Backup', 'OneDrive folders', 'good'),
            $history === false && $oneDrive === false => $this->row('Backup', 'Not set up', 'bad'),
            default => $this->notChecked('Backup'),
        };
    }

    /**
     * @return Row|null
     */
    private function toggle(string $label, ?string $text, string $pattern, bool $goodWhenOn, ?string $present = null): ?array
    {
        if ($text === null) {
            return null;
        }

        if (preg_match($pattern, $text, $match) === 1) {
            return $this->state($label, $match[1], $goodWhenOn);
        }

        return $present === null || preg_match($present, $text) === 1 ? $this->notChecked($label) : null;
    }

    /**
     * @return Row
     */
    private function state(string $label, string $word, bool $goodWhenOn): array
    {
        $on = in_array(strtolower($word), ['on', 'enabled'], true);

        return $this->row($label, $on ? 'On' : 'Off', $on === $goodWhenOn ? 'good' : 'bad');
    }

    /**
     * @return Row
     */
    private function notChecked(string $label): array
    {
        return $this->row($label, self::NOT_CHECKED, 'neutral');
    }

    /**
     * @param  'good'|'bad'|'neutral'  $tone
     * @return Row
     */
    private function row(string $label, string $value, string $tone): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone];
    }

    /**
     * @param  list<string>  $names
     */
    private function join(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }
}
