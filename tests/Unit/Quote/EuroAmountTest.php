<?php

declare(strict_types=1);

use App\Services\Quote\EuroAmount;

it('turns euro text into whole cents without floats', function (string $input, int $cents) {
    expect(EuroAmount::toCents($input))->toBe($cents);
})->with([
    ['12,50', 1250], ['12.50', 1250], ['12', 1200], ['12,5', 1250], ['0,01', 1], ['0', 0], ['0,00', 0],
    ['999999,99', 99_999_999], ['  45,00 ', 4500], ['19,99', 1999], ['0.29', 29], ['1.1', 110],
]);

it('refuses anything that is not a plain amount', function (string $input) {
    expect(EuroAmount::toCents($input))->toBeNull();
})->with([
    '', ' ', '-5', '-0,01', '+5', '1.250,00', '1,250.00', '12,345', '1000000', '1e3', 'abc', '12,', ',50', '12,5,0', '€12', '12 euro',
    "12,50\n7", '１２', '0x10', 'NaN', 'INF',
]);
