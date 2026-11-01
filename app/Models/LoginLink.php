<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LoginLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 */
#[Fillable(['token_hash', 'expires_at'])]
class LoginLink extends Model
{
    /** @use HasFactory<LoginLinkFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<LoginLink>  $query
     * @return Builder<LoginLink>
     */
    public function scopeForToken(Builder $query, string $token): Builder
    {
        return $query->where('token_hash', self::hashToken($token));
    }

    /**
     * @param  Builder<LoginLink>  $query
     * @return Builder<LoginLink>
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
