<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChecklistOutcome;
use Database\Factories\ChecklistEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $machine_id
 * @property string $item_key
 * @property ChecklistOutcome $outcome
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['item_key', 'outcome', 'note'])]
class ChecklistEntry extends Model
{
    /** @use HasFactory<ChecklistEntryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => ChecklistOutcome::class,
        ];
    }
}
