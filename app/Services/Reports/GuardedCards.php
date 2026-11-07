<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @phpstan-import-type Card from ReportDetails
 */
final class GuardedCards
{
    /**
     * Builds each card on its own, so one that throws on an odd report is left out and logged
     * instead of taking the whole page down.
     *
     * @param  array<string, Closure(): (Card|null)>  $builders  By the title the owner knows the card by.
     * @return array{cards: list<Card>, failed: list<string>}
     */
    public static function build(array $builders): array
    {
        $cards = [];
        $failed = [];

        foreach ($builders as $title => $builder) {
            try {
                $card = $builder();
            } catch (Throwable $exception) {
                Log::warning('A report card could not be built.', ['card' => $title, 'exception' => $exception]);
                $failed[] = $title;

                continue;
            }

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return ['cards' => $cards, 'failed' => $failed];
    }
}
