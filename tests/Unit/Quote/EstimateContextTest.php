<?php

declare(strict_types=1);

use App\Services\Quote\EstimateContext;

/**
 * @param  list<array{label: string, value: string, tone: 'good'|'bad'|'neutral'}>  $rows
 * @return list<array{id: string, title: string, rows: list<array{label: string, value: string, tone: 'good'|'bad'|'neutral'}>, list: null}>
 */
function verdictCards(array $rows): array
{
    return [
        ['id' => 'age', 'title' => 'Age', 'rows' => [['label' => 'Model', 'value' => 'X', 'tone' => 'neutral']], 'list' => null],
        ['id' => 'verdict', 'title' => 'Worth keeping?', 'rows' => $rows, 'list' => null],
    ];
}

it('hands back the verdict and the reason texts for a repair', function () {
    $context = (new EstimateContext)->from(verdictCards([
        ['label' => 'Verdict', 'value' => 'Repair', 'tone' => 'neutral'],
        ['label' => 'Only 4 GB of memory', 'value' => 'Adding memory is a cheap fix.', 'tone' => 'bad'],
        ['label' => 'Good to know', 'value' => 'A rule of thumb.', 'tone' => 'neutral'],
    ]));

    expect($context)->toBe(['verdict' => 'Repair', 'reasons' => ['Only 4 GB of memory Adding memory is a cheap fix.']]);
});

it('works for a replace verdict', function () {
    expect((new EstimateContext)->from(verdictCards([['label' => 'Verdict', 'value' => 'Replace', 'tone' => 'bad']]))['verdict'] ?? null)->toBe('Replace');
});

it('gives nothing for keep, not enough data, no verdict card or no cards', function (array $cards) {
    expect((new EstimateContext)->from($cards))->toBeNull();
})->with([
    'keep' => [verdictCards([['label' => 'Verdict', 'value' => 'Keep', 'tone' => 'good']])],
    'not enough data' => [verdictCards([['label' => 'Verdict', 'value' => 'Not enough data to judge', 'tone' => 'neutral']])],
    'empty verdict card' => [verdictCards([])],
    'no verdict card' => [[['id' => 'age', 'title' => 'Age', 'rows' => [], 'list' => null]]],
    'no cards' => [[]],
]);
