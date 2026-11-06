<?php

declare(strict_types=1);

namespace App\Services\Warranty;

use App\Services\Reports\ReportSections;

/**
 * @phpstan-type Link array{maker: string, url: string, serial: string, serial_in_url: bool}
 */
final readonly class WarrantyLink
{
    /**
     * Each address was opened in a real browser. Only Dell takes the tag in the path (its
     * servicetag route answered the tag, flagged a made-up one as invalid); the others are the
     * maker's own lookup page and the owner pastes the serial.
     */
    public const MAKERS = [
        'apple' => ['label' => 'Apple', 'pattern' => null, 'url' => 'https://checkcoverage.apple.com/', 'serial_in_url' => false],
        'dell' => ['label' => 'Dell', 'pattern' => '/\bdell\b/i', 'url' => 'https://www.dell.com/support/home/en-us/product-support/servicetag/{serial}/overview', 'serial_in_url' => true],
        'hp' => ['label' => 'HP', 'pattern' => '/\b(?:hp|hewlett[- ]packard)\b/i', 'url' => 'https://support.hp.com/us-en/check-warranty', 'serial_in_url' => false],
        'lenovo' => ['label' => 'Lenovo', 'pattern' => '/\blenovo\b/i', 'url' => 'https://pcsupport.lenovo.com/us/en/warranty-lookup', 'serial_in_url' => false],
        'asus' => ['label' => 'ASUS', 'pattern' => '/\basus/i', 'url' => 'https://www.asus.com/support/warranty-status-inquiry/', 'serial_in_url' => false],
        'acer' => ['label' => 'Acer', 'pattern' => '/\bacer\b/i', 'url' => 'https://www.acer.com/us-en/support/warranty/warranty-review', 'serial_in_url' => false],
        'surface' => ['label' => 'Microsoft Surface', 'pattern' => '/\bsurface\b/i', 'url' => 'https://support.microsoft.com/en-us/warranty', 'serial_in_url' => false],
        'msi' => ['label' => 'MSI', 'pattern' => '/\b(?:msi|micro-star)\b/i', 'url' => 'https://account.msi.com/services/warranty-book', 'serial_in_url' => false],
    ];

    public function __construct(private ReportSerial $serials) {}

    /**
     * @return Link|null
     */
    public function for(string $markdown): ?array
    {
        $serial = $this->serials->from($markdown);
        $sections = ReportSections::parse($markdown);
        $maker = $serial === null ? null : $this->maker($sections);

        if ($serial === null || $maker === null) {
            return null;
        }

        $entry = self::MAKERS[$maker];

        return [
            'maker' => $entry['label'],
            'url' => str_replace('{serial}', rawurlencode($serial), $entry['url']),
            'serial' => $serial,
            'serial_in_url' => $entry['serial_in_url'],
        ];
    }

    private function maker(ReportSections $sections): ?string
    {
        if (strtolower((string) $sections->header('os')) === 'macos') {
            return 'apple';
        }

        $model = $sections->header('model');

        foreach (self::MAKERS as $key => $entry) {
            if ($entry['pattern'] !== null && $model !== null && preg_match($entry['pattern'], $model) === 1) {
                return $key;
            }
        }

        return null;
    }
}
