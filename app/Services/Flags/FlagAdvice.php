<?php

declare(strict_types=1);

namespace App\Services\Flags;

use App\Enums\FlagSeverity;

final readonly class FlagAdvice
{
    public function __construct(
        public FlagSeverity $severity,
        public string $headline,
        public ?string $advice,
        public string $raw,
        public string $key,
        public ?string $rule = null,
    ) {}

    /**
     * @return array{severity: string, headline: string, advice: string|null, raw: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'headline' => $this->headline,
            'advice' => $this->advice,
            'raw' => $this->raw,
        ];
    }
}
