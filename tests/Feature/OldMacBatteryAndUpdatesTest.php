<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

const OLD_MAC_FLAGS = [
    'Only about 12% of disk is free.',
    'macOS 10.14.6 is old and likely out of security support.',
    'No macOS update has been installed for 1744 days (newest: 2022-01-24).',
];

function oldMacMarkdown(): string
{
    return macReportFixture()
        ."\n## Battery raw (ioreg)\n\n```\n      \"AppleRawMaxCapacity\" = 6011\n      \"MaxCapacity\" = 6011\n      \"CycleCount\" = 391\n      \"DesignCapacity\" = 7150\n```\n"
        ."\n## Software updates\n\n```\nNo new software available.\nSoftware Update Tool\n\nFinding available software\n```\n";
}

function oldMac(?Client $client = null): Machine
{
    $owner = $client === null ? User::factory()->create() : $client->user;
    $machine = Machine::factory()->for($owner)->create(['client_id' => $client?->id, 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => oldMacMarkdown(), 'flags' => OLD_MAC_FLAGS]);

    return $machine;
}

/**
 * @return array<string, mixed>
 */
function oldMacProps(string $path, ?User $user = null): array
{
    $props = [];
    $request = $user === null ? test() : actingAs($user);

    $request->get($path)->assertOk()->assertInertia(function ($page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

it('gives the owner the ioreg capacity and the true update advice', function () {
    $machine = oldMac();
    $props = oldMacProps("/machines/{$machine->id}", $machine->user);
    $cards = array_column($props['detailCards'], 'rows', 'id');

    expect(array_column($cards['battery'], 'value', 'label')['Maximum capacity'])->toBe('84% of when it was new')
        ->and(array_column($cards['updates'], 'value', 'label')['Last update installed'])
        ->toBe('24 January 2022, 1744 days before the scan. No more updates come out for macOS 10.14 Mojave. The newest macOS this Mac can run is macOS 12 Monterey.')
        ->and(array_column($props['report']['advice'], 'advice', 'headline')['No updates installed for 1744 days'])
        ->toBe('No more updates come out for macOS 10.14 Mojave. The newest macOS this Mac can run is macOS 12 Monterey.');
});

it('gives a client the same advice in plain words in their language and no cards', function (string $query, string $text) {
    $client = Client::factory()->withShareLink()->create();
    $machine = oldMac($client);
    $props = oldMacProps("/c/{$client->share_token}/machines/{$machine->id}{$query}");
    $payload = (string) json_encode($props);

    expect(array_column($props['report']['advice'], 'advice'))->toContain($text);

    foreach (['detailCards', 'Maximum capacity', 'Last update installed', 'AppleRawMaxCapacity', 'Battery raw', 'Software Update Tool', 'TESTSERIAL001'] as $hidden) {
        expect($payload)->not->toContain($hidden);
    }
})->with([
    'Dutch by default' => ['', 'Voor macOS 10.14 Mojave komen geen updates meer uit. De nieuwste macOS die op deze Mac kan draaien, is macOS 12 Monterey.'],
    'English when asked' => ['?lang=en', 'No more updates come out for macOS 10.14 Mojave. The newest macOS this Mac can run is macOS 12 Monterey.'],
]);

it('does not show the machine to another account or through another client link', function () {
    $machine = oldMac(Client::factory()->withShareLink()->create());
    $other = Client::factory()->withShareLink()->create();

    actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
    $this->get("/c/{$other->share_token}/machines/{$machine->id}")->assertNotFound();
});
