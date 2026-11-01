<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\RequirePasswordInWorkflowMode;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Base mode confirms with the current password in the request. A user signed in
    // through ID has none, so there it takes a fresh trip through ID instead (WEB-31).
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware(RequirePasswordInWorkflowMode::class)
        ->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', fn () => Inertia::render('settings/Appearance'))->name('appearance.edit');

    // Behind RequirePassword like the security page: a personal access token is
    // a full-access credential, so minting one from a hijacked session should
    // still cost the password.
    Route::middleware(RequirePassword::class)->group(function () {
        Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
        Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
            ->whereNumber('token')
            ->name('api-tokens.destroy');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
