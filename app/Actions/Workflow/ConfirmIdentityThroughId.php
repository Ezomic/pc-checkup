<?php

declare(strict_types=1);

namespace App\Actions\Workflow;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Thijssensoftware\IdClient\Http\Middleware\EnsureSsoSessionIsActive;

/**
 * Password confirmation for users who have no password. In workflow mode ID owns the
 * identity and id-client provisions users without one, so Fortify's confirm form could
 * never pass and every page behind RequirePassword, the API tokens among them, was out
 * of reach. A round trip through ID stands in for the password: the confirmation counts
 * once ID has signed the user in again after it was asked for. That needs ID's own
 * session, which a stolen app session or remember cookie does not carry. The sign-in
 * that was current when confirmation was asked for never counts, even within the same
 * second. See WEB-28.
 *
 * The trip comes back here rather than to the page that asked, so the confirmation is
 * complete by the time the user lands there. WEB-36.
 */
final class ConfirmIdentityThroughId
{
    private const REQUESTED_AT = 'workflow.identity_confirmation.requested_at';

    private const SIGN_IN_AT_REQUEST = 'workflow.identity_confirmation.sign_in_at_request';

    private const RETURN_TO = 'workflow.identity_confirmation.return_to';

    public function handle(Request $request): Response
    {
        $session = $request->session();
        $requestedAt = $session->pull(self::REQUESTED_AT);
        $signInAtRequest = $session->pull(self::SIGN_IN_AT_REQUEST);
        $intended = $session->pull('url.intended');
        $stashed = $session->pull(self::RETURN_TO);
        $returnTo = $this->pageOfThisApp($request, $intended) ?? $this->pageOfThisApp($request, $stashed);
        $signedInAt = $session->get(EnsureSsoSessionIsActive::AUTHENTICATED_AT);

        if (is_int($requestedAt) && is_int($signedInAt) && $signedInAt >= $requestedAt && $signedInAt !== $signInAtRequest) {
            $session->passwordConfirmed();

            return redirect()->to($returnTo ?? Fortify::redirects('password-confirmation'));
        }

        $session->put(self::REQUESTED_AT, Date::now()->getTimestamp());
        $session->put(self::SIGN_IN_AT_REQUEST, $signedInAt);
        $session->put(self::RETURN_TO, $returnTo);

        // id-client's callback lands on the intended URL. Left on the page that asked, it
        // cost another visit here before the confirmation completed, and a page a Delete
        // was pressed on never makes that visit, so the press had to be made twice.
        $session->put('url.intended', route('password.confirm'));

        // Inertia::location, not a redirect: the first visit is usually an Inertia request,
        // and a plain redirect to ID's origin would be followed by XHR and blocked by CORS.
        return Inertia::location(route('sso.redirect'));
    }

    /**
     * For anything but a GET, RequirePassword takes the Referer as the page that asked, and
     * that header is the client's to write. It only counts when it names a page of this app
     * on this origin that answers a GET, by a prefix match through the slash that ends the
     * host, so userinfo or a lookalike host breaks the match. The confirmation itself never
     * counts, or completing it would only start another trip through ID.
     */
    private function pageOfThisApp(Request $request, mixed $url): ?string
    {
        if (! is_string($url) || ! str_starts_with($url, $request->getSchemeAndHttpHost().'/')) {
            return null;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($url));
        } catch (HttpExceptionInterface|RequestExceptionInterface) {
            return null;
        }

        return $route->named('password.confirm') ? null : $url;
    }
}
