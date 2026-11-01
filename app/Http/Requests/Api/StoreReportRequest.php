<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreReportRequest extends FormRequest
{
    /**
     * Same ceiling as the paste box on the import page.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'markdown' => ['required', 'string', 'max:2000000'],
        ];
    }

    public function markdown(): string
    {
        return $this->string('markdown')->toString();
    }
}
