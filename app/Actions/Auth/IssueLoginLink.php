<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\LoginLinkMail;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class IssueLoginLink
{
    public const LIFETIME_MINUTES = 15;

    public function handle(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $token = Str::random(64);

        // Every earlier link of this user goes: the unused ones are superseded, the rest are spent or expired.
        DB::transaction(function () use ($user, $token): void {
            $user->loginLinks()->delete();
            $user->loginLinks()->create([
                'token_hash' => LoginLink::hashToken($token),
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            ]);
        });

        // Built from APP_URL, not the request, so a forged Host header cannot aim the link elsewhere.
        $url = rtrim(config()->string('app.url'), '/').route('login.link.show', ['token' => $token], absolute: false);

        Mail::to($user->email)->send(new LoginLinkMail($url, self::LIFETIME_MINUTES));
    }
}
