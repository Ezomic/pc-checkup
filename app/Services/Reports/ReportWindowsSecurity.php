<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttentionLevel;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type Row from ReportDetails
 * @phpstan-import-type Facts from ReportWindowsHealth
 * @phpstan-import-type Reason from \App\Services\NeedsAttention
 *
 * @phpstan-type Finding array{row: Row, reason: Reason|null}
 */
final readonly class ReportWindowsSecurity
{
    public const STALE_SIGNATURE_DAYS = 14;

    private const PROFILES = ['Domain', 'Private', 'Public'];

    private const DEFENDER_FLAG = '/^Defender real-time protection is OFF/';

    private const SMB_FLAG = '/^SMBv1 is enabled/';

    public function __construct(private ReportWindowsHealth $health = new ReportWindowsHealth) {}

    /**
     * @return list<Row>
     */
    public function rows(ReportSections $sections): array
    {
        return array_column($this->findings($sections), 'row');
    }

    /**
     * @return list<Reason>
     */
    public function reasons(ReportSections $sections): array
    {
        $unique = [];

        foreach ($this->findings($sections) as $finding) {
            if ($finding['reason'] !== null) {
                $unique[$finding['reason']['text']] = $finding['reason'];
            }
        }

        return array_values($unique);
    }

    /**
     * @return list<string>
     */
    public function coveredFlags(ReportSections $sections): array
    {
        $facts = $this->health->read($sections);

        if ($facts === null) {
            return [];
        }

        return array_values(array_filter([
            $this->health->switch($facts, 'Defender real-time protection') === null ? null : self::DEFENDER_FLAG,
            $this->health->switch($facts, 'SMBv1') === null ? null : self::SMB_FLAG,
        ]));
    }

    /**
     * @return list<Finding>
     */
    private function findings(ReportSections $sections): array
    {
        $facts = $this->health->read($sections);

        if ($facts === null) {
            return [];
        }

        $laptop = strtolower((string) $sections->header('device type')) === 'laptop';
        $others = $this->otherAntivirus($facts);

        return array_values(array_filter([
            $this->antivirus($facts, $others),
            $this->signatures($facts, $others),
            $this->scans($facts),
            $this->tamper($facts),
            $this->encryption($facts, $laptop),
            $this->firewall($facts, $others),
            $this->remoteDesktop($facts),
            $this->smb($facts),
            $this->administrators($facts),
            $this->builtInAdministrator($facts),
            $this->updatesPaused($facts),
            $this->accountControl($facts),
        ]));
    }

    /**
     * @param  Facts  $facts
     */
    private function otherAntivirus(array $facts): ?string
    {
        return $this->health->value($facts, 'Other antivirus');
    }

    private function named(?string $others): bool
    {
        return $others !== null && strtolower($others) !== 'none';
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function antivirus(array $facts, ?string $others): ?array
    {
        $realTime = $this->health->switch($facts, 'Defender real-time protection');
        $named = $this->named($others);

        return match (true) {
            $realTime === true => $this->finding('Antivirus', 'Microsoft Defender is on', 'good'),
            $realTime === false && $named => $this->finding('Antivirus', "{$others} (Microsoft Defender is off)", 'neutral'),
            $realTime === false && $others !== null => $this->finding('Antivirus', 'Off. Turn on virus protection in Windows Security.', 'bad', AttentionLevel::Urgent, 'No antivirus protection is switched on'),
            $realTime === false => $this->finding('Antivirus', 'Microsoft Defender is off', 'neutral'),
            $named => $this->finding('Antivirus', (string) $others, 'neutral'),
            default => null,
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function signatures(array $facts, ?string $others): ?array
    {
        $days = $this->health->count($facts, 'Defender signature age days', ReportWindowsHealth::MAX_DAYS);

        if ($days === null) {
            return null;
        }

        $label = 'Virus definitions';
        $age = match (true) {
            $days === 0 => 'Updated today',
            $days === 1 => '1 day old',
            default => "{$days} days old",
        };

        if ($days <= self::STALE_SIGNATURE_DAYS) {
            return $this->finding($label, $age, 'good');
        }

        return strtolower((string) $others) === 'none'
            ? $this->finding($label, "{$age}. Open Windows Security and check for updates.", 'bad', AttentionLevel::Soon, 'Virus definitions are more than '.self::STALE_SIGNATURE_DAYS.' days old')
            : $this->finding($label, $age, 'neutral');
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function scans(array $facts): ?array
    {
        $quick = $this->scanAge($facts, 'Defender last quick scan age days');
        $full = $this->scanAge($facts, 'Defender last full scan age days');
        $parts = array_values(array_filter([
            $quick === null ? null : "quick scan {$quick}",
            $full === null ? null : "full scan {$full}",
        ]));

        return $parts === [] ? null : $this->finding('Last virus scan', ucfirst(implode(', ', $parts)), 'neutral');
    }

    /**
     * @param  Facts  $facts
     */
    private function scanAge(array $facts, string $key): ?string
    {
        if (strtolower((string) $this->health->value($facts, $key)) === 'never') {
            return 'never';
        }

        $days = $this->health->count($facts, $key, ReportWindowsHealth::MAX_DAYS);

        return match (true) {
            $days === null => null,
            $days === 0 => 'today',
            $days === 1 => '1 day ago',
            default => "{$days} days ago",
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function tamper(array $facts): ?array
    {
        return match ($this->health->switch($facts, 'Defender tamper protection')) {
            true => $this->finding('Tamper protection', 'On', 'good'),
            false => $this->finding('Tamper protection', 'Off. Turn it on in Windows Security so malware cannot switch protection off.', 'neutral'),
            null => null,
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function encryption(array $facts, bool $laptop): ?array
    {
        return match ($this->health->switch($facts, 'BitLocker system drive')) {
            true => $this->finding('Disk encryption', 'On', 'good'),
            false => $laptop
                ? $this->finding('Disk encryption', 'Off. If this laptop is lost, anyone can read what is on it. Turn on device encryption or BitLocker.', 'bad', AttentionLevel::Soon, 'BitLocker is off on a laptop')
                : $this->finding('Disk encryption', 'Off', 'neutral'),
            null => null,
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function firewall(array $facts, ?string $others): ?array
    {
        $states = [];

        foreach (self::PROFILES as $profile) {
            $states[$profile] = $this->health->switch($facts, "Firewall {$profile}");
        }

        $off = array_keys(array_filter($states, fn (?bool $on): bool => $on === false));
        $known = count(array_filter($states, fn (?bool $on): bool => $on !== null));

        return match (true) {
            $off !== [] && $this->named($others) => $this->finding('Windows Firewall', 'Off for '.$this->join($off).'. Another security program is installed and may manage the firewall.', 'neutral'),
            $off !== [] => $this->finding('Windows Firewall', 'Off for '.$this->join($off).'. Turn it on in Windows Security.', 'bad', AttentionLevel::Soon, 'Windows Firewall is switched off'),
            $known === count(self::PROFILES) => $this->finding('Windows Firewall', 'On', 'good'),
            $known > 0 => $this->finding('Windows Firewall', 'Not fully checked', 'neutral'),
            default => null,
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function remoteDesktop(array $facts): ?array
    {
        $on = $this->health->switch($facts, 'Remote Desktop');
        $nla = strtolower((string) $this->health->value($facts, 'Remote Desktop network level authentication'));

        return match (true) {
            $on === null => null,
            $on === false => $this->finding('Remote Desktop', 'Off', 'good'),
            $nla === 'not required' => $this->finding('Remote Desktop', 'On, and it does not check who is signing in before connecting. Turn it off if you do not use it.', 'bad'),
            $nla === 'required' => $this->finding('Remote Desktop', 'On, and a sign-in is checked before connecting (network level authentication). Turn it off if you do not use it.', 'neutral'),
            default => $this->finding('Remote Desktop', 'On. Turn it off if you do not use it.', 'neutral'),
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function smb(array $facts): ?array
    {
        return match ($this->health->switch($facts, 'SMBv1')) {
            true => $this->finding('SMBv1', 'On. This outdated file sharing protocol should be turned off.', 'bad', AttentionLevel::Urgent, 'SMBv1, an outdated file sharing protocol, is switched on'),
            false => $this->finding('SMBv1', 'Off', 'good'),
            null => null,
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function administrators(array $facts): ?array
    {
        $count = $this->health->count($facts, 'Local administrator accounts');

        return $count === null ? null : $this->finding('Accounts with administrator rights', $count === 1 ? '1 account' : "{$count} accounts", 'neutral');
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function builtInAdministrator(array $facts): ?array
    {
        return match (strtolower((string) $this->health->value($facts, 'Built-in Administrator account'))) {
            'enabled' => $this->finding('Built-in Administrator account', 'On. Turn it off unless you need it, it is a well known target.', 'bad'),
            'disabled' => $this->finding('Built-in Administrator account', 'Off', 'good'),
            default => null,
        };
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function updatesPaused(array $facts): ?array
    {
        $value = (string) $this->health->value($facts, 'Windows Update paused');

        if (strtolower($value) === 'no') {
            return $this->finding('Windows Update paused', 'No', 'good');
        }

        if (preg_match('/^yes, until (\d{4})-(\d{2})-(\d{2})$/i', $value, $match) !== 1 || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        $until = CarbonImmutable::createFromDate((int) $match[1], (int) $match[2], (int) $match[3])->format('j F Y');

        return $this->finding('Windows Update paused', "Until {$until}. Resume updates in Settings so security fixes arrive.", 'neutral');
    }

    /**
     * @param  Facts  $facts
     * @return Finding|null
     */
    private function accountControl(array $facts): ?array
    {
        $on = $this->health->switch($facts, 'UAC');
        $silent = strtolower((string) $this->health->value($facts, 'UAC administrator prompt')) === 'never asks';
        $label = 'User Account Control';

        return match (true) {
            $on === false => $this->finding($label, 'Off. Turn it on so programs cannot change this PC without asking.', 'bad', AttentionLevel::Soon, 'User Account Control is switched off'),
            $on === true && $silent => $this->finding($label, 'On, but it never asks before changes. Set it to ask in the security settings.', 'bad', AttentionLevel::Soon, 'User Account Control never asks before changes'),
            $on === true => $this->finding($label, 'On', 'good'),
            default => null,
        };
    }

    /**
     * @param  'good'|'bad'|'neutral'  $tone
     * @return Finding
     */
    private function finding(string $label, string $value, string $tone, ?AttentionLevel $level = null, ?string $reason = null): array
    {
        return [
            'row' => ['label' => $label, 'value' => $value, 'tone' => $tone],
            'reason' => $level === null || $reason === null ? null : ['level' => $level->value, 'text' => $reason],
        ];
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
