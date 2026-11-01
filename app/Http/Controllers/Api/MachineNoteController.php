<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Machines\AddMachineNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Machines\StoreMachineNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Machine;
use Illuminate\Http\JsonResponse;

final class MachineNoteController extends Controller
{
    public function store(StoreMachineNoteRequest $request, Machine $ownedMachine, AddMachineNote $action): JsonResponse
    {
        $this->authorize('update', $ownedMachine);

        $note = $action->handle($ownedMachine, $request->string('body')->toString());

        return (new NoteResource($note))->response()->setStatusCode(201);
    }
}
