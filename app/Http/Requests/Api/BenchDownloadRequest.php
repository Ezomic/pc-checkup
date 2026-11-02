<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class BenchDownloadRequest extends FormRequest
{
    public const DEFAULT_BYTES = 25 * 1024 * 1024;

    public const MAX_BYTES = 50 * 1024 * 1024;

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'bytes' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_BYTES],
        ];
    }

    public function bytes(): int
    {
        return $this->integer('bytes', self::DEFAULT_BYTES);
    }
}
