<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class DnsServerKind
{
    public const UNKNOWN = 'not recognised';

    private const THIS_COMPUTER = 'this computer';

    private const LOCAL = 'home network or router';

    private const PUBLIC = [
        'Google' => ['8.8.8.8', '8.8.4.4', '2001:4860:4860::8888', '2001:4860:4860::8844'],
        'Cloudflare' => ['1.1.1.1', '1.0.0.1', '1.1.1.2', '1.0.0.2', '1.1.1.3', '1.0.0.3', '2606:4700:4700::1111', '2606:4700:4700::1001', '2606:4700:4700::1112', '2606:4700:4700::1002', '2606:4700:4700::1113', '2606:4700:4700::1003'],
        'Quad9' => ['9.9.9.9', '149.112.112.112', '9.9.9.10', '149.112.112.10', '9.9.9.11', '149.112.112.11', '2620:fe::fe', '2620:fe::9', '2620:fe::10', '2620:fe::fe:10', '2620:fe::11', '2620:fe::fe:11'],
        'OpenDNS' => ['208.67.222.222', '208.67.220.220', '208.67.222.123', '208.67.220.123', '2620:119:35::35', '2620:119:53::53'],
    ];

    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    // RFC 1918, link-local, carrier-grade NAT (also Tailscale), IPv6 unique local and link-local.
    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '100.64.0.0/10', 'fc00::/7', 'fe80::/10'];

    // The retired IPv6 site-local range. Windows lists fec0:0:0:ffff::1 to ::3 on every adapter without
    // IPv6 DNS, so they say nothing about the DNS server in use.
    private const PLACEHOLDER = ['fec0::/10'];

    public static function address(string $value): ?string
    {
        $value = (string) preg_replace('/%[A-Za-z0-9._-]{1,32}$/', '', trim($value));
        $packed = filter_var($value, FILTER_VALIDATE_IP) === false ? false : inet_pton($value);

        return $packed === false ? null : (string) inet_ntop($packed);
    }

    public static function isPlaceholder(string $address): bool
    {
        return self::within($address, self::PLACEHOLDER);
    }

    public static function describe(string $address): string
    {
        foreach (self::PUBLIC as $name => $addresses) {
            if (in_array($address, $addresses, true)) {
                return $name;
            }
        }

        return match (true) {
            self::within($address, self::LOOPBACK) => self::THIS_COMPUTER,
            self::within($address, self::PRIVATE) => self::LOCAL,
            default => self::UNKNOWN,
        };
    }

    /**
     * @param  list<string>  $ranges
     */
    private static function within(string $address, array $ranges): bool
    {
        $packed = (string) inet_pton($address);

        foreach ($ranges as $range) {
            [$base, $bits] = explode('/', $range);
            $network = (string) inet_pton($base);

            if (strlen($network) === strlen($packed) && self::prefix($packed, (int) $bits) === self::prefix($network, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function prefix(string $packed, int $bits): string
    {
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;
        $prefix = substr($packed, 0, $whole);

        return $rest === 0 ? $prefix : $prefix.chr(ord($packed[$whole]) & (0xFF << (8 - $rest)) & 0xFF);
    }
}
