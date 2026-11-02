<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RustDesk\InstallerScript;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

final class ToolController extends Controller
{
    public function index(InstallerScript $script): Response
    {
        $minutes = Config::integer('rustdesk.link_minutes');

        return Inertia::render('tools/Index', [
            'rustdesk' => [
                'configured' => $script->configured(),
                'temporary_url' => $script->configured()
                    ? URL::temporarySignedRoute('tools.rustdesk.download', now()->addMinutes($minutes))
                    : null,
                'link_minutes' => $minutes,
            ],
        ]);
    }
}
