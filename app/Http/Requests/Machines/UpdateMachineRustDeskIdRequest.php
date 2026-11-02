<?php

declare(strict_types=1);

namespace App\Http\Requests\Machines;

use App\Services\RustDesk\RustDeskId;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateMachineRustDeskIdRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rustdesk_id' => ['nullable', 'string', 'regex:'.RustDeskId::PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rustdesk_id.regex' => 'Use 4 to 64 letters, digits, dashes or underscores, without spaces. Copy the ID, not a link.',
        ];
    }

    public function rustdeskId(): ?string
    {
        $id = $this->validated('rustdesk_id');

        return is_string($id) ? $id : null;
    }
}
