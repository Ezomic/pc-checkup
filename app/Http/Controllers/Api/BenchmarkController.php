<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Bench\CountUploadedBytes;
use App\Actions\Bench\StreamRandomBytes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BenchDownloadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BenchmarkController extends Controller
{
    public function download(BenchDownloadRequest $request, StreamRandomBytes $action): StreamedResponse
    {
        return $action->handle($request->bytes());
    }

    public function upload(Request $request, CountUploadedBytes $action): JsonResponse
    {
        return response()->json(['bytes' => $action->handle($request)])
            ->withHeaders(['Cache-Control' => 'no-store']);
    }
}
