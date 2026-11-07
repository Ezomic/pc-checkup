<?php

declare(strict_types=1);

namespace App\Http\Requests\Scans;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class ApprovePairingRequest extends FormRequest
{
    /**
     * Only the owner's own client, and only a machine that is already that client's.
     *
     * @return array<string, array<int, Exists|ValidationRule|string>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $userId = $user instanceof User ? $user->id : 0;

        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('user_id', $userId)->whereNull('deleted_at')],
            'machine_id' => ['nullable', 'integer', Rule::exists('machines', 'id')
                ->where('user_id', $userId)
                ->where('client_id', $this->integer('client_id'))
                ->whereNull('deleted_at')],
        ];
    }
}
