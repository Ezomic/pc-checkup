<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Report;

final readonly class ResolveReportPair
{
    private const string ID_PATTERN = '/\A[1-9][0-9]{0,14}\z/';

    /**
     * Older scan first. Null when the same scan was picked twice. Every other bad pick is a 404, so a report of another machine or a deleted one cannot be told apart from a missing one.
     *
     * @return array{0: Report, 1: Report}|null
     */
    public function handle(Machine $machine, ?string $beforeId, ?string $afterId): ?array
    {
        $after = $afterId === null ? $this->latest($machine) : $this->owned($machine, $afterId);
        $before = $beforeId === null ? $this->previous($machine, $after) : $this->owned($machine, $beforeId);

        if ($before->is($after)) {
            return null;
        }

        return $this->isOlder($before, $after) ? [$before, $after] : [$after, $before];
    }

    /**
     * @return array{0: Report, 1: Report}
     */
    public function latestTwo(Machine $machine): array
    {
        $after = $this->latest($machine);

        return [$this->previous($machine, $after), $after];
    }

    private function latest(Machine $machine): Report
    {
        return $machine->reports()->latest()->latest('id')->firstOrFail();
    }

    private function previous(Machine $machine, Report $after): Report
    {
        return $machine->reports()
            ->whereKeyNot($after->id)
            ->where(fn ($query) => $query
                ->where('created_at', '<', $after->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $after->created_at)->where('id', '<', $after->id)))
            ->latest()->latest('id')
            ->firstOrFail();
    }

    private function owned(Machine $machine, string $id): Report
    {
        abort_unless(preg_match(self::ID_PATTERN, $id) === 1, 404);

        return $machine->reports()->whereKey((int) $id)->firstOrFail();
    }

    private function isOlder(Report $first, Report $second): bool
    {
        return [$first->created_at?->getTimestamp(), $first->id] < [$second->created_at?->getTimestamp(), $second->id];
    }
}
