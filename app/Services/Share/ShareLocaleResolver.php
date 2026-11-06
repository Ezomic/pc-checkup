<?php

declare(strict_types=1);

namespace App\Services\Share;

use App\Enums\ShareLocale;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class ShareLocaleResolver
{
    public const string QUERY = 'lang';

    public const string COOKIE = 'share_locale';

    private const int COOKIE_MINUTES = 60 * 24 * 365;

    private const int MAX_TOKEN_LENGTH = 64;

    public function apply(Request $request): ShareLocale
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale->value);

        return $locale;
    }

    private function resolve(Request $request): ShareLocale
    {
        $chosen = $this->parse($request->query(self::QUERY));

        if ($chosen !== null) {
            Cookie::queue(Cookie::make(self::COOKIE, $chosen->value, self::COOKIE_MINUTES, '/c', null, null, true, false, 'lax'));

            return $chosen;
        }

        return $this->parse($request->cookie(self::COOKIE))
            ?? $this->forClientOf($request)
            ?? ShareLocale::default();
    }

    private function forClientOf(Request $request): ?ShareLocale
    {
        $token = $request->segment(2);

        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        return Client::query()->where('share_token', $token)->first(['id', 'locale'])?->locale;
    }

    private function parse(mixed $value): ?ShareLocale
    {
        return is_string($value) ? ShareLocale::tryFrom($value) : null;
    }
}
