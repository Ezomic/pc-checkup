<?php

declare(strict_types=1);

namespace App\Services\Reports;

final readonly class ReportSections
{
    /**
     * @param  array<string, string>  $header
     * @param  array<string, string>  $sections  Section text by lower-cased title, first occurrence only.
     * @param  list<string>  $titles  The titles as written, first occurrence only, in report order.
     */
    private function __construct(private array $header, private array $sections, private array $titles) {}

    public static function parse(string $markdown): self
    {
        $header = [];
        $titles = [];
        $bodies = [];
        $current = null;
        $inFence = false;

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            $isFence = str_starts_with(ltrim($line), '```');

            if (! $inFence && str_starts_with($line, '## ')) {
                $current = count($titles);
                $titles[] = trim(substr($line, 3));
                $bodies[] = [];

                continue;
            }

            if ($isFence) {
                $inFence = ! $inFence;

                continue;
            }

            if ($current !== null) {
                $bodies[$current][] = $line;
            } elseif (! $inFence && preg_match('/^- ([^:]+):\s*(\S.*)$/u', $line, $match) === 1) {
                $header[mb_strtolower(trim($match[1]))] = trim($match[2]);
            }
        }

        $sections = [];
        $written = [];

        foreach ($titles as $index => $title) {
            $key = mb_strtolower($title);

            if (! isset($sections[$key])) {
                $sections[$key] = trim(implode("\n", $bodies[$index]));
                $written[] = $title;
            }
        }

        return new self($header, $sections, $written);
    }

    /**
     * @return list<string>
     */
    public function titles(): array
    {
        return $this->titles;
    }

    public function header(string $key): ?string
    {
        return $this->header[mb_strtolower($key)] ?? null;
    }

    public function text(string $title): ?string
    {
        return $this->sections[mb_strtolower($title)] ?? null;
    }
}
