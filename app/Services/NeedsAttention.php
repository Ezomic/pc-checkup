<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttentionFilter;
use App\Enums\AttentionLevel;
use App\Enums\FlagSeverity;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\ReportActivationBitLocker;
use App\Services\Reports\ReportAntivirusProducts;
use App\Services\Reports\ReportBackups;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportDriveHealth;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportFreshness;
use App\Services\Reports\ReportHealth;
use App\Services\Reports\ReportMachineAge;
use App\Services\Reports\ReportNetworkSettings;
use App\Services\Reports\ReportRemoteAccess;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportUpdates;
use App\Services\Reports\ReportVerdict;
use App\Services\Reports\ReportWindowsSecurity;
use Illuminate\Support\Facades\Date;

/**
 * @phpstan-import-type Card from ReportDetails
 *
 * @phpstan-type Reason array{level: 'urgent'|'soon', text: string}
 * @phpstan-type Tagged array{level: 'urgent'|'soon', text: string, filter: AttentionFilter|null}
 * @phpstan-type Item array{id: int, name: string, client: array{id: int, name: string}|null, level: 'urgent'|'soon', reasons: list<Reason>, reason_count: int}
 * @phpstan-type Filter array{key: string, label: string, count: int}
 * @phpstan-type Overview array{total: int, urgent: int, rows: list<Item>, showing_all: bool, filter: string|null, filters: list<Filter>}
 */
