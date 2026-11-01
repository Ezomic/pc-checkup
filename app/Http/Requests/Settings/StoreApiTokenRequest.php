<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreApiTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
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
}
