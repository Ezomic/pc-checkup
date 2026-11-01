<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * snag-client registers @snag but prints nothing on its own, so until the layout carries
 * the directive a clone with snag switched on still has no way to file a report. WEB-34.
 */
function configureSnag(): void
{
    config([
        'snag-client.enabled' => true,
        'snag-client.url' => 'https://snag.example.test',
        'snag-client.key' => 'web-template',
        'snag-client.secret' => 'ingest-secret',
        'snag-client.pseudonym_salt' => 'pseudonym-salt',
    ]);
}

it('puts the snag widget on the page for a signed-in user', function () {
    configureSnag();
    actingAs(User::factory()->create());

    get(route('dashboard'))
        ->assertOk()
        ->assertSee('<script defer src="https://snag.example.test/widget.js" data-snag-key="web-template"', escape: false);
});

it('leaves the widget out while snag is switched off', function () {
    actingAs(User::factory()->create());

    get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-snag-key', escape: false);
});

it('leaves the widget out for a signed-out visitor', function () {
    configureSnag();

    get(route('home'))
        ->assertOk()
        ->assertDontSee('data-snag-key', escape: false);
});
