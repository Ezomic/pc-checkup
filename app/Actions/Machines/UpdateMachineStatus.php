<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Enums\MachineStatus;
use App\Models\Machine;

final class UpdateMachineStatus
{
    public function handle(Machine $machine, MachineStatus $status): void
    {
        $machine->forceFill(['status' => $status])->save();
    }
}
