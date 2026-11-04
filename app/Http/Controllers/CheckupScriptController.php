<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CheckupScript;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class CheckupScriptController extends Controller
{
    public function __invoke(Request $request, CheckupScript $script): Response
    {
        $response = response($script->contents(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $script->downloadName()),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setEtag($script->version());
        $response->setLastModified(Date::createFromTimestamp((int) filemtime($script->path())));
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        $response->isNotModified($request);

        return $response;
    }
}
