<?php

declare(strict_types=1);

namespace App\Actions\PriceItems;

use App\Models\PriceItem;

final class DeletePriceItem
{
    public function handle(PriceItem $item): void
    {
        $item->delete();
    }
}
