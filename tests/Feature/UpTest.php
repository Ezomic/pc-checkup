<?php

declare(strict_types=1);

use function Pest\Laravel\get;

/**
 * app-deploy switches back to the previous release only when /up stops answering 200, and
 * Laravel's /up answers 200 without touching the database. WEB-34.
 */
it('answers up while the database is reachable', function () {
    get('/up')->assertOk();
});

it('answers down when the database is unreachable', function () {
    config([
        'app.debug' => false,
        'database.connections.unreachable' => [
            'driver' => 'sqlite',
            'database' => storage_path('framework/testing/missing.sqlite'),
            'prefix' => '',
        ],
        'database.default' => 'unreachable',
    ]);

    $response = get('/up');

    // RefreshDatabase rolls back whatever connection is the default when the test ends.
    config(['database.default' => 'sqlite']);

    $response->assertInternalServerError();
});
