<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\BuildReportInsights;
use App\Actions\Machines\BuildUpgradeAdvice;
use App\Actions\Machines\CompareLatestReports;
use App\Actions\Machines\CreateManualMachine;
use App\Actions\Machines\ForgetMachine;
use App\Enums\AttentionFilter;
use App\Http\Requests\Machines\StoreMachineRequest;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Photo;
use App\Models\User;
use App\Services\Checklist\ChecklistResolver;
use App\Services\Flags\FlagAdvice;
use App\Services\Flags\FlagAdvisor;
use App\Services\NeedsAttention;
use App\Services\Quote\EstimateContext;
use App\Services\Quote\EuroAmount;
use App\Services\Reports\DetailCardTabs;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportFreshness;
use App\Services\Reports\ReportHealth;
use App\Services\Reports\ReportScriptVersion;
use App\Services\Reports\ReportTrends;
use App\Services\Warranty\WarrantyLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class MachineController extends Controller
{
    public function index(Request $request, FlagAdvisor $advisor, ReportFreshness $freshness, NeedsAttention $attention): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $reason = $request->query('reason');

        return Inertia::render('machines/Index', [
            'needsAttention' => $attention->handle($user, $request->query('attention') === 'all', is_string($reason) ? AttentionFilter::tryFrom($reason) : null),
            'machines' => $user->machines()
                ->with('newestReport')
                ->withCount('reports')
                ->withMax('reports as last_report_at', 'created_at')
                ->latest()
                ->latest('id')
                ->paginate(Config::integer('pagination.per_page'))
                ->through(function (Machine $machine) use ($advisor, $freshness): array {
                    $advice = $advisor->adviseAll($machine->newestReport->flags ?? []);

                    return [
                        ...$machine->withoutRelations()->toArray(),
                        'top_issue' => ($advice[0] ?? null)?->toArray(),
                        'issue_count' => count($advice),
                        'stale' => $freshness->isStale($machine->newestReport),
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

    public function destroy(Machine $machine, ForgetMachine $action): RedirectResponse
    {
        $this->authorize('delete', $machine);

        $action->handle($machine);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Machine and all of its reports deleted permanently.']);

        return to_route('machines.index');
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

    public function show(Machine $machine, ChecklistResolver $resolver, FlagAdvisor $advisor, ReportFacts $facts, BuildReportInsights $insights, ReportFreshness $freshness, CompareLatestReports $changes, ReportDetails $details, ReportTrends $trends, ReportScriptVersion $scriptVersion, WarrantyLink $warranty, EstimateContext $estimate, BuildUpgradeAdvice $upgrade, ReportHealth $health, DetailCardTabs $tabs): Response
    {
        $this->authorize('view', $machine);

        $report = $machine->latestReport();
        $built = $report === null ? ['cards' => [], 'failed' => []] : $details->build($report->markdown, $report->flags, $machine->device_type);
        $cards = $built['cards'];
        $tabbed = $tabs->arrange($cards, $report === null ? [] : $report->flags);
        $priceItems = array_values($machine->user?->priceItems()->get(['id', 'name', 'price_cents', 'unit'])->all() ?? []);

        return Inertia::render('machines/Show', [
            'machine' => $machine->only(['id', 'name', 'serial', 'hardware_uuid', 'os', 'device_type', 'model', 'rustdesk_id', 'status']),
            'report' => $report === null ? null : [
                'id' => $report->id,
                'created_at' => $report->created_at,
                'stale' => $freshness->isStale($report),
                'advice' => array_map(fn (FlagAdvice $advice): array => $advice->toArray(), $advisor->adviseAll($report->flags)),
                'facts' => $facts->from($report->markdown),
                'script' => $scriptVersion->for($report->markdown),
                'problems' => $health->problems($report->markdown, $built['failed']),
                'markdown' => $report->markdown,
            ],
            'changes' => $changes->handle($machine),
            'detailCards' => $tabbed['cards'],
            'verdictCard' => $tabbed['verdict'],
            'upgradeAdvice' => $upgrade->handle($report, $cards, $priceItems),
            'warranty' => $report === null ? null : $warranty->for($report->markdown),
            'estimate' => $estimate->from($cards),
            'priceItems' => $priceItems,
            'vatPercent' => EuroAmount::VAT_PERCENT,
            'trends' => $trends->forMachine($machine),
            'client' => $machine->client?->only(['id', 'name']),
            'clients' => Client::query()->where('user_id', $machine->user_id)->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'checklist' => $resolver->withEntries($machine),
            ...$insights->handle($machine),
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
