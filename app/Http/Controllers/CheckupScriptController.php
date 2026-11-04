<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CheckupScript;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CheckupScriptController extends Controller
{
    public function __invoke(CheckupScript $script): BinaryFileResponse
    {
        $response = response()->download($script->path(), $script->filename(), [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');

        return $response;
    }
}