final readonly class NeedsAttention
{
    public const int URGENT_DISK_FREE_PERCENT = 5;

    public const int SOON_DISK_FREE_PERCENT = 10;

    public const int RESCAN_AFTER_DAYS = 180;

    public const int NEW_MACHINE_GRACE_DAYS = 7;

    public const int OLD_MACHINE_YEARS = 7;

    public const int MAX_REASONS = 2;

    public const int LIST_LIMIT = 20;

    public const string BROKEN_REPORT = 'The latest report looks broken';

    private const string COVERED_FLAGS = '/end of support|out of security support|^Only about \d+% of (?:drive|disk)|^Battery /';

    private const string DRIVE_FLAGS = '/^Disk (?:SMART status|.+? (?:health|status)) is |disk error event|SSD wear is /';

    private const string NO_BACKUP_FLAG = '/^No backup was found/';

    public function __construct(
        private ReportDetails $details,
        private ReportFacts $facts,
        private ReportFreshness $freshness,
        private FlagAdvisor $advisor,
        private ReportHealth $health,
    ) {}

    /**
     * @return Overview
     */
    public function handle(User $user, bool $all, ?AttentionFilter $filter = null): array
    {
        $items = [];
        $counts = array_fill_keys(array_column(AttentionFilter::cases(), 'value'), 0);

        foreach ($user->machines()->with(['newestReport', 'client:id,name'])->get() as $machine) {
            $reasons = $this->analyse($machine, $machine->newestReport)['reasons'];
            $filters = array_unique(array_filter(array_map(fn (array $reason): ?string => $reason['filter']?->value, $reasons)));

            foreach ($filters as $key) {
                $counts[$key]++;
            }

            if ($reasons !== [] && ($filter === null || in_array($filter->value, $filters, true))) {
                $items[] = $this->item($machine, $reasons, $filter);
            }
        }

        usort($items, fn (array $a, array $b): int => [AttentionLevel::from($a['level'])->rank(), $b['reason_count'], mb_strtolower($a['name']), $a['id']]
            <=> [AttentionLevel::from($b['level'])->rank(), $a['reason_count'], mb_strtolower($b['name']), $b['id']]);

        return [
            'total' => count($items),
            'urgent' => count(array_filter($items, fn (array $item): bool => $item['level'] === AttentionLevel::Urgent->value)),
            'rows' => $all ? $items : array_slice($items, 0, self::LIST_LIMIT),
            'showing_all' => $all,
            'filter' => $filter?->value,
            'filters' => array_map(fn (AttentionFilter $case): array => ['key' => $case->value, 'label' => $case->label(), 'count' => $counts[$case->value]], AttentionFilter::cases()),
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
            'reasons' => $this->shown($reasons, null),
            'reason_count' => count($reasons),
            'verdict' => $analysis['verdict'],
        ];
    }

    /**
     * @param  non-empty-list<Tagged>  $reasons
     * @return Item
     */
    private function item(Machine $machine, array $reasons, ?AttentionFilter $filter): array
    {
        return [
            'id' => $machine->id,
            'name' => $machine->name ?? $machine->model ?? 'Unnamed machine',
            'client' => $machine->client?->only(['id', 'name']),
            'level' => $reasons[0]['level'],
            'reasons' => $this->shown($reasons, $filter),
            'reason_count' => count($reasons),
        ];
    }

    /**
     * The reasons that match the filter go first, so a filtered row always shows why it is there.
     *
     * @param  list<Tagged>  $reasons
     * @return list<Reason>
     */
    private function shown(array $reasons, ?AttentionFilter $filter): array
    {
        if ($filter !== null) {
            usort($reasons, fn (array $a, array $b): int => ($b['filter'] === $filter) <=> ($a['filter'] === $filter));
        }

        return array_map(fn (array $reason): array => ['level' => $reason['level'], 'text' => $reason['text']], array_slice($reasons, 0, self::MAX_REASONS));
    }

    /**
     * @return array{reasons: list<Tagged>, verdict: string|null}
     */
    private function analyse(Machine $machine, ?Report $report): array
    {
        if ($report === null) {
            return ['reasons' => $this->unscanned($machine), 'verdict' => null];
        }

        $built = $this->details->build($report->markdown, $report->flags, $machine->device_type);
        $cards = array_column($built['cards'], null, 'id');

        $sections = ReportSections::parse($report->markdown);
        $drives = new ReportDriveHealth;
        $security = new ReportWindowsSecurity;

        $reasons = array_values(array_filter([
            $this->health->problems($report->markdown, $built['failed']) === [] ? null : $this->reason(AttentionLevel::Soon, self::BROKEN_REPORT),
            $this->operatingSystem($report->flags),
            ...$this->battery($cards),
            $this->disk($this->facts->from($report->markdown)['free_disk']),
            $this->verdict($cards),
            $this->backup($cards, $report->flags),
            $this->age($sections),
            ...$this->tagged($drives->reasons($sections), AttentionFilter::Drive),
            ...$this->tagged($security->reasons($sections), null),
            ...$this->tagged((new ReportUpdates)->reasons($sections), null),
            ...$this->tagged((new ReportNetworkSettings)->reasons($sections), null),
            ...$this->tagged((new ReportAntivirusProducts)->reasons($sections), null),
            ...$this->tagged((new ReportRemoteAccess)->reasons($sections), null),
            ...$this->tagged((new ReportActivationBitLocker)->reasons($sections), null),
            ...$this->flags($report->flags, [...$drives->coveredFlags($sections), ...$security->coveredFlags($sections)]),
            $this->freshness->isOlderThan($report, self::RESCAN_AFTER_DAYS) ? $this->reason(AttentionLevel::Soon, 'Last scanned more than 6 months ago', AttentionFilter::Stale) : null,
        ]));

        usort($reasons, fn (array $a, array $b): int => AttentionLevel::from($a['level'])->rank() <=> AttentionLevel::from($b['level'])->rank());

        return ['reasons' => $reasons, 'verdict' => ReportVerdict::outcome($cards['verdict']['rows'] ?? [])];
    }

    /**
     * @return list<Tagged>
     */
    private function unscanned(Machine $machine): array
    {
        $grown = $machine->created_at?->lessThan(Date::now()->subDays(self::NEW_MACHINE_GRACE_DAYS));

        return $grown === true ? [$this->reason(AttentionLevel::Soon, 'No scan yet')] : [];
    }

    /**
     * @param  list<string>  $flags
     * @return Tagged|null
     */
    private function operatingSystem(array $flags): ?array
    {
        $unsupported = array_filter($flags, fn (string $flag): bool => preg_match(FlagAdvisor::UNSUPPORTED_OS, $flag) === 1) !== [];

        return $unsupported ? $this->reason(AttentionLevel::Urgent, 'The operating system no longer gets security updates', AttentionFilter::UnsupportedOs) : null;
    }

    /**
     * @return Tagged|null
     */
    private function age(ReportSections $sections): ?array
    {
        $age = ReportMachineAge::of($sections);

        if ($age === null || $age['years'] < self::OLD_MACHINE_YEARS) {
            return null;
        }

        $text = $age['source'] === 'model' ? "About {$age['years']} years old" : "At least {$age['years']} years old, going by its firmware date";

        return $this->reason(AttentionLevel::Soon, $text, AttentionFilter::Age);
    }

    /**
     * @param  array<string, Card>  $cards
     * @return list<Tagged>
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
     * @return Tagged|null
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
     * @return Tagged|null
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
     * A report from before the backup freshness step only has the flag to go on.
     *
     * @param  array<string, Card>  $cards
     * @param  list<string>  $flags
     * @return Tagged|null
     */
    private function backup(array $cards, array $flags): ?array
    {
        $stale = isset($cards['backups'])
            ? array_filter($cards['backups']['rows'], fn (array $row): bool => $row['label'] === ReportBackups::VERDICT_LABEL && $row['tone'] === 'bad') !== []
            : preg_grep(self::NO_BACKUP_FLAG, $flags) !== [];

        return $stale ? $this->reason(AttentionLevel::Soon, 'No recent backup', AttentionFilter::Backup) : null;
    }

    /**
     * @param  list<string>  $flags
     * @param  list<string>  $covered  Patterns of the flags that the health section already judged.
     * @return list<Tagged>
     */
    private function flags(array $flags, array $covered): array
    {
        $own = array_filter($flags, fn (string $flag): bool => preg_match(self::COVERED_FLAGS, $flag) !== 1 && array_filter($covered, fn (string $pattern): bool => preg_match($pattern, $flag) === 1) === []);
        $reasons = [];

        foreach ($this->advisor->adviseAll(array_values($own)) as $advice) {
            if ($advice->severity === FlagSeverity::High) {
                $drive = preg_match(self::DRIVE_FLAGS, $advice->raw) === 1 || ($reasons[$advice->headline]['filter'] ?? null) === AttentionFilter::Drive;
                $reasons[$advice->headline] = $this->reason(AttentionLevel::Urgent, $advice->headline, $drive ? AttentionFilter::Drive : null);
            }
        }

        return array_values($reasons);
    }

    /**
     * @param  list<Reason>  $reasons
     * @return list<Tagged>
     */
    private function tagged(array $reasons, ?AttentionFilter $filter): array
    {
        return array_map(fn (array $reason): array => [...$reason, 'filter' => $filter], $reasons);
    }

    /**
     * @return Tagged
     */
    private function reason(AttentionLevel $level, string $text, ?AttentionFilter $filter = null): array
    {
        return ['level' => $level->value, 'text' => $text, 'filter' => $filter];
    }
}
