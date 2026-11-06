<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Share\ShareLocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetShareLocale
{
    public function __construct(private ShareLocaleResolver $locale) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->locale->apply($request);

        /** @var Response */
        return $next($request);
    }
}
