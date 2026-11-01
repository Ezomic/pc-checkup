<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $machine_id
 * @property string $path
 * @property string|null $caption
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'caption', 'width', 'height', 'bytes'])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
