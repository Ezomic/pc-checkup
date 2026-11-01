<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * In workflow mode Thijssensoftware ID owns the identity and the login page
 * offers only "Sign in with Thijssensoftware". Fortify's local credential
 * routes stay registered underneath it, and that is not merely untidy: a user
 * provisioned by id-client has a null password, so a reset link would let them
 * mint one and sign in locally from then on. That session is established
 * without ID, so it outlives revoking their application grant and ignores
 * back-channel logout, which makes per-app access control in ID stop being the
 * boundary it is meant to be.
 *
 * Blocked here rather than dropped from config/fortify.php because Wayfinder
 * generates its route helpers from the registered routes. Removing the routes
 * removes the exports, and the auth pages that still import them in standalone
 * mode then fail to build. See WEB-23.
 *
 * The same goes for every other credential the app could hold for a user:
 * passkeys (their sign-in is a guest route of its own), two-factor, changing a
 * password, and the Security page that manages them. Once confirming through
 * ID unlocked the pages behind RequirePassword (WEB-28), a passkey enrolled
 * there would have been a way in that never touches ID.
 */
final class BlockLocalCredentials
{
    /**
     * @var list<string>
     */
    private const BLOCKED = [
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'login.store',
        // Confirmation goes through ID instead (WEB-28), so a local password set before
        // the switch to workflow mode cannot stand in for it.
        'password.confirm.store',
        'passkey.*',
        'two-factor.*',
        'security.edit',
        'user-password.update',
        'well-known.passkeys',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            (bool) config('workflow.enabled') && $request->routeIs(...self::BLOCKED),
            Response::HTTP_NOT_FOUND,
        );

        return $next($request);
    }
}
