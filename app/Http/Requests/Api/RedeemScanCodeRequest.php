<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RedeemScanCodeRequest extends FormRequest
{
    /**
     * The report rules are those of POST /api/reports. The code is checked by the action instead,
     * so that a malformed one gets the same answer as a wrong one.
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

    public function code(): string
    {
        $code = $this->input('code');

        return is_string($code) ? $code : '';
    }
}
