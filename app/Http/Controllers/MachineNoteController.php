<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\AddMachineNote;
use App\Actions\Machines\DeleteMachineNote;
use App\Http\Requests\Machines\StoreMachineNoteRequest;
use App\Models\Machine;
use App\Models\MachineNote;
use Illuminate\Http\RedirectResponse;

final class MachineNoteController extends Controller
{
    public function store(StoreMachineNoteRequest $request, Machine $machine, AddMachineNote $action): RedirectResponse
    {
        $this->authorize('update', $machine);

        $action->handle($machine, $request->string('body')->toString());

        return to_route('machines.show', $machine);
    }

    public function destroy(Machine $machine, MachineNote $note, DeleteMachineNote $action): RedirectResponse
    {
        $this->authorize('update', $machine);

        $action->handle($note);

        return to_route('machines.show', $machine);
    }
}
