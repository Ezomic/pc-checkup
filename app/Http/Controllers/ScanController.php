<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Scans\IssueScanCode;
use App\Models\ScanCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ScanController extends Controller
{
    public function show(Request $request): Response
    {
        $code = $request->session()->get('scan_code');

        return Inertia::render('scans/Show', [
            'code' => is_string($code) ? $code : null,
            'expiresAt' => $this->scanOf($request)?->expires_at->toIso8601String(),
        ]);
    }

    public function store(Request $request, IssueScanCode $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return to_route('scans.show')->with('scan_code', $action->handle($user));
    }

    public function status(Request $request): JsonResponse
    {
        $scan = $this->scanOf($request);
        $machineId = $scan?->report?->machine_id;

        $status = match (true) {
            $scan === null => 'none',
            $machineId !== null => 'received',
            $scan->used_at === null && $scan->expires_at->isFuture() => 'waiting',
            default => 'expired',
        };

        return response()->json(['status' => $status, 'machine_id' => $machineId]);
    }

    private function scanOf(Request $request): ?ScanCode
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user->scanCode()->with('report')->first();
    }
}
