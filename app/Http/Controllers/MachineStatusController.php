<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\UpdateMachineStatus;
use App\Http\Requests\Machines\UpdateMachineStatusRequest;
use App\Models\Machine;
use Illuminate\Http\RedirectResponse;

final class MachineStatusController extends Controller
{
    public function update(UpdateMachineStatusRequest $request, Machine $machine, UpdateMachineStatus $action): RedirectResponse
    {
        $this->authorize('update', $machine);

        $action->handle($machine, $request->status());

        return to_route('machines.show', $machine);
    }
}
