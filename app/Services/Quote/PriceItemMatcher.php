<?php

declare(strict_types=1);

namespace App\Services\Quote;

use App\Models\PriceItem;

/**
 * Finds the item in an owner's price list that prices an upgrade advice, by its name and in the order
 * the owner sorted the list. Memory of another generation, module size or form factor, an SSD that is
 * external or too small, an NVMe drive for a SATA swap and memory cards or sticks are never matched.
 *
 * @phpstan-import-type Advice from \App\Services\Reports\UpgradeAdvisor
 */
final readonly class PriceItemMatcher
{
    private const SSD = '/\bssd\b|solid.?state/i';

    private const NOT_SATA = '/\bnvme\b|\bm\.?2\b/i';

    private const NOT_INTERNAL = '/\busb\b|extern|enclosure|behuizing/i';

    private const MEMORY = '/\bram\b|\bmemory\b|\bgeheugen\b|werkgeheugen|\b(?:so-?)?dimm\b|\b(?:lp)?ddr\d?\b/i';

    private const NOT_MEMORY = '/card|kaart|\busb\b|stick/i';

    private const GENERATION = '/\b(lp)?ddr(\d?)/i';

    private const SAME_GENERATION = 2;

    private const SAME_SIZE = 1;

    private const GB_PER_TB = 1000;

    // A drive the scan reports in binary units (466 GB) is sold as a decimal size (500 GB).
    private const DRIVE_SIZE_TOLERANCE = 0.9;

    /**
     * @param  Advice  $advice
     * @param  list<PriceItem>  $items
     */
    public function match(array $advice, array $items): ?PriceItem
    {
        return $advice['part'] === 'ssd' ? $this->ssd($advice, $items) : $this->memory($advice, $items);
    }

    /**
     * @param  Advice  $advice
     * @param  list<PriceItem>  $items
     */
    private function ssd(array $advice, array $items): ?PriceItem
    {
        foreach ($items as $item) {
            $sizes = $this->driveSizes($item->name);
            $bigEnough = $advice['driveGb'] === null || $sizes === [] || max($sizes) >= $advice['driveGb'] * self::DRIVE_SIZE_TOLERANCE;

            $internal = preg_match(self::NOT_INTERNAL, $item->name) !== 1 && ($advice['bus'] !== 'SATA' || preg_match(self::NOT_SATA, $item->name) !== 1);

            if (preg_match(self::SSD, $item->name) === 1 && $bigEnough && $internal) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<float>
     */
    private function driveSizes(string $name): array
    {
        preg_match_all('/\b(\d{1,4}(?:[.,]\d+)?)\s*(GB|TB)\b/i', $name, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): float => (float) str_replace(',', '.', $match[1]) * (strtoupper($match[2]) === 'TB' ? self::GB_PER_TB : 1), $matches);
    }

    /**
     * @param  Advice  $advice
     * @param  list<PriceItem>  $items
     */
    private function memory(array $advice, array $items): ?PriceItem
    {
        $wanted = $this->generation($advice['memoryType']);
        $best = null;
        $bestScore = -1;

        foreach ($items as $item) {
            $generation = $this->generation($item->name);
            $sizes = $this->sizes($item->name);

            if (! $this->isMemoryFor($advice, $item->name, $generation, $wanted, $sizes)) {
                continue;
            }

            $score = ($generation !== null ? self::SAME_GENERATION : 0) + ($sizes !== [] ? self::SAME_SIZE : 0);

            if ($score > $bestScore) {
                [$best, $bestScore] = [$item, $score];
            }
        }

        return $best;
    }

    /**
     * @param  Advice  $advice
     * @param  list<int>  $sizes
     */
    private function isMemoryFor(array $advice, string $name, ?string $generation, ?string $wanted, array $sizes): bool
    {
        $form = $this->form($name);

        return preg_match(self::MEMORY, $name) === 1
            && preg_match(self::NOT_MEMORY, $name) !== 1
            && in_array($generation, [null, $wanted], true)
            && ($sizes === [] || in_array($advice['moduleGb'], $sizes, true))
            && ($form === null || $advice['form'] === null || $form === $advice['form']);
    }

    private function generation(?string $text): ?string
    {
        return preg_match(self::GENERATION, (string) $text, $match) === 1 ? strtoupper($match[1]).$match[2] : null;
    }

    private function form(string $name): ?string
    {
        return match (true) {
            preg_match('/\bso-?dimm\b/i', $name) === 1 => 'SODIMM',
            preg_match('/\bdimm\b/i', $name) === 1 => 'DIMM',
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    private function sizes(string $name): array
    {
        preg_match_all('/\b(\d{1,4})\s*GB\b/i', $name, $matches);

        return array_map('intval', $matches[1]);
    }
}
