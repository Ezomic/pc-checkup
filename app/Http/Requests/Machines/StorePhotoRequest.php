<?php

declare(strict_types=1);

namespace App\Http\Requests\Machines;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StorePhotoRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
