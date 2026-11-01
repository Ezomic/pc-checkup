<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ConsumeLoginLink;
use App\Enums\LoginLinkOutcome;
use App\Models\LoginLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

final class LoginLinkController extends Controller
{
    /**
     * Mail scanners and link previewers fetch GET links, so this only asks for confirmation.
     * Nothing here consumes the token or signs anyone in.
     */
    public function show(Request $request, string $token): Response
    {
        $link = LoginLink::query()->forToken($token)->usable()->with('user')->first();

        // The masked address lets someone who was sent a stranger's link see it is not their account.
        $page = $link === null
            ? Inertia::render('auth/LoginLinkInvalid')
            : Inertia::render('auth/ConfirmLoginLink', [
                'token' => $token,
                'email' => Str::mask($link->user->email, '*', 1, strpos($link->user->email, '@') - 1),
            ]);

        $response = $page->toResponse($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function consume(Request $request, string $token, ConsumeLoginLink $action): RedirectResponse
    {
        return match ($action->handle($token, $request)) {
            LoginLinkOutcome::Invalid => to_route('login.link.invalid'),
            LoginLinkOutcome::TwoFactorChallenge => to_route('two-factor.login'),
            LoginLinkOutcome::SignedIn => redirect()->intended(route('dashboard')),
        };
    }

    public function invalid(): InertiaResponse
    {
        return Inertia::render('auth/LoginLinkInvalid');
    }
}
