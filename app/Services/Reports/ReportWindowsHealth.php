<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * @phpstan-type Drive array{name: string, bus: string|null, usb: bool, health: string|null, wear: float|null, temperature: int|null, readErrors: int|null, media: string|null, bytes: float|null}
 * @phpstan-type Facts array{drives: list<Drive>, values: array<string, string>}
 */
final readonly class ReportWindowsHealth
{
    public const SECTION = 'Windows health and security';

    public const MAX_WEAR = 100.0;

    public const MAX_TEMPERATURE = 150;

    public const MAX_COUNT = 1_000_000_000;

    public const MAX_DAYS = 36500;

    private const NOT_READ = 'could not check';

    private const NAME_LIMIT = 50;

    private const VALUE_LIMIT = 80;

    private const MAX_DRIVES = 8;

    private const DRIVE_FIELDS = ['bus', 'health', 'wear', 'temperature', 'read errors', 'type', 'size'];

    private const MEDIA = ['ssd' => 'SSD', 'hdd' => 'HDD'];

    /**
     * @return Facts|null
     */
    public function read(ReportSections $sections): ?array
    {
        $text = $sections->text(self::SECTION);

        if ($text === null || $text === '') {
            return null;
        }

        $drives = [];
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*Drive (\d+):\s*(.+?)\s*$/i', $line, $match) === 1) {
                if (count($drives) < self::MAX_DRIVES) {
                    $drives[] = $this->drive((int) $match[1], $match[2]);
                }

                continue;
            }

            if (preg_match('/^\s*([^:|]{2,70}):\s*(\S.*?)\s*$/u', $line, $match) === 1) {
                $values[mb_strtolower($match[1])] ??= $match[2];
            }
        }

        return ['drives' => $drives, 'values' => $values];
    }

    /**
     * @param  Facts  $facts
     */
    public function value(array $facts, string $key): ?string
    {
        $value = trim($facts['values'][mb_strtolower($key)] ?? '');

        return $value === '' || strtolower($value) === self::NOT_READ ? null : Str::limit($this->plain($value), self::VALUE_LIMIT, '');
    }

    /**
     * @param  Facts  $facts
     */
    public function count(array $facts, string $key, int $max = self::MAX_COUNT): ?int
    {
        $count = LocaleNumber::count($this->value($facts, $key));

        return $count === null || $count > $max ? null : $count;
    }

    /**
     * @param  Facts  $facts
     */
    public function switch(array $facts, string $key): ?bool
    {
        return match (strtolower((string) $this->value($facts, $key))) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    /**
     * @return Drive
     */
    private function drive(int $number, string $line): array
    {
        $parts = array_map('trim', preg_split('/\s*\|\s*/', $line) ?: []);
        $name = $this->plain((string) array_shift($parts));
        $fields = [];

        foreach ($parts as $part) {
            foreach (self::DRIVE_FIELDS as $field) {
                if (preg_match('/^'.preg_quote($field, '/').' (.+)$/i', $part, $match) === 1) {
                    $fields[$field] ??= $match[1];
                }
            }
        }

        $bus = $this->known($fields['bus'] ?? null);
        $health = $this->known($fields['health'] ?? null);

        return [
            'name' => $name === '' ? "Drive {$number}" : Str::limit($name, self::NAME_LIMIT, ''),
            'bus' => $bus,
            'usb' => $bus !== null && strtolower($bus) === 'usb',
            'health' => $health === null || strtolower($health) === 'unknown' ? null : ucfirst(strtolower($health)),
            'wear' => $this->bounded($fields['wear'] ?? null, self::MAX_WEAR),
            'temperature' => ($temperature = $this->bounded($fields['temperature'] ?? null, (float) self::MAX_TEMPERATURE)) === null || $temperature <= 0 ? null : (int) round($temperature),
            'readErrors' => $this->errors($fields['read errors'] ?? null),
            'media' => self::MEDIA[strtolower((string) $this->known($fields['type'] ?? null))] ?? null,
            'bytes' => ByteSize::parse((string) $this->known($fields['size'] ?? null)),
        ];
    }

    private function errors(?string $value): ?int
    {
        $count = LocaleNumber::count($this->known($value));

        return $count === null || $count > self::MAX_COUNT ? null : $count;
    }

    private function bounded(?string $value, float $max): ?float
    {
        $number = LocaleNumber::float($this->known($value));

        return $number === null || $number < 0 || $number > $max ? null : $number;
    }

    private function known(?string $value): ?string
    {
        $value = $this->plain((string) $value);

        return $value === '' || strtolower($value) === self::NOT_READ ? null : Str::limit($value, self::VALUE_LIMIT, '');
    }

    private function plain(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $value));
    }
}
