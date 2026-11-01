<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Machines\ImportReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreReportRequest;
use App\Http\Resources\ImportedReportResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class ReportController extends Controller
{
    public function store(StoreReportRequest $request, ImportReport $action): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $report = $action->handle($user, $request->markdown());

        return (new ImportedReportResource($report))
            ->response()
            ->setStatusCode($report->wasRecentlyCreated ? 201 : 200);
    }
}
