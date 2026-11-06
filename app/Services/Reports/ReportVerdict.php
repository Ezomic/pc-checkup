<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Flags\FlagAdvisor;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type Row from ReportDetails
 *
 * @phpstan-type Signal array{strength: int, reason: string, advice: string, fixable: bool}
 */
final readonly class ReportVerdict
{
    private const NONE = 0;

    private const WEAK = 1;

    private const STRONG = 2;

    private const MIN_READABLE_INPUTS = 3;

    private const REPLACE_STRONG_SIGNALS = 3;

    private const REPAIR_FIXABLE_STRONG_SIGNALS = 1;

    private const REPAIR_FIXABLE_SIGNALS = 2;

    private const MAX_REASONS = 5;

    private const AGE_STRONG_YEARS = 9;

    private const AGE_WEAK_YEARS = 6;

    private const AGE_SANE_MAX_YEARS = 40;

    private const BATTERY_CAPACITY_STRONG_PERCENT = 60;

    private const BATTERY_CAPACITY_WEAK_PERCENT = 80;

    private const BATTERY_CYCLES_STRONG = 1000;

    private const BATTERY_CYCLES_WEAK = 700;

    private const BATTERY_CYCLES_SANE_MAX = 10000;

    private const BATTERY_WORN_CONDITIONS = ['service recommended', 'poor', 'replace soon', 'replace now'];

    private const RAM_STRONG_GB = 4;

    private const RAM_WEAK_GB = 8;

    private const DISK_FREE_STRONG_PERCENT = 5;

    private const DISK_FREE_WEAK_PERCENT = 10;

    private const VERDICT_KEEP = 'Keep';

    private const VERDICT_REPAIR = 'Repair';

    private const VERDICT_REPLACE = 'Replace';

    private const NOT_ENOUGH_DATA = 'Not enough data to judge';

    private const NOTE = 'A rule of thumb for you, not a guarantee. Look at the machine itself before you spend money.';

    /**
     * @param  list<string>  $flags
     * @return list<Row>
     */
    public function rows(ReportSections $sections, array $flags, ?string $freeDisk): array
    {
        $signals = array_filter([
            'os' => $this->operatingSystem($sections, $flags),
            'age' => $this->age($sections),
            'battery' => $this->battery($sections),
            'ram' => $this->memory($sections),
            'drive' => $this->drive($sections),
            'disk' => $this->freeDisk($freeDisk),
        ], fn (?array $signal): bool => $signal !== null);

        if ($signals === []) {
            return [];
        }

        $note = ['label' => 'Good to know', 'value' => self::NOTE, 'tone' => 'neutral'];

        if (count($signals) < self::MIN_READABLE_INPUTS) {
            return [['label' => 'Verdict', 'value' => self::NOT_ENOUGH_DATA, 'tone' => 'neutral'], $note];
        }

        [$value, $tone] = $this->decide($signals);

        return [
            ['label' => 'Verdict', 'value' => $value, 'tone' => $tone],
            ...$this->reasonRows($signals),
            $note,
        ];
    }

    /**
     * @param  list<Row>  $rows
     */
    public static function outcome(array $rows): ?string
    {
        $verdict = array_column($rows, 'value', 'label')['Verdict'] ?? null;

        return in_array($verdict, [self::VERDICT_KEEP, self::VERDICT_REPAIR, self::VERDICT_REPLACE], true) ? $verdict : null;
    }

    /**
     * @param  array<string, Signal>  $signals
     * @return array{string, 'good'|'bad'|'neutral'}
     */
    private function decide(array $signals): array
    {
        $strong = $this->count($signals, self::STRONG);
        $fixable = array_filter($signals, fn (array $signal): bool => $signal['fixable'] && $signal['strength'] > self::NONE);
        $fixableStrong = $this->count($fixable, self::STRONG);
        $osStrong = ($signals['os']['strength'] ?? self::NONE) === self::STRONG;
        $osStuck = $osStrong && ! $signals['os']['fixable'];
        $tooOld = ($signals['age']['strength'] ?? self::NONE) === self::STRONG || $osStuck;

        return match (true) {
            $tooOld && ($osStrong || $strong >= self::REPLACE_STRONG_SIGNALS) => [self::VERDICT_REPLACE, 'bad'],
            $fixableStrong >= self::REPAIR_FIXABLE_STRONG_SIGNALS || count($fixable) >= self::REPAIR_FIXABLE_SIGNALS => [self::VERDICT_REPAIR, 'neutral'],
            default => [self::VERDICT_KEEP, 'good'],
        };
    }

    /**
     * @param  array<string, Signal>  $signals
     */
    private function count(array $signals, int $strength): int
    {
        return count(array_filter($signals, fn (array $signal): bool => $signal['strength'] === $strength));
    }

    /**
     * @param  array<string, Signal>  $signals
     * @return list<Row>
     */
    private function reasonRows(array $signals): array
    {
        $reasons = array_values(array_filter($signals, fn (array $signal): bool => $signal['strength'] > self::NONE));
        usort($reasons, fn (array $a, array $b): int => $b['strength'] <=> $a['strength']);

        return array_map(
            fn (array $signal): array => ['label' => $signal['reason'], 'value' => $signal['advice'], 'tone' => $signal['strength'] === self::STRONG ? 'bad' : 'neutral'],
            array_slice($reasons, 0, self::MAX_REASONS),
        );
    }

    /**
     * @param  list<string>  $flags
     * @return Signal|null
     */
    private function operatingSystem(ReportSections $sections, array $flags): ?array
    {
        $known = $this->known($sections->header('macos')) ?? $this->known($sections->header('windows'))
            ?? $this->capture('/^ProductVersion:\s*(\S+)/m', $sections->text('macOS version'));

        $unsupported = array_filter($flags, fn (string $flag): bool => preg_match(FlagAdvisor::UNSUPPORTED_OS, $flag) === 1) !== [];

        $newest = MacNewestOs::forModel($sections->header('model'));
        $stuck = $newest !== null && ! MacNewestOs::isSupportedByApple($newest);

        return match (true) {
            $unsupported && $stuck => $this->signal(self::STRONG, 'The operating system no longer gets security updates', 'No macOS that still gets security updates runs on this Mac, so it is time to replace it.', false),
            $unsupported => $this->signal(self::STRONG, 'The operating system no longer gets security updates', 'Update it if the machine allows, otherwise it is time to replace it.', true),
            $known !== null => $this->none(),
            default => null,
        };
    }

    /**
     * @return Signal|null
     */
    private function age(ReportSections $sections): ?array
    {
        $model = $sections->header('model');
        $identifier = $this->capture('/\(([A-Za-z]+\d+,\d+)\)$/', $model);
        $year = $identifier === null ? null : MacModelYears::year($identifier);

        if ($year === null) {
            return null;
        }

        $years = CarbonImmutable::now()->year - $year;

        if ($years < 0 || $years > self::AGE_SANE_MAX_YEARS) {
            return null;
        }

        $reason = "About {$years} years old";

        return match (true) {
            $years >= self::AGE_STRONG_YEARS => $this->signal(self::STRONG, $reason, 'Machines this old slow down and fail more often. Start planning a replacement.', false),
            $years >= self::AGE_WEAK_YEARS => $this->signal(self::WEAK, $reason, 'Fine for now, but it is getting on. Keep a replacement in mind.', false),
            default => $this->none(),
        };
    }

    /**
     * @return Signal|null
     */
    private function battery(ReportSections $sections): ?array
    {
        if (preg_match('/^(\S+) cycles,\s*(.+)$/i', (string) $sections->header('battery'), $line) !== 1) {
            return null;
        }

        $mac = preg_match('/^condition (.+?), max capacity (.+)$/i', $line[2], $parts) === 1;
        $condition = $mac ? strtolower(trim($parts[1])) : null;
        $capacity = $this->percent('/^(\d{1,3})%/', $mac ? $parts[2] : $line[2]);
        $cycles = ctype_digit($line[1]) && (int) $line[1] <= self::BATTERY_CYCLES_SANE_MAX ? (int) $line[1] : null;

        if ($capacity === null && $cycles === null && ! in_array($condition, [...self::BATTERY_WORN_CONDITIONS, 'normal'], true)) {
            return null;
        }

        $advice = 'A new battery usually costs far less than a new machine.';

        return match (true) {
            in_array($condition, self::BATTERY_WORN_CONDITIONS, true) => $this->signal(self::STRONG, 'The battery is worn out', $advice, true),
            $capacity !== null && $capacity < self::BATTERY_CAPACITY_STRONG_PERCENT => $this->signal(self::STRONG, "The battery holds only {$capacity}% of what it did when new", $advice, true),
            $cycles !== null && $cycles >= self::BATTERY_CYCLES_STRONG => $this->signal(self::STRONG, "The battery has been charged {$cycles} times", $advice, true),
            $capacity !== null && $capacity < self::BATTERY_CAPACITY_WEAK_PERCENT => $this->signal(self::WEAK, "The battery holds {$capacity}% of what it did when new", $advice, true),
            $cycles !== null && $cycles >= self::BATTERY_CYCLES_WEAK => $this->signal(self::WEAK, "The battery has been charged {$cycles} times", $advice, true),
            default => $this->none(),
        };
    }

    /**
     * @return Signal|null
     */
    private function memory(ReportSections $sections): ?array
    {
        $gigabytes = (new ReportSlowness)->ramGb($sections->header('memory'));

        if ($gigabytes === null) {
            return null;
        }

        $size = round($gigabytes, 1);

        return match (true) {
            $gigabytes < self::RAM_STRONG_GB => $this->signal(self::STRONG, "Only {$size} GB of memory", 'Adding memory, if this model allows it, is a cheap fix.', true),
            $gigabytes < self::RAM_WEAK_GB => $this->signal(self::WEAK, "{$size} GB of memory is on the small side", 'More memory would help, if this model allows it.', true),
            default => $this->none(),
        };
    }

    /**
     * @return Signal|null
     */
    private function drive(ReportSections $sections): ?array
    {
        $solid = strtolower((string) $this->capture('/^\s*Solid State:\s*(\S+)/mi', $sections->text('Storage (boot volume)')));
        $kinds = $this->windowsDriveKinds($sections->text('Storage (physical disks)'));

        $spinning = $solid === 'no' || ($kinds !== [] && ! in_array('SSD', $kinds, true) && in_array('HDD', $kinds, true));
        $fast = $solid === 'yes' || in_array('SSD', $kinds, true);

        return match (true) {
            $spinning => $this->signal(self::STRONG, 'It still has a spinning hard drive', 'Swapping in a solid state drive makes an older machine feel much faster.', true),
            $fast => $this->none(),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function windowsDriveKinds(?string $text): array
    {
        $kinds = [];

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/\s(SSD|HDD)\s+(\S+)\s+(\d+)\s+(\S+)\s+\S.*$/', $line, $match) === 1) {
                $kinds[] = $match[1];
            }
        }

        return $kinds;
    }

    /**
     * @return Signal|null
     */
    private function freeDisk(?string $freeDisk): ?array
    {
        $free = $this->percent('/^(\d{1,3})%$/', $freeDisk);

        if ($free === null) {
            return null;
        }

        $advice = 'Clear out big files or move them to another drive.';

        return match (true) {
            $free < self::DISK_FREE_STRONG_PERCENT => $this->signal(self::STRONG, "Only {$free}% of the drive is free", $advice, true),
            $free < self::DISK_FREE_WEAK_PERCENT => $this->signal(self::WEAK, "Only {$free}% of the drive is free", $advice, true),
            default => $this->none(),
        };
    }

    /**
     * @return Signal
     */
    private function signal(int $strength, string $reason, string $advice, bool $fixable): array
    {
        return ['strength' => $strength, 'reason' => $reason, 'advice' => $advice, 'fixable' => $fixable];
    }

    /**
     * @return Signal
     */
    private function none(): array
    {
        return $this->signal(self::NONE, '', '', false);
    }

    private function percent(string $pattern, ?string $text): ?int
    {
        $value = $this->capture($pattern, $text);

        return $value === null || (int) $value > 100 ? null : (int) $value;
    }

    private function known(?string $value): ?string
    {
        return $value === null || preg_match('/\A(?:n\/a|unknown|none|-)\z/i', trim($value)) === 1 ? null : $value;
    }

    private function capture(string $pattern, ?string $text): ?string
    {
        return $text !== null && preg_match($pattern, $text, $match) === 1 ? $match[1] : null;
    }
}
