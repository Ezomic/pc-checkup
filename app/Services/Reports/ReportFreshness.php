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
        if ($newest?->created_at === null) {
            return false;
        }

        return $newest->created_at->lessThan(Date::now()->subDays(self::STALE_AFTER_DAYS));
    }
}
