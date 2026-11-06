<?php

declare(strict_types=1);

use App\Models\PriceItem;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function priceUser(): User
{
    return User::factory()->create();
}

it('requires authentication', function () {
    $this->get('/settings/price-list')->assertRedirect('/login');
    $this->post('/settings/price-list', [])->assertRedirect('/login');
});

it('starts empty and seeds nothing', function () {
    actingAs(priceUser())->get('/settings/price-list')
        ->assertInertia(fn ($page) => $page->component('settings/PriceList')->has('items', 0));
});

it('lists only my items in sort order with cents', function () {
    $user = priceUser();
    PriceItem::factory()->for($user)->create(['name' => 'Second', 'price_cents' => 2000, 'sort_order' => 2]);
    PriceItem::factory()->for($user)->create(['name' => 'First', 'price_cents' => 1999, 'unit' => 'per hour', 'sort_order' => 1]);
    PriceItem::factory()->create(['name' => 'Theirs']);

    actingAs($user)->get('/settings/price-list')
        ->assertInertia(fn ($page) => $page
            ->has('items', 2)
            ->where('items.0.name', 'First')
            ->where('items.0.price_cents', 1999)
            ->where('items.0.unit', 'per hour')
            ->where('items.1.name', 'Second'));
});

it('creates an item and stores the price in whole cents', function (string $typed, int $cents) {
    $user = priceUser();

    actingAs($user)->post('/settings/price-list', ['name' => 'New SSD', 'price' => $typed, 'unit' => 'each'])->assertRedirect();

    assertDatabaseHas('price_items', ['user_id' => $user->id, 'name' => 'New SSD', 'price_cents' => $cents, 'unit' => 'each']);
})->with([['89,50', 8950], ['89.5', 8950], ['89', 8900], ['0,01', 1], ['999999,99', 99_999_999]]);

it('puts new items after the existing ones', function () {
    $user = priceUser();

    actingAs($user)->post('/settings/price-list', ['name' => 'A', 'price' => '1']);
    actingAs($user)->post('/settings/price-list', ['name' => 'B', 'price' => '2']);

    expect($user->priceItems()->pluck('name')->all())->toBe(['A', 'B'])
        ->and($user->priceItems()->pluck('sort_order')->all())->toBe([0, 1]);
});

it('treats a blank unit as none', function () {
    $user = priceUser();

    actingAs($user)->post('/settings/price-list', ['name' => 'A', 'price' => '1', 'unit' => '']);

    assertDatabaseHas('price_items', ['name' => 'A', 'unit' => null]);
});

it('rejects bad prices and writes nothing', function (string $price) {
    actingAs(priceUser())->post('/settings/price-list', ['name' => 'Thing', 'price' => $price])->assertSessionHasErrors('price');

    assertDatabaseCount('price_items', 0);
})->with(['0', '0,00', '-5', '-0,01', '1000000', '1.250,00', '12,345', 'abc', '', '1e3', '€12', '99999999999999999999']);

it('rejects bad names and units', function (array $input, string $field) {
    actingAs(priceUser())->post('/settings/price-list', ['price' => '5', ...$input])->assertSessionHasErrors($field);

    assertDatabaseCount('price_items', 0);
})->with([
    'missing name' => [[], 'name'],
    'blank name' => [['name' => '   '], 'name'],
    'name too long' => [['name' => str_repeat('a', 81)], 'name'],
    'newline in name' => [['name' => "Line\nbreak"], 'name'],
    'control char in name' => [['name' => "Bell\x07"], 'name'],
    'zero width space in name' => [['name' => "Ba\u{200B}ttery"], 'name'],
    'bidi override in name' => [['name' => "Bat\u{202E}tery"], 'name'],
    'line separator in unit' => [['name' => 'A', 'unit' => "a\u{2028}b"], 'unit'],
    'array name' => [['name' => ['x']], 'name'],
    'unit too long' => [['name' => 'A', 'unit' => str_repeat('u', 21)], 'unit'],
    'newline in unit' => [['name' => 'A', 'unit' => "a\nb"], 'unit'],
]);

it('accepts the longest name and unicode and keeps markup as plain text', function () {
    $user = priceUser();
    $name = '<img src=x onerror=alert(1)> '.str_repeat('é', 50);

    actingAs($user)->post('/settings/price-list', ['name' => mb_substr($name, 0, 80), 'price' => '5'])->assertSessionHasNoErrors();
    actingAs($user)->post('/settings/price-list', ['name' => str_repeat('é', 80), 'price' => '5'])->assertSessionHasNoErrors();

    expect($user->priceItems()->first()?->name)->toBe(mb_substr($name, 0, 80));
});

it('refuses a duplicate name of mine whatever the case, but allows the same name for another user', function () {
    $user = priceUser();
    PriceItem::factory()->for($user)->create(['name' => 'New SSD']);

    actingAs($user)->post('/settings/price-list', ['name' => ' new ssd ', 'price' => '5'])->assertSessionHasErrors('name');

    actingAs(priceUser())->post('/settings/price-list', ['name' => 'New SSD', 'price' => '5'])->assertSessionHasNoErrors();
    assertDatabaseCount('price_items', 2);
});

it('updates my item and lets it keep its own name', function () {
    $user = priceUser();
    $item = PriceItem::factory()->for($user)->create(['name' => 'SSD', 'price_cents' => 100]);

    actingAs($user)->put("/settings/price-list/{$item->id}", ['name' => 'SSD', 'price' => '12,50', 'unit' => ''])->assertSessionHasNoErrors();

    assertDatabaseHas('price_items', ['id' => $item->id, 'name' => 'SSD', 'price_cents' => 1250]);
});

it('refuses to rename onto another of my items', function () {
    $user = priceUser();
    PriceItem::factory()->for($user)->create(['name' => 'RAM']);
    $item = PriceItem::factory()->for($user)->create(['name' => 'SSD']);

    actingAs($user)->put("/settings/price-list/{$item->id}", ['name' => 'ram', 'price' => '5'])->assertSessionHasErrors('name');
});

it('deletes my item', function () {
    $user = priceUser();
    $item = PriceItem::factory()->for($user)->create();

    actingAs($user)->delete("/settings/price-list/{$item->id}")->assertRedirect();

    assertDatabaseMissing('price_items', ['id' => $item->id]);
});

it('never lets me change or delete someone else’s item', function () {
    $item = PriceItem::factory()->create(['name' => 'Theirs', 'price_cents' => 500]);

    actingAs(priceUser())->put("/settings/price-list/{$item->id}", ['name' => 'Mine now', 'price' => '1'])->assertForbidden();
    actingAs(priceUser())->delete("/settings/price-list/{$item->id}")->assertForbidden();

    assertDatabaseHas('price_items', ['id' => $item->id, 'name' => 'Theirs', 'price_cents' => 500]);
});

it('answers 404 for a missing or non numeric item id', function () {
    actingAs(priceUser())->delete('/settings/price-list/999999')->assertNotFound();
    actingAs(priceUser())->delete('/settings/price-list/abc')->assertNotFound();
});

it('removes a user’s items with the user', function () {
    $user = priceUser();
    PriceItem::factory()->for($user)->create();

    $user->delete();

    assertDatabaseCount('price_items', 0);
});
