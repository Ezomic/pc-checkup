<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class MachineController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('machines/Index', [
            'machines' => $user->machines()
                ->withCount('reports')
                ->withMax('reports as last_report_at', 'created_at')
                ->latest()
                ->latest('id')
                ->paginate(Config::integer('pagination.per_page'))
                ->withQueryString(),
        ]);
    }
}
