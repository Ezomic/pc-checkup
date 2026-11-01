<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreReportImportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required_without:text', 'nullable', 'file', 'extensions:md,txt', 'max:2048'],
            'text' => ['required_without:file', 'nullable', 'string', 'max:2000000'],
        ];
    }
}
