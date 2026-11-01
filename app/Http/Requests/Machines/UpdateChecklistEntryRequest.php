<?php

declare(strict_types=1);

namespace App\Http\Requests\Machines;

use App\Enums\ChecklistOutcome;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateChecklistEntryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|Enum|string>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['nullable', Rule::enum(ChecklistOutcome::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function outcome(): ?ChecklistOutcome
    {
        return $this->enum('outcome', ChecklistOutcome::class);
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
