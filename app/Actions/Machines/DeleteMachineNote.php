<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\MachineNote;

final class DeleteMachineNote
{
    public function handle(MachineNote $note): void
    {
        $note->delete();
    }
}
