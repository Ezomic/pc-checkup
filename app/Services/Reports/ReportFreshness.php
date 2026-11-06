<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Report;
use Illuminate\Support\Facades\Date;

final class ReportFreshness
{
    public const int STALE_AFTER_DAYS = 30;

    public function isStale(?Report $newest): bool
    {
        return $this->isOlderThan($newest, self::STALE_AFTER_DAYS);
    }

    public function isOlderThan(?Report $newest, int $days): bool
    {
        if ($newest?->created_at === null) {
            return false;
        }

        return $newest->created_at->lessThan(Date::now()->subDays($days));
    }
}
