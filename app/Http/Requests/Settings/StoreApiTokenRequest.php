<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ApiAbility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreApiTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|Enum|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::enum(ApiAbility::class)],
        ];
    }

    /**
     * validated() returns mixed, which is a cast-to-string at every call site
     * under level 10. Narrowed once here instead, where the rule that
     * guarantees it is a string is in view.
     */
    public function tokenName(): string
    {
        return $this->string('name')->toString();
    }

    /**
     * @return list<ApiAbility>
     */
    public function abilities(): array
    {
        return array_values(
            $this->collect('abilities')
                ->map(fn (mixed $ability): ?ApiAbility => is_string($ability) ? ApiAbility::tryFrom($ability) : null)
                ->filter()
                ->all(),
        );
    }
}
