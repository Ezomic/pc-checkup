<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\PriceItem;
use App\Models\Report;
use App\Services\Quote\PriceItemMatcher;
use App\Services\Reports\ReportSections;
use App\Services\Reports\UpgradeAdvisor;

/**
 * @phpstan-import-type Card from \App\Services\Reports\ReportDetails
 */
final readonly class BuildUpgradeAdvice
{
    public function __construct(private UpgradeAdvisor $advisor, private PriceItemMatcher $matcher) {}

    /**
     * @param  list<Card>  $cards
     * @param  list<PriceItem>  $priceItems  The price list of the machine's owner, and only theirs.
     * @return array{text: string, price: array{name: string, price_cents: int, unit: string|null}|null}|null
     */
    public function handle(?Report $report, array $cards, array $priceItems): ?array
    {
        $advice = $report === null ? null : $this->advisor->advise(ReportSections::parse($report->markdown), $cards);

        if ($advice === null) {
            return null;
        }

        $item = $this->matcher->match($advice, $priceItems);

        return [
            'text' => $advice['text'],
            'price' => $item === null ? null : ['name' => $item->name, 'price_cents' => $item->price_cents, 'unit' => $item->unit],
        ];
    }
}
