<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckupScript: string
{
    case Windows = 'windows';
    case Mac = 'mac';

    public function filename(): string
    {
        return match ($this) {
            self::Windows => 'windows-checkup.ps1',
            self::Mac => 'mac-checkup.sh',
        };
    }

    public function path(): string
    {
        return base_path('scripts/'.$this->filename());
    }
}
