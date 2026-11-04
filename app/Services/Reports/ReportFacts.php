<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class ReportFacts
{
    /**
     * @return array{free_disk: string|null, memory: string|null, cpu: string|null}
     */
    public function from(string $markdown): array
    {
        $header = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (str_starts_with($line, '## ')) {
                break;
            }

            if (preg_match('/^- ([^:]+):\s*(\S.*)$/u', $line, $match) === 1) {
                $header[mb_strtolower(trim($match[1]))] = trim($match[2]);
            }
        }

        return [
            'free_disk' => $this->known($header['free disk'] ?? null),
            'memory' => $this->known($header['memory'] ?? null),
            'cpu' => $this->known($header['chip/cpu'] ?? null),
        ];
    }

    private function known(?string $value): ?string
    {
        return $value === null || preg_match('/\A(?:n\/a%?|unknown|none|-)\z/i', $value) === 1 ? null : $value;
    }
}
