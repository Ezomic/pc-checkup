<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\ApiTokens\CreateApiToken;
use App\Actions\ApiTokens\RevokeApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('settings/Tokens', [
            'tokens' => $user->tokens()
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->getKey(),
                    'name' => $token->name,
                    'created_at_diff' => $token->created_at?->diffForHumans(),
                    'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                    'expires_at_diff' => $token->expires_at?->diffForHumans(),
                    'expired' => $token->expires_at?->isPast() ?? false,
                ])
                ->values()
                ->all(),
            // Session flash, so the plaintext survives exactly one render and
            // is gone on refresh. It is never stored or sent again.
            'createdToken' => $request->session()->get('createdToken'),
        ]);
    }

    public function store(StoreApiTokenRequest $request, CreateApiToken $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $plainTextToken = $action->handle($user, $request->tokenName());

        return to_route('api-tokens.index')->with('createdToken', $plainTextToken);
    }

    public function destroy(Request $request, int $token, RevokeApiToken $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        abort_unless($action->handle($user, $token), 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return to_route('api-tokens.index');
    }
}
