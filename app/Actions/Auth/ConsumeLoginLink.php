<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginLinkOutcome;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

final class ConsumeLoginLink
{
    public function handle(string $token, Request $request): LoginLinkOutcome
    {
        $user = $this->claim($token);

        if ($user === null) {
            return LoginLinkOutcome::Invalid;
        }

        if ($this->mustPassTwoFactor($user)) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);
            $request->session()->regenerate();

            return LoginLinkOutcome::TwoFactorChallenge;
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return LoginLinkOutcome::SignedIn;
    }

    /**
     * The conditional update is what makes the link single use: of two simultaneous requests
     * only one finds the row unused and unexpired, so only one gets a count of 1.
     */
    private function claim(string $token): ?User
    {
        return DB::transaction(function () use ($token): ?User {
            $claimed = LoginLink::query()->forToken($token)->usable()->update(['used_at' => now()]);

            return $claimed === 1 ? LoginLink::query()->forToken($token)->first()?->user : null;
        });
    }

    /**
     * Fortify's own rule for when a password login becomes a two-factor challenge.
     */
    private function mustPassTwoFactor(User $user): bool
    {
        if (! Features::enabled(Features::twoFactorAuthentication()) || $user->two_factor_secret === null) {
            return false;
        }

        return ! Fortify::confirmsTwoFactorAuthentication() || $user->two_factor_confirmed_at !== null;
    }
}
