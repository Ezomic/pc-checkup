<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RustDesk\InstallerScript;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RustDeskInstallerController extends Controller
{
    public function __invoke(Request $request, InstallerScript $script): Response
    {
        abort_unless($request->hasValidSignature() || $request->user()?->hasVerifiedEmail() === true, 404);

        return response($script->render(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rustdesk-install.ps1"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
