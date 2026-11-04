<?php

declare(strict_types=1);

use App\Services\Reports\LocaleNumber;

describe('float', function () {
    it('reads a decimal comma and a decimal point the same way', function (string $value, float $expected) {
        expect(LocaleNumber::float($value))->toBe($expected);
    })->with([
        'plain integer' => ['69', 69.0],
        'comma' => ['1,41', 1.41],
        'point' => ['1.41', 1.41],
        'zero point five with comma' => ['0,5', 0.5],
        'trailing zeros' => ['2048,00', 2048.0],
        'three digits after a comma stay decimal' => ['1,414', 1.414],
        'three digits after a point stay decimal' => ['1.414', 1.414],
        'negative comma' => ['-1,5', -1.5],
        'negative point' => ['-1.5', -1.5],
        'padded' => ['  3,5  ', 3.5],
        'grouped with commas' => ['1,234,567', 1234567.0],
        'grouped with points' => ['1.234.567', 1234567.0],
        'point grouping with decimal comma' => ['1.234,56', 1234.56],
        'comma grouping with decimal point' => ['1,234.56', 1234.56],
        'two groups then decimal' => ['1.234.567,8', 1234567.8],
    ]);

    it('refuses what is not one number', function (?string $value) {
        expect(LocaleNumber::float($value))->toBeNull();
    })->with([
        'null' => [null],
        'empty' => [''],
        'blank' => ['   '],
        'list' => ['1,2,3'],
        'doubled separator' => ['1,,5'],
        'no leading digit' => [',5'],
        'no trailing digit' => ['5,'],
        'lone separator' => [','],
        'words' => ['abc'],
        'unit attached' => ['5GB'],
        'two spaces' => ['1 2'],
        'mixed order' => ['1,2.3,4'],
        'bad grouping' => ['12,34,567'],
        'long first group' => ['1234,567,890'],
        'zero first group' => ['0,234,567'],
        'grouping after a decimal' => ['1,234.567,8'],
        'exponent' => ['1e5'],
        'plus sign' => ['+5'],
    ]);
});

describe('count', function () {
    it('reads one comma or point followed by three digits as thousands', function (?string $value, ?int $expected) {
        expect(LocaleNumber::count($value))->toBe($expected);
    })->with([
        'plain' => ['1234', 1234],
        'comma thousands' => ['1,234', 1234],
        'point thousands' => ['1.234', 1234],
        'two groups' => ['12.345.678', 12345678],
        'no leading zero group' => ['0,500', null],
        'four digit lead' => ['1234,567', null],
        'two digits after' => ['1,23', null],
        'a real decimal' => ['2,5', null],
        'negative' => ['-5', null],
        'empty' => ['', null],
        'null' => [null, null],
    ]);
});

describe('PATTERN', function () {
    it('matches the numbers the parsers capture', function () {
        preg_match('/^'.LocaleNumber::PATTERN.'$/', '69,2', $comma);
        preg_match('/^'.LocaleNumber::PATTERN.'$/', '69.2', $point);

        expect($comma)->toBe(['69,2'])
            ->and($point)->toBe(['69.2'])
            ->and(preg_match('/^'.LocaleNumber::PATTERN.'$/', '69,'))->toBe(0);
    });
});
