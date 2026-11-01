<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

final class DeletePhoto
{
    public function handle(Photo $photo): void
    {
        Storage::disk('photos')->delete($photo->path);

        $photo->delete();
    }
}
