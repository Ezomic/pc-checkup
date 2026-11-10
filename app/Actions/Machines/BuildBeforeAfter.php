<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Report;
use App\Services\Flags\FlagAdvice;
use App\Services\Reports\ReportNumbers;

/**
 * @phpstan-type Row array{key: string, from: string, to: string, improved: bool|null}
 * @phpstan-type Document array{before: string|null, after: string|null, same_rights: bool, fixed: list<string>, open: list<string>, new: list<string>, numbers: list<Row>}
 */
final readonly class BuildBeforeAfter
{
    private const array CONDITION_RANK = ['normal' => 0, 'service_recommended' => 1, 'replace_soon' => 1, 'poor' => 2, 'replace_now' => 2];

    private const array HIGHER_IS_BETTER = ['free_disk', 'memory', 'disk_read', 'disk_write'];

    public function __construct(private CompareLatestReports $compare, private ReportNumbers $numbers) {}

    /**
     * A client only ever gets the plain wording: a flag no rule matched carries the scanner's raw text, which stays with the owner.
     * A flag the earlier scan could not raise is not new, but it is still to do, so the document never calls the machine clear while it has one.
     *
     * @return Document
     */
    public function handle(Report $before, Report $after, bool $forClient): array
    {
        $diff = $this->compare->diff($before, $after);
        $headline = fn (FlagAdvice $advice): string => $forClient && $advice->advice === null ? trans('flags.unknown.headline') : $advice->headline;

        return [
            'before' => $before->created_at?->toJSON(),
            'after' => $after->created_at?->toJSON(),
            'same_rights' => $this->compare->sameRights($before, $after),
            'fixed' => array_map($headline, $diff['fixed']),
            'open' => array_map($headline, [...$diff['open'], ...$diff['newly_checked']]),
            'new' => array_map($headline, $diff['new']),
            'numbers' => $this->rows($this->numbers->from($before), $this->numbers->from($after)),
        ];
    }

    /**
     * @param  array<string, int|float|string>  $before
     * @param  array<string, int|float|string>  $after
     * @return list<Row>
     */
    private function rows(array $before, array $after): array
    {
        $rows = [];

        foreach (array_intersect_key($before, $after) as $key => $from) {
            $to = $after[$key];
            [$fromText, $toText] = [$this->text($key, $from), $this->text($key, $to)];

            if ($fromText !== $toText) {
                $rows[] = ['key' => $key, 'from' => $fromText, 'to' => $toText, 'improved' => $this->improved($key, $from, $to)];
            }
        }

        return $rows;
    }

    private function text(string $key, int|float|string $value): string
    {
        return match ($key) {
            'free_disk' => "{$value}%",
            'memory' => $this->decimal((float) $value).' GB',
            'disk_read', 'disk_write' => round((float) $value).' MB/s',
            default => (string) $value,
        };
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function improved(string $key, int|float|string $from, int|float|string $to): ?bool
    {
        if ($key === 'battery_cycles') {
            return null;
        }

        if ($key === 'battery_condition') {
            $now = self::CONDITION_RANK[(string) $to] ?? 0;
            $was = self::CONDITION_RANK[(string) $from] ?? 0;

            return $now === $was ? null : $now < $was;
        }

        return in_array($key, self::HIGHER_IS_BETTER, true) ? $to > $from : $to < $from;
    }
}
