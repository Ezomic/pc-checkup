<?php

declare(strict_types=1);

use App\Http\Middleware\BlockLocalCredentials;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only APP_URL's own name, no subdomains, outside local and tests.
        // Anchored, because Symfony reads each entry as an unanchored pattern;
        // a closure, because config is not loaded yet when this runs.
        $middleware->trustHosts(
            at: fn (): array => ['^'.preg_quote((string) parse_url(config()->string('app.url'), PHP_URL_HOST)).'$'],
            subdomains: false,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->throttleApi();

        $middleware->web(append: [
            BlockLocalCredentials::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Render the branded page for the statuses a user can actually walk
        // into. Skipped in local so the Ignition debug page still wins there,
        // and skipped for JSON so an API caller keeps getting JSON.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (app()->environment('local') || $request->expectsJson()) {
                return $response;
            }

            if (! in_array($response->getStatusCode(), [403, 404, 419, 500], true)) {
                return $response;
            }

            return Inertia::render('Error', ['status' => $response->getStatusCode()])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
