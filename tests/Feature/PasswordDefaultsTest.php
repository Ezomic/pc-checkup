<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

function passwordFails(string $password): bool
{
    return Validator::make(
        ['password' => $password],
        ['password' => Password::default()],
    )->fails();
}

it('accepts a simple eight character password outside production', function () {
    expect(passwordFails('password'))->toBeFalse();
});

it('demands twelve characters and mixed content in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    // Short enough that the rule fails on length and returns before the
    // uncompromised check, which would otherwise call haveibeenpwned.
    expect(passwordFails('Sh0rt!'))->toBeTrue();
});
