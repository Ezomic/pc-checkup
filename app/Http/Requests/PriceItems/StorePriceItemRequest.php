<?php

declare(strict_types=1);

namespace App\Http\Requests\PriceItems;

use App\Concerns\PriceItemRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StorePriceItemRequest extends FormRequest
{
    use PriceItemRules;

    /**
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        return $this->priceItemRules(null);
    }
}
