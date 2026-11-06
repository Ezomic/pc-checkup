<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\CompareReportPair;
use App\Actions\Machines\ListMachineHistory;
use App\Models\Machine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MachineHistoryController extends Controller
{
    public function __invoke(Request $request, Machine $machine, ListMachineHistory $history, CompareReportPair $compare): Response
    {
        $this->authorize('view', $machine);

        $from = filter_var($request->query('from'), FILTER_VALIDATE_INT);
        $to = filter_var($request->query('to'), FILTER_VALIDATE_INT);
        $comparing = $from !== false && $to !== false;
        $error = null;
        $comparison = null;

        if ($comparing && $from === $to) {
            $error = 'Pick two different scans to compare.';
        } elseif ($comparing) {
            $reports = $machine->reports()->whereIn('id', [$from, $to])->get()->values();
            $first = $reports->get(0);
            $second = $reports->get(1);

            if ($first !== null && $second !== null) {
                $comparison = $compare->handle($first, $second);
            } else {
                $error = 'One of those scans is not available for this machine.';
            }
        }

        return Inertia::render('machines/History', [
            'machine' => $machine->only(['id', 'name', 'model', 'device_type']),
            'scans' => $history->handle($machine)->withQueryString(),
            'comparison' => $comparison,
            'comparison_error' => $error,
            'selected' => $comparing ? [$from, $to] : [],
        ]);
    }
}
