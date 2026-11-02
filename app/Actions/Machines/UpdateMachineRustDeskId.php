<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;

final class UpdateMachineRustDeskId
{
    public function handle(Machine $machine, ?string $rustdeskId): void
    {
        $machine->forceFill(['rustdesk_id' => $rustdeskId])->save();
    }
}
