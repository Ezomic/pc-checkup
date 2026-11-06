<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\BuildBeforeAfter;
use App\Actions\Machines\ResolveReportPair;
use App\Enums\ShareLocale;
use App\Models\Machine;
use App\Models\Report;
use App\Services\Share\SharedMachineView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MachineBeforeAfterController extends Controller
{
    public function show(Request $request, Machine $machine, ResolveReportPair $pair, BuildBeforeAfter $build, SharedMachineView $view): Response|RedirectResponse
    {
        $this->authorize('view', $machine);

        $reports = $pair->handle($machine, $this->id($request, 'before'), $this->id($request, 'after'));

        if ($reports === null) {
            return to_route('machines.before-after', [$machine, 'lang' => $this->language($request)?->value]);
        }

        $client = $machine->client;
        app()->setLocale(($this->language($request) ?? $client->locale ?? ShareLocale::English)->value);

        return Inertia::render('machines/BeforeAfter', [
            'machine' => $view->identity($machine),
            'client' => $client === null ? null : ['name' => $client->name],
            'comparison' => $build->handle($reports[0], $reports[1], false),
            'selected' => ['before' => $reports[0]->id, 'after' => $reports[1]->id],
            'scans' => $machine->reports()->latest()->latest('id')->get(['id', 'created_at'])
                ->map(fn (Report $report): array => ['id' => $report->id, 'scanned_at' => $report->created_at?->toJSON()])
                ->all(),
        ]);
    }

    private function id(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        abort_unless($value === null || is_string($value), 404);

        return $value === '' ? null : $value;
    }

    private function language(Request $request): ?ShareLocale
    {
        $value = $request->query('lang');

        return is_string($value) ? ShareLocale::tryFrom($value) : null;
    }
}
