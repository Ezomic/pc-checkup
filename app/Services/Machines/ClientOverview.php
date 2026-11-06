<?php

declare(strict_types=1);

namespace App\Services\Machines;

use App\Enums\MachineStatus;
use App\Models\Client;
use App\Models\Machine;
use App\Services\NeedsAttention;

/**
 * @phpstan-import-type Reason from NeedsAttention
 *
 * @phpstan-type Row array{id: int, name: string|null, model: string|null, os: string|null, device_type: string|null, status: MachineStatus, last_scan_at: string|null, level: 'urgent'|'soon'|'fine', reasons: list<Reason>, reason_count: int, verdict: string|null}
 * @phpstan-type Summary array{total: int, urgent: int, soon: int, fine: int}
 */
final readonly class ClientOverview
{
    private const array LEVEL_ORDER = ['urgent' => 0, 'soon' => 1, 'fine' => 2];

    public function __construct(private NeedsAttention $attention) {}

    /**
     * @return array{summary: Summary, machines: list<Row>}
     */
    public function handle(Client $client): array
    {
        $rows = [];

        foreach ($client->machines()->with('newestReport')->orderBy('id')->get() as $machine) {
            $rows[] = $this->row($machine);
        }

        usort($rows, fn (array $a, array $b): int => [self::LEVEL_ORDER[$a['level']], $b['reason_count'], mb_strtolower($a['name'] ?? $a['model'] ?? ''), $a['id']]
            <=> [self::LEVEL_ORDER[$b['level']], $a['reason_count'], mb_strtolower($b['name'] ?? $b['model'] ?? ''), $b['id']]);

        $count = fn (string $level): int => count(array_filter($rows, fn (array $row): bool => $row['level'] === $level));

        return [
            'summary' => ['total' => count($rows), 'urgent' => $count('urgent'), 'soon' => $count('soon'), 'fine' => $count('fine')],
            'machines' => $rows,
        ];
    }

    /**
     * @return Row
     */
    private function row(Machine $machine): array
    {
        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'model' => $machine->model,
            'os' => $machine->os,
            'device_type' => $machine->device_type,
            'status' => $machine->status,
            'last_scan_at' => $machine->newestReport?->created_at?->toJSON(),
            ...$this->attention->assess($machine),
        ];
    }
}
