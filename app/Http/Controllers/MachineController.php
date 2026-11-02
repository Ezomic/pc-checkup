<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\CreateManualMachine;
use App\Http\Requests\Machines\StoreMachineRequest;
use App\Models\Machine;
use App\Models\Photo;
use App\Models\User;
use App\Services\Checklist\ChecklistResolver;
use App\Services\Flags\FlagAdvice;
use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\ReportFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class MachineController extends Controller
{
    public function index(Request $request, FlagAdvisor $advisor): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('machines/Index', [
            'machines' => $user->machines()
                ->with('newestReport')
                ->withCount('reports')
                ->withMax('reports as last_report_at', 'created_at')
                ->latest()
                ->latest('id')
                ->paginate(Config::integer('pagination.per_page'))
                ->through(function (Machine $machine) use ($advisor): array {
                    $advice = $advisor->adviseAll($machine->newestReport->flags ?? []);

                    return [
                        ...$machine->withoutRelations()->toArray(),
                        'top_issue' => ($advice[0] ?? null)?->toArray(),
                        'issue_count' => count($advice),
                    ];
                })
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

        $report = $machine->latestReport();
        $checklist = ['fail' => [], 'pass' => [], 'na' => [], 'unchecked' => []];

        foreach ($resolver->withEntries($machine) as $item) {
            $outcome = $item['outcome'] ?? 'unchecked';
            unset($item['outcome']);
            $checklist[$outcome][] = $item;
        }

        return Inertia::render('machines/Summary', [
            'machine' => $machine->only(['id', 'name', 'serial', 'hardware_uuid', 'os', 'device_type', 'model', 'status']),
            'report' => $report === null ? null : ['created_at' => $report->created_at, 'flags' => $report->flags],
            'checklist' => $checklist,
            'notes' => $machine->notes()->latest()->latest('id')->get(['id', 'body', 'created_at']),
        ]);
    }

    public function show(Machine $machine, ChecklistResolver $resolver, FlagAdvisor $advisor, ReportFacts $facts): Response
    {
        $this->authorize('view', $machine);

        $report = $machine->latestReport();

        return Inertia::render('machines/Show', [
            'machine' => $machine->only(['id', 'name', 'serial', 'hardware_uuid', 'os', 'device_type', 'model', 'status']),
            'report' => $report === null ? null : [
                'id' => $report->id,
                'created_at' => $report->created_at,
                'advice' => array_map(fn (FlagAdvice $advice): array => $advice->toArray(), $advisor->adviseAll($report->flags)),
                'facts' => $facts->from($report->markdown),
                'markdown' => $report->markdown,
            ],
            'checklist' => $resolver->withEntries($machine),
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
