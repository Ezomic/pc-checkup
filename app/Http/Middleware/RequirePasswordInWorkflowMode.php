<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequirePassword in workflow mode only, where it is a fresh trip through ID (WEB-28).
 * A route that asks for the current password itself in base mode needs nothing more
 * there, and confirming on a separate page first would only make the user type it twice.
 */
final class RequirePasswordInWorkflowMode
{
    public function __construct(private readonly RequirePassword $requirePassword) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! Config::boolean('workflow.enabled')) {
            return $next($request);
        }

        return $this->requirePassword->handle($request, $next);
    }
}
