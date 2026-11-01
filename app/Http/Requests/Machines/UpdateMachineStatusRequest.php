<?php

declare(strict_types=1);

namespace App\Http\Requests\Machines;

use App\Enums\MachineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateMachineStatusRequest extends FormRequest
{
    /**
     * @return array<string, array<int, Enum|string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MachineStatus::class)],
        ];
    }

    public function status(): MachineStatus
    {
        $status = $this->validated('status');
        assert(is_string($status));

        return MachineStatus::from($status);
    }
}
