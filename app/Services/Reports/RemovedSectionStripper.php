<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * Cuts the sections older scans stored and the scans no longer collect (PCC-73) out of a report,
 * each from its heading to the next heading, keeping every other byte. Headings are found the
 * way ReportSections finds them, outside code fences.
 */
final class RemovedSectionStripper
{
    public const TITLES = [
        'Wi-Fi',
        'DNS and proxy',
        'Hosts file (non-default lines)',
        'Privacy permissions (TCC), needs Full Disk Access for Terminal',
        'Certificates in System keychain',
        'Saved Wi-Fi networks and VPNs',
    ];

    // Today's scans still write this section with only a count per trust domain, so only the old
    // variant goes, which named the certificates under a line per domain.
    private const TRUST_TITLE = 'Trusted certificates: custom trust settings';

    private const OLD_TRUST_LINE = '-- admin';

    private const FENCE = '```';

    /**
     * @return array{markdown: string, removed: list<string>, kept: list<string>}
     */
    public function strip(string $markdown): array
    {
        $result = ['markdown' => '', 'removed' => [], 'kept' => []];

        foreach ($this->pieces($markdown) as $piece) {
            if ($piece['title'] === null || ! $this->isRemoved($piece['title'], $piece['body'])) {
                $result['markdown'] .= $piece['text'];
            } elseif ($this->cutsCleanly($piece['body'])) {
                $result['removed'][] = $piece['title'];
            } else {
                $result['markdown'] .= $piece['text'];
                $result['kept'][] = $piece['title'];
            }
        }

        return $result;
    }

    /**
     * The report as the text before the first heading and one piece per section, so the pieces
     * joined are the report again.
     *
     * @return list<array{title: string|null, text: string, body: list<string>}>
     */
    private function pieces(string $markdown): array
    {
        $parts = preg_split('/(\r\n|\r|\n)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$markdown];
        $pieces = [['title' => null, 'text' => '', 'body' => []]];
        $inFence = false;
        $count = count($parts);

        for ($index = 0; $index < $count; $index += 2) {
            $line = $parts[$index];
            $text = $line.($parts[$index + 1] ?? '');

            if (! $inFence && str_starts_with($line, '## ')) {
                $pieces[] = ['title' => trim(substr($line, 3)), 'text' => $text, 'body' => []];

                continue;
            }

            if (str_starts_with(ltrim($line), self::FENCE)) {
                $inFence = ! $inFence;
            }

            $last = count($pieces) - 1;
            $pieces[$last]['text'] .= $text;
            $pieces[$last]['body'][] = $line;
        }

        return $pieces;
    }

    /**
     * @param  list<string>  $body
     */
    private function isRemoved(string $title, array $body): bool
    {
        if (in_array($title, self::TITLES, true)) {
            return true;
        }

        return $title === self::TRUST_TITLE && in_array(self::OLD_TRUST_LINE, array_map(trim(...), $body), true);
    }

    /**
     * Only a body shaped the way both scripts have always written it is cut: an opening fence,
     * lines that look like neither a fence nor a heading, and a closing fence, or less of it when
     * the upload was cut off. A line of content that looks like a fence or a heading may have
     * moved where the section ends, so cutting it could take more or less than the section.
     *
     * @param  list<string>  $body
     */
    private function cutsCleanly(array $body): bool
    {
        $text = trim(implode("\n", $body));

        if ($text === '') {
            return true;
        }

        $lines = explode("\n", $text);

        if ($lines[0] !== self::FENCE) {
            return false;
        }

        $closed = count($lines) > 1 && trim($lines[count($lines) - 1]) === self::FENCE;

        foreach (array_slice($lines, 1, $closed ? -1 : null) as $line) {
            if (str_starts_with(ltrim($line), self::FENCE) || str_starts_with($line, '## ')) {
                return false;
            }
        }

        return true;
    }
}
