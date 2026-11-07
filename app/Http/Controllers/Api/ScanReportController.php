<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Scans\RedeemPairingToken;
use App\Actions\Scans\RedeemScanCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RedeemScanCodeRequest;
use App\Http\Resources\ImportedReportResource;
use App\Services\Scans\ScanCodeThrottle;
use Illuminate\Http\JsonResponse;

final class ScanReportController extends Controller
{
    public const INVALID = 'That scan code is not valid, has expired or was already used. Start a new scan in the app.';

    public const INVALID_APPROVAL = 'That approval is not valid, has expired or was already used. Run the script again to get a new QR code.';

    public function store(RedeemScanCodeRequest $request, RedeemScanCode $codes, RedeemPairingToken $pairings, ScanCodeThrottle $throttle): JsonResponse
    {
        $ip = (string) $request->ip();

        $wait = $throttle->retryAfter($ip);

        if ($wait > 0) {
            return response()->json(['message' => 'Too many wrong codes. Wait a while before trying again.'], 429, ['Retry-After' => $wait]);
        }

        $token = $request->uploadToken();
        $report = $token === null
            ? $codes->handle($request->code(), $request->markdown())
            : $pairings->handle($token, $request->markdown());

        if ($report === null) {
            $throttle->recordFailure($ip);
            [$field, $message] = $token === null ? ['code', self::INVALID] : ['upload_token', self::INVALID_APPROVAL];

            return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
        }

        return (new ImportedReportResource($report))
            ->response()
            ->setStatusCode($report->wasRecentlyCreated ? 201 : 200);
    }
}
