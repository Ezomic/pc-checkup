<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateBenchmarkClient;
use App\Models\Machine;
use App\Models\User;
use App\Services\Scans\PairingUrlSanitiser;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Thijssensoftware\FlareClient\Payload\Sanitiser;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Sanitiser::class, PairingUrlSanitiser::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureApiBindings();
    }

    /**
     * An API caller may not learn that a machine exists, so someone else's machine is as
     * missing as one that never was. The UI answers 403 for it through the same policy.
     * Soft deleted machines are already absent from the query.
     */
    protected function configureApiBindings(): void
    {
        Route::bind('ownedMachine', function (string $id): Machine {
            $machine = Machine::query()->find($id);

            abort_unless($machine !== null && Gate::allows('view', $machine), 404);

            return $machine;
        });
    }

    /**
     * Every API route, through throttleApi() in bootstrap/app.php. Per token, so one
     * runaway script cannot lock out the user's other tokens. auth:sanctum falls back to
     * the web guard, so a browser session arrives without a token and counts per user, and
     * a route an app adds without auth counts per address rather than in one shared bucket.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $token = $user?->currentAccessToken();

            return Limit::perMinute(60)->by(match (true) {
                $token instanceof PersonalAccessToken => "token:{$token->id}",
                $user instanceof User => "user:{$user->id}",
                default => 'ip:'.$request->ip(),
            });
        });

        RateLimiter::for('share', fn (Request $request): Limit => Limit::perMinute(60)->by('share:'.$request->ip()));

        // A script asks for one pairing per run; a shop full of PCs behind one address needs a few.
        RateLimiter::for('pairing', fn (Request $request): array => [
            Limit::perMinute(5)->by('pairing:minute:'.$request->ip()),
            Limit::perHour(60)->by('pairing:hour:'.$request->ip()),
        ]);

        // One poll every 3 seconds is 20 a minute per waiting script.
        RateLimiter::for('pairing-poll', fn (Request $request): Limit => Limit::perMinute(120)->by('pairing-poll:'.$request->ip()));

        RateLimiter::for('bench', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $scanCodeId = $request->attributes->get(AuthenticateBenchmarkClient::SCAN_CODE_ATTRIBUTE);

            return Limit::perMinute(6)->by(match (true) {
                $token instanceof PersonalAccessToken => "bench:token:{$token->id}",
                is_int($scanCodeId) => "bench:code:{$scanCodeId}",
                default => 'bench:ip:'.$request->ip(),
            });
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
