<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\MachineNote;

final class AddMachineNote
{
    public function handle(Machine $machine, string $body): MachineNote
    {
        return $machine->notes()->create(['body' => $body]);
    }
}
