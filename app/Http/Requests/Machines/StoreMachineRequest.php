<?php

declare(strict_types=1);

namespace App\Http\Requests\Machines;

use App\Enums\DeviceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreMachineRequest extends FormRequest
{
    /**
     * @return array<string, array<int, Enum|ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'serial' => ['nullable', 'string', 'max:100'],
            'hardware_uuid' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:100'],
            'device_type' => ['nullable', 'string', Rule::enum(DeviceType::class)],
            'model' => ['nullable', 'string', 'max:255'],
        ];
    }
}
