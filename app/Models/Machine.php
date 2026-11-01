<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MachineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $identity_key
 * @property string|null $serial
 * @property string|null $hardware_uuid
 * @property string|null $name
 * @property string|null $os
 * @property string|null $device_type
 * @property string|null $model
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Machine extends Model
{
    /** @use HasFactory<MachineFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * @return HasMany<ChecklistEntry, $this>
     */
    public function checklistEntries(): HasMany
    {
        return $this->hasMany(ChecklistEntry::class);
    }

    /**
     * @return HasMany<MachineNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(MachineNote::class);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }
}
