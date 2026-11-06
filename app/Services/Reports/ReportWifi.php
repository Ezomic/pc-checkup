<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * @phpstan-import-type Card from ReportDetails
 */
final readonly class ReportWifi
{
    public const STRONG_AT = 70;

    public const WEAK_BELOW = 40;

    private const MIN_DBM = -120;

    private const MAX_DBM = -1;

    private const BAND_PATTERN = '/^(2[.,]4|5|6)\s*GHz$/i';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $text = $sections->text('Wi-Fi signal');
        $lines = $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
        $percent = $this->percent(ReportDates::keyValue($lines, 'signal-percent'));
        $dbm = $this->dbm(ReportDates::keyValue($lines, 'signal-dbm'));

        if ($percent === null && $dbm === null) {
            return null;
        }

        $quality = $percent ?? max(0, min(100, 2 * ((int) $dbm + 100)));
        $band = $this->band(ReportDates::keyValue($lines, 'band'));
        [$word, $tone] = match (true) {
            $quality >= self::STRONG_AT => ['Strong', 'good'],
            $quality >= self::WEAK_BELOW => ['Fair', 'neutral'],
            default => ['Weak', 'bad'],
        };

        return [
            'id' => 'wifi',
            'title' => 'Wi-Fi connection',
            'rows' => [
                ['label' => 'Signal', 'value' => $word.' ('.($percent !== null ? "{$percent}%" : "{$dbm} dBm").')', 'tone' => $tone],
                ...($band === null ? [] : [['label' => 'Band', 'value' => $band, 'tone' => 'neutral']]),
            ],
            'notes' => array_values(array_filter([
                'Measured once, where the computer stood during the scan. To compare, scan again in the room where it is used.',
                $tone === 'bad' ? 'A weak signal slows downloads and calls. Move closer to the router or add an access point.' : null,
                $band === '2.4 GHz' ? '2.4 GHz reaches further but is slower and more crowded than 5 GHz.' : null,
                'Only the Wi-Fi connection in use is read: no network names, passwords or saved networks.',
                'Only you see this: it is not on the pages you share with clients.',
            ])),
            'list' => null,
        ];
    }

    private function percent(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d{1,3}$/', $value) === 1 && (int) $value <= 100 ? (int) $value : null;
    }

    private function dbm(?string $value): ?int
    {
        if ($value === null || preg_match('/^-\d{1,3}$/', $value) !== 1) {
            return null;
        }

        return (int) $value >= self::MIN_DBM && (int) $value <= self::MAX_DBM ? (int) $value : null;
    }

    private function band(?string $value): ?string
    {
        if ($value === null || preg_match(self::BAND_PATTERN, $value, $match) !== 1) {
            return null;
        }

        return str_replace(',', '.', $match[1]).' GHz';
    }
}
