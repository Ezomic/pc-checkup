<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        abort_unless($user instanceof User, 403);

        $rules = $this->profileRules($user->id);

        // In workflow mode ID owns the email and id-client falls back to it when linking a
        // sign-in to a user, so changing it here would let another ID account sign in as
        // this user. Leaving it out of the rules keeps it out of validated() (WEB-30).
        if ((bool) config('workflow.enabled')) {
            unset($rules['email']);
        }

        return $rules;
    }
}
