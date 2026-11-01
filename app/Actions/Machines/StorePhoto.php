<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Photo;
use App\Services\Photos\PhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StorePhoto
{
    public const MAX_PER_MACHINE = 30;

    public function __construct(private readonly PhotoProcessor $processor) {}

    public function handle(Machine $machine, UploadedFile $file, ?string $caption): Photo
    {
        $processed = $this->processor->process($file->getContent());

        // The file name is ours: nothing the uploader sent reaches the disk or the database.
        $path = $machine->id.'/'.Str::uuid()->toString().'.jpg';

        // Inside the transaction so a failed write rolls the row back with it, and the
        // lock makes the count and the insert one step for concurrent uploads.
        return DB::transaction(function () use ($machine, $caption, $processed, $path): Photo {
            Machine::query()->whereKey($machine->id)->lockForUpdate()->first();

            if ($machine->photos()->count() >= self::MAX_PER_MACHINE) {
                throw ValidationException::withMessages(['photo' => 'A machine can have at most '.self::MAX_PER_MACHINE.' photos.']);
            }

            $photo = $machine->photos()->create([
                'path' => $path,
                'caption' => $caption,
                'width' => $processed['width'],
                'height' => $processed['height'],
                'bytes' => strlen($processed['bytes']),
            ]);

            Storage::disk('photos')->put($path, $processed['bytes']);

            return $photo;
        });
    }
}
