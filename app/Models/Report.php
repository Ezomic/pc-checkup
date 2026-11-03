<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportKind;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $machine_id
 * @property string $markdown
 * @property string $checksum
 * @property int|null $format_version
 * @property ReportKind $kind
 * @property int|null $before_report_id
 * @property list<string> $flags
 * @property list<array{id: string, status: string, command: string, undo: string|null}>|null $fixes
 * @property array<string, int|float>|null $benchmark
 * @property bool|null $ran_as_admin
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['markdown', 'checksum', 'format_version', 'kind', 'before_report_id', 'flags', 'fixes', 'benchmark', 'ran_as_admin'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function beforeReport(): BelongsTo
    {
        return $this->belongsTo(self::class, 'before_report_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReportKind::class,
            'flags' => 'array',
            'fixes' => 'array',
            'benchmark' => 'array',
            'ran_as_admin' => 'boolean',
        ];
    }
}
