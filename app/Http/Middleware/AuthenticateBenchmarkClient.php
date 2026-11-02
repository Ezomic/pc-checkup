<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiAbility;
use App\Models\ScanCode;
use App\Models\User;
use App\Services\Scans\ScanCodeThrottle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateBenchmarkClient
{
    public const SCAN_CODE_HEADER = 'X-Scan-Code';

    public const SCAN_CODE_ATTRIBUTE = 'bench.scan_code_id';

    public function __construct(private readonly ScanCodeThrottle $throttle) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            return $this->viaToken($request, $next);
        }

        if ($request->hasHeader(self::SCAN_CODE_HEADER)) {
            return $this->viaScanCode($request, $next);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function viaToken(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->tokenCan(ApiAbility::Write->value)) {
            return response()->json(['message' => 'Invalid ability provided.'], 403);
        }

        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    /**
     * The code is only looked at, never claimed: it stays usable for the report upload that
     * follows the benchmark. Wrong guesses feed the same throttle as redeeming does.
     *
     * @param  Closure(Request): Response  $next
     */
    private function viaScanCode(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();

        $wait = $this->throttle->retryAfter($ip);

        if ($wait > 0) {
            return response()->json(['message' => 'Too many wrong codes. Wait a while before trying again.'], 429, ['Retry-After' => $wait]);
        }

        $scan = $this->usableScanCode($request->header(self::SCAN_CODE_HEADER));

        if ($scan === null) {
            $this->throttle->recordFailure($ip);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set(self::SCAN_CODE_ATTRIBUTE, $scan->id);

        return $next($request);
    }

    private function usableScanCode(mixed $code): ?ScanCode
    {
        if (! is_string($code) || preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return null;
        }

        return ScanCode::query()->forCode($code)->usable()->first();
    }
}
