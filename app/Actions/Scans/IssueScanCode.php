<?php

declare(strict_types=1);

namespace App\Actions\Scans;

use App\Models\ScanCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class IssueScanCode
{
    public const LIFETIME_MINUTES = 15;

    /**
     * Returns the plain code. It is not stored anywhere, so this is the only time it can be shown.
     */
    public function handle(User $user): string
    {
        // A code is unique among all rows, so one that is spent or expired still blocks its digits.
        do {
            $code = sprintf('%06d', random_int(0, 999999));
        } while (ScanCode::query()->forCode($code)->exists());

        DB::transaction(function () use ($user, $code): void {
            ScanCode::query()->where('expires_at', '<', now()->subDay())->delete();

            $user->scanCode()->delete();
            $user->scanCode()->create([
                'code_hash' => ScanCode::hashCode($code),
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            ]);
        });

        return $code;
    }
}
