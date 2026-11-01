<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Machine;
use App\Models\User;

final class MachinePolicy
{
    public function view(User $user, Machine $machine): bool
    {
        return $user->id === $machine->user_id;
    }

    public function update(User $user, Machine $machine): bool
    {
        return $user->id === $machine->user_id;
    }
}
