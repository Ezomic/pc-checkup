<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MachineDetailResource;
use App\Http\Resources\MachineResource;
use App\Models\Machine;
use App\Models\User;
use App\Services\Checklist\ChecklistResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Config;

final class MachineController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return MachineResource::collection(
            $user->machines()
                ->withCount('reports')
                ->withMax('reports as last_report_at', 'created_at')
                ->latest()
                ->latest('id')
                ->paginate(Config::integer('pagination.per_page'))
                ->withQueryString(),
        );
    }

    public function show(Machine $ownedMachine, ChecklistResolver $resolver): MachineDetailResource
    {
        $this->authorize('view', $ownedMachine);

        return new MachineDetailResource($ownedMachine, $ownedMachine->latestReport(), $resolver->withEntries($ownedMachine));
    }
}
