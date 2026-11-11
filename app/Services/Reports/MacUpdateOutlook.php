<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * What is true about updates for a Mac whose macOS Apple no longer supports and whose Software
 * Update check found nothing: installing updates is no fix, so say what the Mac can still move to.
 */
final readonly class MacUpdateOutlook
{
    /**
     * @return array{running: string, newest: string|null, newestSupported: bool}|null The newest is null when the Mac already runs it.
     */
    public function read(ReportSections $sections): ?array
    {
        $running = MacNewestOs::running($sections->header('macos') ?? $this->productVersion($sections->text('macOS version')));
        $newest = MacNewestOs::forModel($sections->header('model'));
        $newestName = $newest === null ? null : MacNewestOs::name($newest);

        if ($running === null || $newest === null || $newestName === null || MacNewestOs::isSupportedByApple($running)
            || version_compare($running, $newest, '>') || ! $this->nothingOffered($sections->text('Software updates'))) {
            return null;
        }

        return [
            'running' => MacNewestOs::name($running) ?? "macOS {$running}",
            'newest' => version_compare($running, $newest, '<') ? $newestName : null,
            'newestSupported' => MacNewestOs::isSupportedByApple($newest),
        ];
    }

    private function productVersion(?string $text): ?string
    {
        return $text !== null && preg_match('/^ProductVersion:\s*(\S+)/m', $text, $match) === 1 ? $match[1] : null;
    }

    private function nothingOffered(?string $text): bool
    {
        return $text !== null
            && preg_match('/^No new software available\.?\s*$/m', $text) === 1
            && preg_match('/found the following new or updated software|^\s*\*\s/m', $text) !== 1;
    }
}
