<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttentionLevel;
use App\Enums\FlagSeverity;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportDriveHealth;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportFreshness;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportVerdict;
use App\Services\Reports\ReportWindowsSecurity;
use Illuminate\Support\Facades\Date;

/**
 * @phpstan-import-type Card from ReportDetails
 *
 * @phpstan-type Reason array{level: 'urgent'|'soon', text: string}
 * @phpstan-type Item array{id: int, name: string, client: array{id: int, name: string}|null, level: 'urgent'|'soon', reasons: list<Reason>, reason_count: int}
 * @phpstan-type Overview array{total: int, urgent: int, rows: list<Item>, showing_all: bool}
 */
final readonly class NeedsAttention
{
    public const int URGENT_DISK_FREE_PERCENT = 5;

    public const int SOON_DISK_FREE_PERCENT = 10;

    public const int RESCAN_AFTER_DAYS = 180;

    public const int NEW_MACHINE_GRACE_DAYS = 7;

    public const int MAX_REASONS = 2;

    public const int LIST_LIMIT = 20;

    private const string COVERED_FLAGS = '/end of support|out of security support|^Only about \d+% of (?:drive|disk)|^Battery /';

    public function __construct(
        private ReportDetails $details,
        private ReportFacts $facts,
        private ReportFreshness $freshness,
        private FlagAdvisor $advisor,
    ) {}

    /**
     * @return Overview
     */
    public function handle(User $user, bool $all): array
    {
        $items = [];

        foreach ($user->machines()->with(['newestReport', 'client:id,name'])->get() as $machine) {
            $reasons = $this->analyse($machine, $machine->newestReport)['reasons'];

            if ($reasons !== []) {
                $items[] = $this->item($machine, $reasons);
            }
        }

        usort($items, fn (array $a, array $b): int => [AttentionLevel::from($a['level'])->rank(), $b['reason_count'], mb_strtolower($a['name']), $a['id']]
            <=> [AttentionLevel::from($b['level'])->rank(), $a['reason_count'], mb_strtolower($b['name']), $b['id']]);

        return [
            'total' => count($items),
            'urgent' => count(array_filter($items, fn (array $item): bool => $item['level'] === AttentionLevel::Urgent->value)),
            'rows' => $all ? $items : array_slice($items, 0, self::LIST_LIMIT),
            'showing_all' => $all,
        ];
    }

    /**
     * @return array{level: 'urgent'|'soon'|'fine', reasons: list<Reason>, reason_count: int, verdict: string|null}
     */
    public function assess(Machine $machine): array
    {
        $analysis = $this->analyse($machine, $machine->newestReport);
        $reasons = $analysis['reasons'];

        return [
            'level' => $reasons[0]['level'] ?? 'fine',
            'reasons' => array_slice($reasons, 0, self::MAX_REASONS),
            'reason_count' => count($reasons),
            'verdict' => $analysis['verdict'],
        ];
    }

    /**
     * @param  list<Reason>  $reasons
     * @return Item
     */
    private function item(Machine $machine, array $reasons): array
    {
        return [
            'id' => $machine->id,
            'name' => $machine->name ?? $machine->model ?? 'Unnamed machine',
            'client' => $machine->client?->only(['id', 'name']),
            'level' => $reasons[0]['level'],
            'reasons' => array_slice($reasons, 0, self::MAX_REASONS),
            'reason_count' => count($reasons),
        ];
    }

    /**
     * @return array{reasons: list<Reason>, verdict: string|null}
     */
    private function analyse(Machine $machine, ?Report $report): array
    {
        if ($report === null) {
            return ['reasons' => $this->unscanned($machine), 'verdict' => null];
        }

        $cards = array_column($this->details->cards($report->markdown, $report->flags, $machine->device_type), null, 'id');

        $sections = ReportSections::parse($report->markdown);
        $drives = new ReportDriveHealth;
        $security = new ReportWindowsSecurity;

        $reasons = array_values(array_filter([
            $this->operatingSystem($report->flags),
            ...$this->battery($cards),
            $this->disk($this->facts->from($report->markdown)['free_disk']),
            $this->verdict($cards),
            ...$drives->reasons($sections),
            ...$security->reasons($sections),
            ...$this->flags($report->flags, [...$drives->coveredFlags($sections), ...$security->coveredFlags($sections)]),
            $this->freshness->isOlderThan($report, self::RESCAN_AFTER_DAYS) ? $this->reason(AttentionLevel::Soon, 'Last scanned more than 6 months ago') : null,
        ]));

        usort($reasons, fn (array $a, array $b): int => AttentionLevel::from($a['level'])->rank() <=> AttentionLevel::from($b['level'])->rank());

        return ['reasons' => $reasons, 'verdict' => ReportVerdict::outcome($cards['verdict']['rows'] ?? [])];
    }

    /**
     * @return list<Reason>
     */
    private function unscanned(Machine $machine): array
    {
        $grown = $machine->created_at?->lessThan(Date::now()->subDays(self::NEW_MACHINE_GRACE_DAYS));

        return $grown === true ? [$this->reason(AttentionLevel::Soon, 'No scan yet')] : [];
    }

    /**
     * @param  list<string>  $flags
     * @return Reason|null
     */
    private function operatingSystem(array $flags): ?array
    {
        $unsupported = array_filter($flags, fn (string $flag): bool => preg_match(FlagAdvisor::UNSUPPORTED_OS, $flag) === 1) !== [];

        return $unsupported ? $this->reason(AttentionLevel::Urgent, 'The operating system no longer gets security updates') : null;
    }

    /**
     * @param  array<string, Card>  $cards
     * @return list<Reason>
     */
    private function battery(array $cards): array
    {
        $worn = array_filter($cards['battery']['rows'] ?? [], fn (array $row): bool => $row['label'] === 'Condition' && $row['tone'] === 'bad') !== [];
        $weak = array_filter($cards['verdict']['rows'] ?? [], fn (array $row): bool => str_starts_with($row['label'], 'The battery')) !== [];

        return match (true) {
            $worn => [$this->reason(AttentionLevel::Urgent, 'The battery needs service or replacing')],
            $weak => [$this->reason(AttentionLevel::Soon, 'The battery is getting weak')],
            default => [],
        };
    }

    /**
     * @return Reason|null
     */
    private function disk(?string $freeDisk): ?array
    {
        if ($freeDisk === null || preg_match('/^(\d{1,3})%$/', $freeDisk, $match) !== 1 || (int) $match[1] > 100) {
            return null;
        }

        $free = (int) $match[1];
        $text = "Only {$free}% of the drive is free";

        return match (true) {
            $free < self::URGENT_DISK_FREE_PERCENT => $this->reason(AttentionLevel::Urgent, $text),
            $free < self::SOON_DISK_FREE_PERCENT => $this->reason(AttentionLevel::Soon, $text),
            default => null,
        };
    }

    /**
     * @param  array<string, Card>  $cards
     * @return Reason|null
     */
    private function verdict(array $cards): ?array
    {
        $verdicts = array_column($cards['verdict']['rows'] ?? [], 'value', 'label');

        return match ($verdicts['Verdict'] ?? null) {
            'Replace' => $this->reason(AttentionLevel::Urgent, 'The scan says this machine is worth replacing'),
            'Repair' => $this->reason(AttentionLevel::Soon, 'The scan says this machine is worth repairing'),
            default => null,
        };
    }

    /**
     * @param  list<string>  $flags
     * @param  list<string>  $covered  Patterns of the flags that the health section already judged.
     * @return list<Reason>
     */
    private function flags(array $flags, array $covered): array
    {
        $own = array_filter($flags, fn (string $flag): bool => preg_match(self::COVERED_FLAGS, $flag) !== 1 && array_filter($covered, fn (string $pattern): bool => preg_match($pattern, $flag) === 1) === []);
        $high = array_filter($this->advisor->adviseAll(array_values($own)), fn ($advice): bool => $advice->severity === FlagSeverity::High);

        return array_values(array_map(
            fn (string $headline): array => $this->reason(AttentionLevel::Urgent, $headline),
            array_unique(array_map(fn ($advice): string => $advice->headline, $high)),
        ));
    }

    /**
     * @return Reason
     */
    private function reason(AttentionLevel $level, string $text): array
    {
        return ['level' => $level->value, 'text' => $text];
    }
}
