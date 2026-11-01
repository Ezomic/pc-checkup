<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Machines\UpdateMachineStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Machines\UpdateMachineStatusRequest;
use App\Http\Resources\MachineStatusResource;
use App\Models\Machine;

final class MachineStatusController extends Controller
{
    public function update(UpdateMachineStatusRequest $request, Machine $ownedMachine, UpdateMachineStatus $action): MachineStatusResource
    {
        $this->authorize('update', $ownedMachine);

        $action->handle($ownedMachine, $request->status());

        return new MachineStatusResource($ownedMachine);
    }
}
