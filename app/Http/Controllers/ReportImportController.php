<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\ImportReport;
use App\Http\Requests\Reports\StoreReportImportRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

final class ReportImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('imports/Create');
    }

    public function store(StoreReportImportRequest $request, ImportReport $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $file = $request->file('file');

        $action->handle($user, $file instanceof UploadedFile ? $file->getContent() : $request->string('text')->toString());

        return to_route('machines.index');
    }
}
