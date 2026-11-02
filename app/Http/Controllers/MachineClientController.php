<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Clients\AssignMachineToClient;
use App\Http\Requests\Clients\AssignMachineClientRequest;
use App\Models\Machine;
use Illuminate\Http\RedirectResponse;

final class MachineClientController extends Controller
{
    public function update(AssignMachineClientRequest $request, Machine $machine, AssignMachineToClient $action): RedirectResponse
    {
        $this->authorize('update', $machine);

        $action->handle($machine, $request->clientId());

        return back();
    }
}
