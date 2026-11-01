<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Enums\ChecklistOutcome;
use App\Models\Machine;

final class RecordChecklistEntry
{
    public function handle(Machine $machine, string $itemKey, ?ChecklistOutcome $outcome, ?string $note): void
    {
        if ($outcome === null) {
            $machine->checklistEntries()->where('item_key', $itemKey)->delete();

            return;
        }

        $machine->checklistEntries()->updateOrCreate(
            ['item_key' => $itemKey],
            ['outcome' => $outcome, 'note' => $note],
        );
    }
}
