<?php

declare(strict_types=1);

use App\Enums\CheckupScript;

it('lets a guest download each checkup script', function (CheckupScript $script) {
    $response = $this->get(route('scripts.download', $script))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain($script->filename())
        ->and($response->headers->get('content-type'))->toStartWith('text/plain')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe(file_get_contents($script->path()));
})->with([CheckupScript::Windows, CheckupScript::Mac]);

it('does not serve anything that is not a known script', function (string $name) {
    $this->get('/scripts/'.$name)->assertNotFound();
})->with(['linux', '..%2F.env', 'windows-checkup.ps1', 'WINDOWS']);
