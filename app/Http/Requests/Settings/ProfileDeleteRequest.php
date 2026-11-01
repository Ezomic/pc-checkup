<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

final class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // In workflow mode the user has no password to give, and the route has already
        // sent them through ID to confirm it is them (RequirePasswordInWorkflowMode).
        if (Config::boolean('workflow.enabled')) {
            return [];
        }

        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
