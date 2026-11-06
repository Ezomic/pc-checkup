<?php

declare(strict_types=1);

namespace App\Services\Quote;

/**
 * @phpstan-import-type Card from \App\Services\Reports\ReportDetails
 *
 * @phpstan-type Context array{verdict: string, reasons: list<string>}
 */
final readonly class EstimateContext
{
    private const VERDICTS = ['Repair', 'Replace'];

    private const SKIPPED_LABELS = ['Verdict', 'Good to know'];

    /**
     * @param  list<Card>  $cards
     * @return Context|null
     */
    public function from(array $cards): ?array
    {
        foreach ($cards as $card) {
            if ($card['id'] !== 'verdict') {
                continue;
            }

            $verdict = $card['rows'][0]['value'] ?? '';

            if (! in_array($verdict, self::VERDICTS, true)) {
                return null;
            }

            $reasons = [];

            foreach ($card['rows'] as $row) {
                if (! in_array($row['label'], self::SKIPPED_LABELS, true)) {
                    $reasons[] = $row['label'].' '.$row['value'];
                }
            }

            return ['verdict' => $verdict, 'reasons' => $reasons];
        }

        return null;
    }
}
