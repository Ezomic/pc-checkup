<?php

declare(strict_types=1);

use App\Services\Reports\TrendMath;

it('fits a perfect falling line', function () {
    $fit = TrendMath::fit([['x' => 0.0, 'y' => 100.0], ['x' => 10.0, 'y' => 90.0], ['x' => 20.0, 'y' => 80.0]]);

    expect($fit)->not->toBeNull()
        ->and($fit['slope'])->toEqualWithDelta(-1.0, 1e-9)
        ->and($fit['intercept'])->toEqualWithDelta(100.0, 1e-9)
        ->and($fit['r2'])->toEqualWithDelta(1.0, 1e-9);
});

it('calls a flat series a perfect fit with no slope', function () {
    $fit = TrendMath::fit([['x' => 0.0, 'y' => 7.0], ['x' => 5.0, 'y' => 7.0], ['x' => 9.0, 'y' => 7.0]]);

    expect($fit)->toBe(['slope' => 0.0, 'intercept' => 7.0, 'r2' => 1.0]);
});

it('gives a poor fit a low r squared', function () {
    $fit = TrendMath::fit([['x' => 0.0, 'y' => 100.0], ['x' => 1.0, 'y' => 20.0], ['x' => 2.0, 'y' => 90.0], ['x' => 3.0, 'y' => 30.0], ['x' => 4.0, 'y' => 80.0]]);

    expect($fit['r2'])->toBeLessThan(0.3);
});

it('cannot fit fewer than two points or points in the same instant', function (array $points) {
    expect(TrendMath::fit($points))->toBeNull();
})->with([
    'none' => [[]],
    'one' => [[['x' => 1.0, 'y' => 1.0]]],
    'same x' => [[['x' => 1.0, 'y' => 1.0], ['x' => 1.0, 'y' => 5.0]]],
]);

it('counts the months until the free space is gone', function () {
    expect(TrendMath::monthsUntilFull(30.44, -1.0))->toEqualWithDelta(1.0, 1e-9)
        ->and(TrendMath::monthsUntilFull(0.0, -1.0))->toBe(0.0);
});

it('has no months to count when the free space is not shrinking', function (float $slope) {
    expect(TrendMath::monthsUntilFull(10.0, $slope))->toBeNull();
})->with([0.0, 0.5]);
