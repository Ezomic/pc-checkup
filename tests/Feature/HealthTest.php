<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\getJson;

it('reports healthy when the database is reachable', function () {
    getJson('/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'database' => 'ok',
        ])
        ->assertJsonStructure(['status', 'app', 'version', 'database']);
});

it('is publicly accessible without authentication', function () {
    getJson('/health')->assertOk();
});

it('reports degraded when the database is unreachable', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('getPdo')->andThrow(new RuntimeException('down'));
    DB::shouldReceive('connection')->andReturn($connection);

    getJson('/health')
        ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
        ->assertJson([
            'status' => 'degraded',
            'database' => 'unreachable',
        ]);
});
