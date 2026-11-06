<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttentionLevel;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 * @phpstan-import-type Reason from \App\Services\NeedsAttention
 *
 * @phpstan-type Hosts array{partial: bool, entries: int, redirected: list<string>, blocked: list<string>}
 * @phpstan-type Proxy array{on: bool, host: string|null, script: string|null}
 */
final readonly class ReportNetworkSettings
{
    /** Sites whose redirection in the hosts file is a sign of hijacking. The scan scripts hold the same list. */
    public const WATCHED_SITES = [
        'google.com', 'google.nl', 'gmail.com', 'youtube.com', 'microsoft.com', 'live.com', 'outlook.com', 'office.com',
        'microsoftonline.com', 'windowsupdate.com', 'apple.com', 'icloud.com', 'facebook.com', 'paypal.com',
        'ing.nl', 'rabobank.nl', 'abnamro.nl', 'snsbank.nl', 'asnbank.nl', 'regiobank.nl', 'triodos.nl', 'knab.nl',
        'bunq.com', 'digid.nl', 'belastingdienst.nl',
    ];

    public const MAX_DNS_ROWS = 4;

    private const MAX_ENTRIES = 100000;

    private const HOST_PATTERN = '/^(?:[A-Za-z0-9](?:[A-Za-z0-9._-]{0,98}[A-Za-z0-9])?|\[[0-9A-Fa-f:.]{2,45}\])(?::\d{1,5})?$/';

    private const SECTION = 'Network settings';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $lines = $this->lines($sections);
        $hosts = $this->hosts($lines);
        $proxy = $this->proxy($lines);
        $dns = $this->dns($lines);
        $rows = array_values(array_filter([
            $hosts === null ? null : $this->hostsRow($hosts),
            $proxy === null ? null : $this->proxyRow($proxy),
            $dns === [] ? null : [
                'label' => 'DNS servers',
                'value' => implode(', ', array_map(fn (string $address): string => $address.' ('.DnsServerKind::describe($address).')', $dns)),
                'tone' => 'neutral',
            ],
        ]));

        if ($rows === []) {
            return null;
        }

        $unknown = array_filter($dns, fn (string $address): bool => DnsServerKind::describe($address) === DnsServerKind::UNKNOWN) !== [];

        return [
            'id' => 'network-settings',
            'title' => 'Network settings',
            'rows' => $rows,
            'notes' => array_values(array_filter([
                ($hosts['partial'] ?? false) ? 'The hosts file was too long to read whole, so only its first part was checked.' : null,
                ($hosts['redirected'] ?? []) !== [] ? 'The hosts file overrules where a site name leads. Sending a bank or a big site to another address is how some malware steals sign-ins.' : null,
                ($proxy['on'] ?? false) ? 'A proxy sends web traffic through another computer. That is normal when a company or school set it up; otherwise check who did.' : null,
                $unknown ? 'A DNS server that is not the router or a well-known public service is usually the internet provider\'s, which is fine. Only if nobody recognises it is it worth a closer look.' : null,
                'From the hosts file only the number of extra entries and a fixed list of well-known sites are recorded, never its lines.',
                'Only you see this: it is not on the pages you share with clients.',
            ])),
            'list' => null,
        ];
    }

    /**
     * @return list<Reason>
     */
    public function reasons(ReportSections $sections): array
    {
        $lines = $this->lines($sections);
        $redirected = $this->hosts($lines)['redirected'] ?? [];

        return array_values(array_filter([
            $redirected === [] ? null : ['level' => AttentionLevel::Urgent->value, 'text' => 'The hosts file sends '.$this->join($redirected).' to another address'],
            ($this->proxy($lines)['on'] ?? false) ? ['level' => AttentionLevel::Soon->value, 'text' => 'A proxy server is set: check that it is wanted'] : null,
        ]));
    }

    /**
     * @return list<string>
     */
    private function lines(ReportSections $sections): array
    {
        $text = $sections->text(self::SECTION);

        return $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
    }

    /**
     * @param  list<string>  $lines
     * @return Hosts|null
     */
    private function hosts(array $lines): ?array
    {
        $entries = LocaleNumber::count(ReportDates::keyValue($lines, 'hosts-entries'));
        $status = strtolower((string) ReportDates::keyValue($lines, 'hosts'));
        $partial = str_starts_with($status, 'partial');

        if (($status !== 'complete' && ! $partial) || $entries === null || $entries > self::MAX_ENTRIES) {
            return null;
        }

        return [
            'partial' => $partial,
            'entries' => $entries,
            'redirected' => $this->sites(ReportDates::keyValue($lines, 'hosts-redirected')),
            'blocked' => $this->sites(ReportDates::keyValue($lines, 'hosts-blocked')),
        ];
    }

    /**
     * @return list<string>
     */
    private function sites(?string $value): array
    {
        $named = array_map(fn (string $site): string => strtolower(trim($site)), explode(',', (string) $value));

        return array_values(array_intersect(self::WATCHED_SITES, $named));
    }

    /**
     * @param  Hosts  $hosts
     * @return Row
     */
    private function hostsRow(array $hosts): array
    {
        $count = ($hosts['partial'] ? 'At least ' : '').($hosts['entries'] === 1 ? '1 extra entry' : number_format($hosts['entries']).' extra entries');

        return match (true) {
            $hosts['redirected'] !== [] => ['label' => 'Hosts file', 'value' => "{$count}. Sends ".$this->join($hosts['redirected']).' to another address: check the hosts file.', 'tone' => 'bad'],
            $hosts['entries'] === 0 && $hosts['partial'] => ['label' => 'Hosts file', 'value' => 'No extra entries in the part that was read', 'tone' => 'neutral'],
            $hosts['entries'] === 0 => ['label' => 'Hosts file', 'value' => 'No extra entries', 'tone' => 'good'],
            $hosts['blocked'] !== [] => ['label' => 'Hosts file', 'value' => "{$count}, blocking ".$this->join($hosts['blocked']), 'tone' => 'neutral'],
            default => ['label' => 'Hosts file', 'value' => $count, 'tone' => 'neutral'],
        };
    }

    /**
     * @param  list<string>  $lines
     * @return Proxy|null
     */
    private function proxy(array $lines): ?array
    {
        return match (strtolower((string) ReportDates::keyValue($lines, 'proxy'))) {
            'off' => ['on' => false, 'host' => null, 'script' => null],
            'on' => ['on' => true, 'host' => $this->host(ReportDates::keyValue($lines, 'proxy-host')), 'script' => $this->host(ReportDates::keyValue($lines, 'proxy-script-host'))],
            default => null,
        };
    }

    private function host(?string $value): ?string
    {
        return $value !== null && preg_match(self::HOST_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * @param  Proxy  $proxy
     * @return Row
     */
    private function proxyRow(array $proxy): array
    {
        $value = match (true) {
            ! $proxy['on'] => 'Off',
            $proxy['host'] !== null && $proxy['script'] !== null => "On, through {$proxy['host']} (set up by a script from {$proxy['script']})",
            $proxy['host'] !== null => "On, through {$proxy['host']}",
            $proxy['script'] !== null => "On, set up by a script from {$proxy['script']}",
            default => 'On',
        };

        return ['label' => 'Proxy', 'value' => $value, 'tone' => $proxy['on'] ? 'bad' : 'good'];
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function dns(array $lines): array
    {
        if (strtolower((string) ReportDates::keyValue($lines, 'dns')) !== 'complete') {
            return [];
        }

        $addresses = [];

        foreach ($lines as $line) {
            $address = preg_match('/^dns-server:\s*(\S+)$/i', trim($line), $match) === 1 ? DnsServerKind::address($match[1]) : null;

            if ($address !== null && ! DnsServerKind::isPlaceholder($address)) {
                $addresses[$address] = $address;
            }
        }

        return array_slice(array_values($addresses), 0, self::MAX_DNS_ROWS);
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
