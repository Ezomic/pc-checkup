<?php

declare(strict_types=1);

use App\Enums\CheckupScript;

it('lets a guest download each checkup script', function (CheckupScript $script) {
    $response = $this->get(route('scripts.download', $script))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain($script->downloadName())
        ->and($response->headers->get('content-type'))->toStartWith('text/plain')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->getContent())->toBe($script->contents());
})->with([CheckupScript::Windows, CheckupScript::Mac]);

it('makes browsers and proxies revalidate each script instead of reusing a saved copy', function (CheckupScript $script) {
    $response = $this->get(route('scripts.download', $script))->assertOk();

    $directives = array_map(trim(...), explode(',', (string) $response->headers->get('cache-control')));

    expect($directives)->toContain('no-cache', 'must-revalidate')
        ->not->toContain('public')
        ->and($response->headers->get('last-modified'))->not->toBeNull();
})->with([CheckupScript::Windows, CheckupScript::Mac]);

it('does not serve anything that is not a known script', function (string $name) {
    $this->get('/scripts/'.$name)->assertNotFound();
})->with(['linux', '..%2F.env', 'windows-checkup.ps1', 'WINDOWS']);
