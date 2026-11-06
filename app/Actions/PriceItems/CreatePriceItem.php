<?php

declare(strict_types=1);

namespace App\Actions\PriceItems;

use App\Models\PriceItem;
use App\Models\User;

final class CreatePriceItem
{
    /**
     * @param  array{name: string, unit: string|null, price_cents: int}  $data
     */
    public function handle(User $user, array $data): PriceItem
    {
        $last = $user->priceItems()->reorder()->max('sort_order');

        return $user->priceItems()->create([...$data, 'sort_order' => is_numeric($last) ? (int) $last + 1 : 0]);
    }
}
