<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\PriceItem;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

function repairMarkdown(string $serial = 'C02TESTSERIAL', string $os = 'macOS', string $model = 'MacBook Pro (MacBookPro18,1)'): string
{
    return "# Mac checkup: Test\n\n- Format: 1\n- OS: {$os}\n- Serial: {$serial}\n- Model: {$model}\n- Memory: 16 GB\n"
        ."- macOS: 14.5 (23F79)\n- Battery: 1200 cycles, condition Normal, max capacity 55%\n- Free disk: 50%\n";
}

/**
 * @return array<string, mixed>
 */
function showProps(User $user, Machine $machine): array
{
    $props = [];

    actingAs($user)->get("/machines/{$machine->id}")->assertOk()
        ->assertInertia(function ($page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props;
}

it('gives the owner a warranty link built from the stored serial', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['markdown' => repairMarkdown('C02TESTSERIAL')]);

    expect(showProps($user, $machine)['warranty'])->toBe([
        'maker' => 'Apple',
        'url' => 'https://checkcoverage.apple.com/',
        'serial' => 'C02TESTSERIAL',
        'serial_in_url' => false,
    ]);
});

it('hides the warranty for a placeholder serial, an unknown maker or no report', function (string $serial, string $model) {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['markdown' => repairMarkdown($serial, 'Windows', $model)]);

    expect(showProps($user, $machine)['warranty'])->toBeNull();
})->with([
    'placeholder' => ['To Be Filled By O.E.M.', 'Dell Latitude 5490'],
    'unknown maker' => ['ABC12345', 'Gigabyte Z790'],
]);

it('has no warranty and no estimate before the first report', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();

    $props = showProps($user, $machine);

    expect($props['warranty'])->toBeNull()
        ->and($props['estimate'])->toBeNull()
        ->and($props['vatPercent'])->toBe(21);
});

it('hands over the verdict reasons and only my price items in cents', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['markdown' => repairMarkdown()]);
    PriceItem::factory()->for($user)->create(['name' => 'New battery', 'price_cents' => 8900, 'unit' => 'each']);
    PriceItem::factory()->create(['name' => 'Their secret item', 'price_cents' => 1]);

    $props = showProps($user, $machine);

    expect($props['estimate']['verdict'])->toBe('Repair')
        ->and(implode(' ', $props['estimate']['reasons']))->toContain('battery')
        ->and($props['priceItems'])->toHaveCount(1)
        ->and($props['priceItems'][0]['name'])->toBe('New battery')
        ->and($props['priceItems'][0]['price_cents'])->toBe(8900)
        ->and(json_encode($props))->not->toContain('Their secret item');
});

it('sends an empty price list and no estimate for a machine worth keeping', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['markdown' => macReportFixture()]);

    $props = showProps($user, $machine);

    expect($props['priceItems'])->toBe([])
        ->and($props['estimate'])->toBeNull();
});

it('does not show another owner’s machine, serial or price list', function () {
    $owner = User::factory()->create();
    $machine = Machine::factory()->for($owner)->create();
    Report::factory()->for($machine)->create(['markdown' => repairMarkdown('C02OWNERSERIAL')]);
    PriceItem::factory()->for($owner)->create(['name' => 'Owner price']);

    actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
});

it('puts neither the serial link nor the price list on the client share pages', function (bool $machinePage) {
    $owner = User::factory()->create();
    $client = Client::factory()->for($owner)->create(['share_token' => Str::random(48)]);
    $machine = Machine::factory()->for($owner)->create(['client_id' => $client->id]);
    Report::factory()->for($machine)->create(['markdown' => repairMarkdown('C02SHARESERIAL')]);
    PriceItem::factory()->for($owner)->create(['name' => 'Secret labour rate', 'price_cents' => 777700]);

    $path = "/c/{$client->share_token}".($machinePage ? "/machines/{$machine->id}" : '');
    $response = $this->get($path)->assertOk();
    $body = (string) $response->getContent();
    $page = '';
    $response->assertInertia(function ($assertable) use (&$page): void {
        $page = json_encode($assertable->toArray(), JSON_THROW_ON_ERROR);
    });

    foreach ([$body, $page] as $text) {
        foreach (['C02SHARESERIAL', 'checkcoverage', 'Secret labour rate', '7777', 'priceItems', 'warranty', 'estimate', 'vatPercent'] as $hidden) {
            expect($text)->not->toContain($hidden);
        }
    }
})->with([[false], [true]]);
