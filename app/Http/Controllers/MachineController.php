<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\CreateManualMachine;
use App\Http\Requests\Machines\StoreMachineRequest;
use App\Models\Machine;
use App\Models\Photo;
use App\Models\User;
use App\Services\Checklist\ChecklistResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class MachineController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('machines/Index', [
            'machines' => $user->machines()
                ->withCount('reports')
                ->withMax('reports as last_report_at', 'created_at')
                ->latest()
                ->latest('id')
                ->paginate(Config::integer('pagination.per_page'))
                ->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('machines/Create');
    }

    public function store(StoreMachineRequest $request, CreateManualMachine $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $machine = $action->handle(
            $user,
            $request->string('serial')->toString(),
            $request->string('hardware_uuid')->toString(),
            $request->string('name')->toString(),
            $request->string('os')->toString(),
            $request->string('device_type')->toString(),
            $request->string('model')->toString(),
        );

        Inertia::flash('toast', $machine->wasRecentlyCreated || $machine->wasChanged()
            ? ['type' => 'success', 'message' => 'Machine added.']
            : ['type' => 'info', 'message' => 'This machine was already registered, so nothing was changed.']);

        return to_route('machines.show', $machine);
    }

    public function summary(Machine $machine, ChecklistResolver $resolver): Response
    {
        $this->authorize('view', $machine);

        $report = $machine->reports()->latest()->latest('id')->first();
        $entries = $machine->checklistEntries()->get()->keyBy('item_key');
        $checklist = ['fail' => [], 'pass' => [], 'na' => [], 'unchecked' => []];

        foreach ($resolver->for($machine) as $item) {
            $entry = $entries->get($item['key']);
            $checklist[$entry?->outcome->value ?? 'unchecked'][] = [...$item, 'note' => $entry?->note];
        }

        return Inertia::render('machines/Summary', [
            'machine' => $machine->only(['id', 'name', 'serial', 'hardware_uuid', 'os', 'device_type', 'model', 'status']),
            'report' => $report === null ? null : ['created_at' => $report->created_at, 'flags' => $report->flags],
            'checklist' => $checklist,
            'notes' => $machine->notes()->latest()->latest('id')->get(['id', 'body', 'created_at']),
        ]);
    }

    public function show(Machine $machine, ChecklistResolver $resolver): Response
    {
        $this->authorize('view', $machine);

        $report = $machine->reports()->latest()->latest('id')->first();
        $entries = $machine->checklistEntries()->get()->keyBy('item_key');

        return Inertia::render('machines/Show', [
            'machine' => $machine->only(['id', 'name', 'serial', 'hardware_uuid', 'os', 'device_type', 'model', 'status']),
            'report' => $report === null ? null : [
                'id' => $report->id,
                'created_at' => $report->created_at,
                'flags' => $report->flags,
                'markdown' => $report->markdown,
            ],
            'checklist' => array_map(fn (array $item): array => [
                ...$item,
                'outcome' => $entries->get($item['key'])?->outcome->value,
                'note' => $entries->get($item['key'])?->note,
            ], $resolver->for($machine)),
            'notes' => $machine->notes()->latest()->latest('id')->get(['id', 'body', 'created_at']),
            'photos' => $machine->photos()->oldest()->oldest('id')->get()->map(fn (Photo $photo): array => [
                'id' => $photo->id,
                'caption' => $photo->caption,
                'width' => $photo->width,
                'height' => $photo->height,
                'url' => route('machines.photos.show', [$machine, $photo], false),
            ])->all(),
        ]);
    }
}
