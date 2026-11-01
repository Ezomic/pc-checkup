<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Scans\RedeemScanCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RedeemScanCodeRequest;
use App\Http\Resources\ImportedReportResource;
use App\Services\Scans\ScanCodeThrottle;
use Illuminate\Http\JsonResponse;

final class ScanReportController extends Controller
{
    public const INVALID = 'That scan code is not valid, has expired or was already used. Start a new scan in the app.';

    public function store(RedeemScanCodeRequest $request, RedeemScanCode $action, ScanCodeThrottle $throttle): JsonResponse
    {
        $ip = (string) $request->ip();

        $wait = $throttle->retryAfter($ip);

        if ($wait > 0) {
            return response()->json(['message' => 'Too many wrong codes. Wait a while before trying again.'], 429, ['Retry-After' => $wait]);
        }

        $report = $action->handle($request->code(), $request->markdown());

        if ($report === null) {
            $throttle->recordFailure($ip);

            return response()->json(['message' => self::INVALID, 'errors' => ['code' => [self::INVALID]]], 422);
        }

        return (new ImportedReportResource($report))
            ->response()
            ->setStatusCode($report->wasRecentlyCreated ? 201 : 200);
    }
}
