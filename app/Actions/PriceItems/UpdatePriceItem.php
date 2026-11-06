<?php

declare(strict_types=1);

namespace App\Actions\PriceItems;

use App\Models\PriceItem;

final class UpdatePriceItem
{
    /**
     * @param  array{name: string, unit: string|null, price_cents: int}  $data
     */
    public function handle(PriceItem $item, array $data): PriceItem
    {
        $item->update($data);

        return $item;
    }
}
