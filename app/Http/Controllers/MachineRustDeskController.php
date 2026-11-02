<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\UpdateMachineRustDeskId;
use App\Http\Requests\Machines\UpdateMachineRustDeskIdRequest;
use App\Models\Machine;
use Illuminate\Http\RedirectResponse;

final class MachineRustDeskController extends Controller
{
    public function update(UpdateMachineRustDeskIdRequest $request, Machine $machine, UpdateMachineRustDeskId $action): RedirectResponse
    {
        $this->authorize('update', $machine);

        $action->handle($machine, $request->rustdeskId());

        return to_route('machines.show', $machine);
    }
}
