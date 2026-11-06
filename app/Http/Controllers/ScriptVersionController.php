<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CheckupScript;
use Symfony\Component\HttpFoundation\Response;

final class ScriptVersionController extends Controller
{
    public function __invoke(CheckupScript $script): Response
    {
        return response($script->version(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
