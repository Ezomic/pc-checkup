<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CheckupScript;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class PrivacyController extends Controller
{
    public const string NAME_PLACEHOLDER = '[owner name]';

    public const string EMAIL_PLACEHOLDER = '[contact email]';

    public function __invoke(): Response
    {
        $name = $this->setting('pc-checkup.owner_name');
        $email = $this->setting('pc-checkup.privacy_email');

        return Inertia::render('share/Privacy', [
            'ownerName' => $name ?? self::NAME_PLACEHOLDER,
            'contactEmail' => $email ?? self::EMAIL_PLACEHOLDER,
            'contactConfigured' => $email !== null,
            'scripts' => array_map(fn (CheckupScript $script): array => [
                'os' => $script->value,
                'version' => $script->version(),
            ], CheckupScript::cases()),
        ]);
    }

    private function setting(string $key): ?string
    {
        $value = Config::get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
