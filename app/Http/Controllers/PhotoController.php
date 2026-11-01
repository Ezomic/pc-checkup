<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Machines\DeletePhoto;
use App\Actions\Machines\StorePhoto;
use App\Http\Requests\Machines\StorePhotoRequest;
use App\Models\Machine;
use App\Models\Photo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PhotoController extends Controller
{
    public function store(StorePhotoRequest $request, Machine $machine, StorePhoto $action): RedirectResponse
    {
        $this->authorize('update', $machine);

        $photo = $request->file('photo');
        abort_unless($photo instanceof UploadedFile, 422);

        $caption = $request->validated('caption');

        $action->handle($machine, $photo, is_string($caption) ? $caption : null);

        return to_route('machines.show', $machine);
    }

    public function show(Machine $machine, Photo $photo): StreamedResponse
    {
        $this->authorize('view', $machine);

        $disk = Storage::disk('photos');
        abort_unless($disk->exists($photo->path), 404);

        return $disk->response($photo->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Machine $machine, Photo $photo, DeletePhoto $action): RedirectResponse
    {
        $this->authorize('update', $machine);

        $action->handle($photo);

        return to_route('machines.show', $machine);
    }
}
