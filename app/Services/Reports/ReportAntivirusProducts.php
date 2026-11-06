<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttentionLevel;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Reason from \App\Services\NeedsAttention
 *
 * @phpstan-type Product array{name: string, active: bool}
 */
final readonly class ReportAntivirusProducts
{
    public const ROW_LIMIT = 6;

    private const SECTION = 'Antivirus products';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $products = $this->products($sections);

        if ($products === []) {
            return null;
        }

        $active = $this->activeCount($products);

        return [
            'id' => 'antivirus-products',
            'title' => 'Antivirus programs',
            'summary' => match ($active) {
                0 => 'No antivirus program is active',
                1 => '1 antivirus program is active',
                default => "{$active} antivirus programs are active at the same time",
            },
            'rows' => array_map(fn (array $product): array => [
                'label' => $product['name'],
                'value' => $product['active'] ? 'Active' : 'Not active',
                'tone' => match (true) {
                    ! $product['active'] => 'neutral',
                    $active > 1 => 'bad',
                    default => 'good',
                },
            ], array_slice($products, 0, self::ROW_LIMIT)),
            'notes' => array_values(array_filter([
                $active > 1 ? 'Two antivirus programs side by side slow the computer down and can get in each other\'s way. Keep one and uninstall the other. Microsoft Defender steps aside by itself when another one is active.' : null,
                'As Windows Security Center reports them.',
                'Only you see this: it is not on the pages you share with clients.',
            ])),
            'list' => null,
        ];
    }

    /**
     * @return list<Reason>
     */
    public function reasons(ReportSections $sections): array
    {
        $active = $this->activeCount($this->products($sections));

        return $active > 1 ? [['level' => AttentionLevel::Soon->value, 'text' => "{$active} antivirus programs are active at the same time"]] : [];
    }

    /**
     * @param  list<Product>  $products
     */
    private function activeCount(array $products): int
    {
        return count(array_filter($products, fn (array $product): bool => $product['active']));
    }

    /**
     * @return list<Product>
     */
    private function products(ReportSections $sections): array
    {
        $text = $sections->text(self::SECTION);
        $lines = $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);

        if (strtolower((string) ReportDates::keyValue($lines, 'status')) !== 'complete') {
            return [];
        }

        $products = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $name = count($parts) === 3 && $parts[0] === 'product' ? ReportAppName::clean($parts[1]) : null;
            $state = $name === null ? null : strtolower($parts[2]);

            if ($name === null || ! in_array($state, ['on', 'off'], true)) {
                continue;
            }

            $key = mb_strtolower($name);
            $products[$key] = ['name' => $products[$key]['name'] ?? $name, 'active' => ($products[$key]['active'] ?? false) || $state === 'on'];
        }

        return array_values($products);
    }
}
