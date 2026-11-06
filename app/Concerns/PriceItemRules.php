<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\PriceItem;
use App\Models\User;
use App\Services\Quote\EuroAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait PriceItemRules
{
    /**
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    protected function priceItemRules(?PriceItem $current): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:80', 'not_regex:/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u',$this->uniqueName($current)],
            'unit' => ['nullable', 'string', 'max:20', 'not_regex:/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u'],
            'price' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $cents = EuroAmount::toCents(is_string($value) ? $value : '');

                if ($cents === null) {
                    $fail('Enter an amount like 12,50 with digits and one comma or dot.');
                } elseif ($cents < 1) {
                    $fail('The price must be above zero.');
                }
            }],
        ];
    }

    /**
     * @return array{name: string, unit: string|null, price_cents: int}
     */
    public function priceItem(): array
    {
        $unit = $this->string('unit')->toString();

        return [
            'name' => $this->string('name')->toString(),
            'unit' => $unit === '' ? null : $unit,
            'price_cents' => (int) EuroAmount::toCents($this->string('price')->toString()),
        ];
    }

    private function uniqueName(?PriceItem $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            $user = $this->user();
            $wanted = mb_strtolower(trim(is_string($value) ? $value : ''));

            $taken = $user instanceof User && $user->priceItems()
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current?->id))
                ->pluck('name')
                ->contains(fn (string $name): bool => mb_strtolower(trim($name)) === $wanted);

            if ($taken) {
                $fail('You already have a price item with this name.');
            }
        };
    }
}
