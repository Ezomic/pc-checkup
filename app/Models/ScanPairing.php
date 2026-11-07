<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PairingStatus;
use Database\Factories\ScanPairingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $pairing_hash
 * @property string $secret_hash
 * @property string $check_code
 * @property Carbon $expires_at
 * @property int|null $user_id
 * @property int|null $client_id
 * @property int|null $machine_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property string|null $token_hash
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 */
#[Fillable(['pairing_hash', 'secret_hash', 'check_code', 'expires_at'])]
class ScanPairing extends Model
{
    /** @use HasFactory<ScanPairingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const ID_LENGTH = 24;

    public const SECRET_LENGTH = 40;

    public const TOKEN_LENGTH = 40;

    /**
     * Every value is at least 128 random bits, so a plain hash is enough to keep the stored
     * rows from being usable on their own.
     */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Ids, secrets and tokens are all letters and digits of one fixed length.
     */
    public static function wellFormed(string $value, int $length): bool
    {
        return preg_match('/\A[A-Za-z0-9]{'.$length.'}\z/', $value) === 1;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function status(): PairingStatus
    {
        return match (true) {
            $this->used_at !== null => PairingStatus::Used,
            $this->rejected_at !== null => PairingStatus::Rejected,
            $this->approved_at !== null => $this->token_expires_at?->isFuture() === true ? PairingStatus::Approved : PairingStatus::Expired,
            $this->expires_at->isFuture() => PairingStatus::Pending,
            default => PairingStatus::Expired,
        };
    }

    /**
     * @param  Builder<ScanPairing>  $query
     * @return Builder<ScanPairing>
     */
    public function scopeForPairing(Builder $query, string $id): Builder
    {
        return $query->where('pairing_hash', self::hash($id));
    }

    /**
     * @param  Builder<ScanPairing>  $query
     * @return Builder<ScanPairing>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('approved_at')->whereNull('rejected_at')->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<ScanPairing>  $query
     * @return Builder<ScanPairing>
     */
    public function scopeRedeemable(Builder $query, string $token): Builder
    {
        return $query->where('token_hash', self::hash($token))
            ->whereNotNull('approved_at')
            ->whereNull('used_at')
            ->where('token_expires_at', '>', now());
    }

    /**
     * Used, or past both the time to approve and the time to upload.
     *
     * @param  Builder<ScanPairing>  $query
     * @return Builder<ScanPairing>
     */
    public function scopeDead(Builder $query): Builder
    {
        return $query->whereNotNull('used_at')->orWhere(fn (Builder $query): Builder => $query
            ->where('expires_at', '<=', now())
            ->where(fn (Builder $query): Builder => $query->whereNull('token_expires_at')->orWhere('token_expires_at', '<=', now())));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
