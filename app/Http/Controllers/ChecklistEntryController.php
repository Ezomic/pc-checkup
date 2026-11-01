<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\RecordChecklistEntry;
use App\Http\Requests\Machines\UpdateChecklistEntryRequest;
use App\Models\Machine;
use App\Services\Checklist\ChecklistResolver;
use Illuminate\Http\RedirectResponse;

final class ChecklistEntryController extends Controller
{
    public function update(
        UpdateChecklistEntryRequest $request,
        Machine $machine,
        string $item,
        ChecklistResolver $resolver,
        RecordChecklistEntry $action,
    ): RedirectResponse {
        $this->authorize('update', $machine);

        abort_unless($resolver->knows($machine, $item), 404);

        $action->handle($machine, $item, $request->outcome(), $request->note());

        return to_route('machines.show', $machine);
    }
}
