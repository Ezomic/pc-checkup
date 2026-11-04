<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class TrendMath
{
    public const DAYS_PER_MONTH = 30.44;

    /**
     * Least squares line through the points, with how well it explains them (r squared, 0 to 1).
     *
     * @param  list<array{x: float, y: float}>  $points
     * @return array{slope: float, intercept: float, r2: float}|null
     */
    public static function fit(array $points): ?array
    {
        $count = count($points);

        if ($count < 2) {
            return null;
        }

        $meanX = array_sum(array_column($points, 'x')) / $count;
        $meanY = array_sum(array_column($points, 'y')) / $count;
        $sxx = $sxy = $syy = 0.0;

        foreach ($points as $point) {
            $sxx += ($point['x'] - $meanX) ** 2;
            $sxy += ($point['x'] - $meanX) * ($point['y'] - $meanY);
            $syy += ($point['y'] - $meanY) ** 2;
        }

        if ($sxx <= 0.0) {
            return null;
        }

        $slope = $sxy / $sxx;

        return [
            'slope' => $slope,
            'intercept' => $meanY - $slope * $meanX,
            'r2' => $syy <= 0.0 ? 1.0 : min(1.0, ($sxy ** 2) / ($sxx * $syy)),
        ];
    }

    public static function monthsUntilFull(float $free, float $slopePerDay): ?float
    {
        if ($slopePerDay >= 0.0) {
            return null;
        }

        return max($free, 0.0) / -$slopePerDay / self::DAYS_PER_MONTH;
    }
}
