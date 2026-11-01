<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ScanCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $report_id
 * @property Carbon|null $created_at
 */
#[Fillable(['code_hash', 'expires_at'])]
class ScanCode extends Model
{
    /** @use HasFactory<ScanCodeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Keyed with the app key: a plain hash of six digits falls to a table of a million rows.
     */
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, config()->string('app.key'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @param  Builder<ScanCode>  $query
     * @return Builder<ScanCode>
     */
    public function scopeForCode(Builder $query, string $code): Builder
    {
        return $query->where('code_hash', self::hashCode($code));
    }

    /**
     * @param  Builder<ScanCode>  $query
     * @return Builder<ScanCode>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
