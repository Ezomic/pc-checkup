<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * The other volumes in the startup disk's APFS container (swap, recovery, startup and update files)
 * count as used space, but the storage breakdown only walks the startup volume and, on macOS 10.15
 * and later, the data volume. A volume shares the container when it has the same disk number and
 * shows the same size, since df prints the container's size for every volume in it.
 *
 * @phpstan-type Volume array{container: string, volume: string, size: float, used: float, mount: string}
 */
final readonly class ReportMacVolumes
{
    private const LINE = '#\A/dev/(disk\d+)(s\d+)(?:s\d+)*\s+(\S+)\s+(\S+)\s+\S+\s+\d+%\s+(?:[^/\s]\S*\s+){0,3}(/.*?)\s*\z#';

    private const WALKED = ['/', '/System/Volumes/Data'];

    private const MACOS_MOUNT = '#\A(?:/System/Volumes/(?!Data(?:/|\z))[^/]+(?:/.*)?|/private/var/vm|/Volumes/(?:Recovery|Preboot|VM|Update))\z#';

    public function bytes(?string $diskUsage): float
    {
        $volumes = $this->volumes((string) $diskUsage);
        $startup = array_values(array_filter($volumes, fn (array $volume): bool => $volume['mount'] === '/'))[0] ?? null;

        if ($startup === null) {
            return 0.0;
        }

        $walked = array_column(array_filter($volumes, fn (array $volume): bool => in_array($volume['mount'], self::WALKED, true)), 'volume');
        $counted = [];

        foreach ($volumes as $volume) {
            $other = $volume['container'] === $startup['container'] && $volume['size'] === $startup['size'] && ! in_array($volume['volume'], $walked, true);

            if ($other && preg_match(self::MACOS_MOUNT, $volume['mount']) === 1) {
                $counted[$volume['volume']] ??= $volume['used'];
            }
        }

        return (float) array_sum($counted);
    }

    /**
     * A snapshot (disk3s3s1, the startup volume on macOS 11 and later) belongs to its volume (disk3s3).
     *
     * @return list<Volume>
     */
    private function volumes(string $text): array
    {
        $volumes = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match(self::LINE, trim($line), $match) !== 1) {
                continue;
            }

            $size = ByteSize::human($match[3]);
            $used = ByteSize::human($match[4]);

            if ($size !== null && $used !== null) {
                $volumes[] = ['container' => $match[1], 'volume' => $match[1].$match[2], 'size' => $size, 'used' => $used, 'mount' => $match[5]];
            }
        }

        return $volumes;
    }
}
