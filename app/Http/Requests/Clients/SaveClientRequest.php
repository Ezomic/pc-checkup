<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Enums\ShareLocale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class SaveClientRequest extends FormRequest
{
    /**
     * @return array<string, array<int, Enum|ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['sometimes', Rule::enum(ShareLocale::class)],
        ];
    }

    public function locale(): ?ShareLocale
    {
        return $this->enum('locale', ShareLocale::class);
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }
}
