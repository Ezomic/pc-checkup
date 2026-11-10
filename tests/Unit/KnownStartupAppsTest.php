<?php

declare(strict_types=1);

use App\Services\Reports\KnownStartupApps;

/**
 * @return list<array{name: string, description: string, patterns: list<string>}>
 */
function ksaCatalog(): array
{
    /** @var list<array{name: string, description: string, patterns: list<string>}> */
    return (new ReflectionClassConstant(KnownStartupApps::class, 'APPS'))->getValue();
}

it('finds an app by a lower-cased name and gives its plain name and description', function () {
    expect((new KnownStartupApps)->find('lghub'))->toBe(['name' => 'Logitech G HUB', 'description' => 'Settings app for Logitech gaming mice, keyboards and headsets'])
        ->and((new KnownStartupApps)->find('mystery tool'))->toBeNull()
        ->and((new KnownStartupApps)->find(''))->toBeNull();
});

it('has a name, a one-line description and patterns that compile for every app', function () {
    foreach (ksaCatalog() as $app) {
        expect($app['name'])->not->toBe('')
            ->and($app['description'])->not->toBe('')->not->toContain("\n")->not->toContain("\u{2014}")
            ->and($app['patterns'])->not->toBe([]);

        foreach ($app['patterns'] as $pattern) {
            expect(@preg_match('/'.$pattern.'/', ''))->not->toBeFalse();
        }
    }
});

it('recognises every app by its own name, so no entry is hidden behind a broader one', function () {
    foreach (ksaCatalog() as $app) {
        expect((new KnownStartupApps)->find(mb_strtolower($app['name']))['name'] ?? null)->toBe($app['name']);
    }
});

it('lists each app once', function () {
    $names = array_map(fn (array $app): string => mb_strtolower($app['name']), ksaCatalog());

    expect($names)->toBe(array_values(array_unique($names)));
});
