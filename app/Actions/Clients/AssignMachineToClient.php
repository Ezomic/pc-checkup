<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Machine;

final class AssignMachineToClient
{
    public function handle(Machine $machine, ?int $clientId): void
    {
        $machine->forceFill(['client_id' => $clientId])->save();
    }
}
