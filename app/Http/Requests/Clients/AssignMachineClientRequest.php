<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class AssignMachineClientRequest extends FormRequest
{
    /**
     * @return array<string, array<int, Exists|string>>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'client_id' => [
                'nullable',
                'integer',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user instanceof User ? $user->id : 0)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function clientId(): ?int
    {
        return $this->filled('client_id') ? $this->integer('client_id') : null;
    }
}
