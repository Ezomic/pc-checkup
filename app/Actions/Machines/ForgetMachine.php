<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ForgetMachine
{
    public function handle(Machine $machine): void
    {
        $paths = $machine->photos()->pluck('path')->all();

        DB::transaction(function () use ($machine): void {
            $machine->photos()->delete();
            $machine->notes()->delete();
            $machine->checklistEntries()->delete();
            $machine->reports()->withTrashed()->forceDelete();
            $machine->forceDelete();
        });

        Storage::disk('photos')->delete($paths);
    }
}
