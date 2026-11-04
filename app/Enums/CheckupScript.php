<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckupScript: string
{
    public const string VERSION_TOKEN = '__PCC_SCRIPT_VERSION__';

    case Windows = 'windows';
    case Mac = 'mac';

    public static function forOs(?string $os): ?self
    {
        $os = mb_strtolower((string) $os);

        return match (true) {
            str_contains($os, 'windows') => self::Windows,
            str_contains($os, 'macos'), str_contains($os, 'darwin') => self::Mac,
            default => null,
        };
    }

    public static function versionOf(string $source): string
    {
        return substr(hash('sha256', str_replace("\r\n", "\n", $source)), 0, 8);
    }

    public function filename(): string
    {
        return match ($this) {
            self::Windows => 'windows-checkup.ps1',
            self::Mac => 'mac-checkup.sh',
        };
    }

    public function downloadName(): string
    {
        return str_replace('-checkup.', '-checkup-'.$this->version().'.', $this->filename());
    }

    public function path(): string
    {
        return base_path('scripts/'.$this->filename());
    }

    public function version(): string
    {
        return self::versionOf($this->source());
    }

    public function contents(): string
    {
        return str_replace(self::VERSION_TOKEN, $this->version(), $this->source());
    }

    private function source(): string
    {
        return (string) file_get_contents($this->path());
    }
}
