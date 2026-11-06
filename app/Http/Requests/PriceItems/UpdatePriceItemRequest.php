<?php

declare(strict_types=1);

namespace App\Http\Requests\PriceItems;

use App\Concerns\PriceItemRules;
use App\Models\PriceItem;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePriceItemRequest extends FormRequest
{
    use PriceItemRules;

    /**
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        $item = $this->route('priceItem');

        return $this->priceItemRules($item instanceof PriceItem ? $item : null);
    }
}
