<?php

declare(strict_types=1);

use App\Services\Reports\GuardedCards;
use Illuminate\Support\Facades\Log;

/**
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: 'good'|'bad'|'neutral'}>, list: null}
 */
function guardedCard(string $id): array
{
    return ['id' => $id, 'title' => ucfirst($id), 'rows' => [['label' => 'Row', 'value' => 'Value', 'tone' => 'neutral']], 'list' => null];
}

it('builds every card in order and leaves out the ones with nothing to show', function () {
    $built = GuardedCards::build([
        'First' => fn (): array => guardedCard('first'),
        'Empty' => fn (): ?array => null,
        'Second' => fn (): array => guardedCard('second'),
    ]);

    expect($built)->toBe(['cards' => [guardedCard('first'), guardedCard('second')], 'failed' => []]);
});

it('leaves out a card that throws, keeps the cards after it and logs what went wrong', function (Throwable $thrown) {
    Log::spy();

    $built = GuardedCards::build([
        'First' => fn (): array => guardedCard('first'),
        'Startup items' => fn (): array => throw $thrown,
        'Last' => fn (): array => guardedCard('last'),
    ]);

    expect($built)->toBe(['cards' => [guardedCard('first'), guardedCard('last')], 'failed' => ['Startup items']]);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'A report card could not be built.'
        && $context['card'] === 'Startup items'
        && $context['exception'] === $thrown);
})->with([
    'an exception' => [new RuntimeException('Unexpected line')],
    'a type error' => [new TypeError('Argument #1 must be of type string, null given')],
    'a value error' => [new ValueError('max(): Argument #1 must contain at least one element')],
    'a division by zero' => [new DivisionByZeroError('Division by zero')],
]);

it('names every card that failed', function () {
    Log::spy();

    $built = GuardedCards::build([
        'Storage' => fn (): array => throw new RuntimeException('one'),
        'Battery' => fn (): array => throw new RuntimeException('two'),
    ]);

    expect($built)->toBe(['cards' => [], 'failed' => ['Storage', 'Battery']]);
    Log::shouldHaveReceived('warning')->twice();
});
