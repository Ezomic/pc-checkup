<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Machines\RecordChecklistEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Machines\UpdateChecklistEntryRequest;
use App\Http\Resources\ChecklistItemResource;
use App\Models\Machine;
use App\Services\Checklist\ChecklistResolver;

final class ChecklistEntryController extends Controller
{
    public function update(
        UpdateChecklistEntryRequest $request,
        Machine $ownedMachine,
        string $item,
        ChecklistResolver $resolver,
        RecordChecklistEntry $action,
    ): ChecklistItemResource {
        $this->authorize('update', $ownedMachine);

        abort_unless($resolver->knows($ownedMachine, $item), 404);

        $action->handle($ownedMachine, $item, $request->outcome(), $request->note());

        return new ChecklistItemResource($resolver->item($ownedMachine, $item));
    }
}
