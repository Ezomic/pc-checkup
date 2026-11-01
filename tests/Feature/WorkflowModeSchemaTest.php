<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow mode delegates identity to Thijssensoftware ID, which is passwordless
 * and sends no password at all. A NOT NULL password column therefore made the
 * very first SSO login fail on the insert, dumping the user back on the callback
 * URL with an error that reads as a broken app rather than a schema mismatch.
 */
it('keeps users.password nullable so a passwordless IdP can provision users', function () {
    $password = collect(Schema::getColumns('users'))->firstWhere('name', 'password');

    expect($password)->not->toBeNull()
        ->and($password['nullable'])->toBeTrue();
});

it('provisions a user with no password, as the identity provider does', function () {
    $user = User::create(['name' => 'SSO User', 'email' => 'sso@example.test']);

    expect($user->exists)->toBeTrue()
        ->and($user->password)->toBeNull();
});

it('still hashes a password when one is supplied in base mode', function () {
    $user = User::create([
        'name' => 'Local',
        'email' => 'local@example.test',
        'password' => 'secret-value',
    ]);

    expect($user->password)->not->toBeNull()
        ->and($user->password)->not->toBe('secret-value');
});
