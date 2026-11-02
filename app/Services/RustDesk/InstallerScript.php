<?php

declare(strict_types=1);

namespace App\Services\RustDesk;

use Illuminate\Support\Facades\Config;

final class InstallerScript
{
    private const HOST_PATTERN = '/\A[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d{1,5})?\z/';

    private const KEY_PATTERN = '/\A[A-Za-z0-9+\/_=-]{20,}\z/';

    public function configured(): bool
    {
        return $this->host() !== null && $this->key() !== null && $this->relay() !== null;
    }

    public function render(): string
    {
        $host = $this->host();
        $key = $this->key();
        $relay = $this->relay();

        abort_if($host === null || $key === null || $relay === null, 404);

        return strtr((string) file_get_contents(resource_path('rustdesk/install-windows.ps1')), [
            '{{HOST}}' => $host,
            '{{RELAY}}' => $relay,
            '{{KEY}}' => $key,
        ]);
    }

    private function host(): ?string
    {
        return $this->setting('rustdesk.host', self::HOST_PATTERN);
    }

    private function relay(): ?string
    {
        if (in_array(Config::get('rustdesk.relay'), [null, ''], true)) {
            return $this->host();
        }

        return $this->setting('rustdesk.relay', self::HOST_PATTERN);
    }

    private function key(): ?string
    {
        return $this->setting('rustdesk.key', self::KEY_PATTERN);
    }

    private function setting(string $key, string $pattern): ?string
    {
        $value = Config::get($key);

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
