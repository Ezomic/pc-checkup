<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseReachable = $this->databaseReachable();

        return response()->json([
            'status' => $databaseReachable ? 'ok' : 'degraded',
            'app' => Config::string('app.name'),
            'version' => app()->version(),
            'database' => $databaseReachable ? 'ok' : 'unreachable',
        ], $databaseReachable ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
