<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PriceItem;
use App\Models\User;

final class PriceItemPolicy
{
    public function update(User $user, PriceItem $priceItem): bool
    {
        return $user->id === $priceItem->user_id;
    }

    public function delete(User $user, PriceItem $priceItem): bool
    {
        return $user->id === $priceItem->user_id;
    }
}
