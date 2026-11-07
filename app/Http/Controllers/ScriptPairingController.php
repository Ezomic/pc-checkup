<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Scans\PollPairing;
use App\Actions\Scans\StartPairing;
use App\Services\Scans\PairingThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the scan scripts call: no login, no cookies, and nothing about the computer comes in.
 */
final class ScriptPairingController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function store(Request $request, StartPairing $action): JsonResponse
    {
        return response()->json($action->handle($request->query('qr') === 'ascii'), 201, self::NO_STORE);
    }

    /**
     * The secret comes as a bearer token, never in the URL, so it stays out of access logs.
     */
    public function show(Request $request, string $pairing, PollPairing $action, PairingThrottle $throttle): JsonResponse
    {
        $ip = (string) $request->ip();
        $wait = $throttle->retryAfter($ip);

        if ($wait > 0) {
            return response()->json(['message' => 'Too many wrong requests. Wait a while before trying again.'], 429, ['Retry-After' => $wait, ...self::NO_STORE]);
        }

        $answer = $action->handle($pairing, (string) $request->bearerToken());

        if ($answer === null) {
            $throttle->recordFailure($ip);

            return response()->json(['status' => 'unknown'], 404, self::NO_STORE);
        }

        return response()->json($answer, 200, self::NO_STORE);
    }
}
