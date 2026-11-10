<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Flags\FlagAdvisor;
use App\Services\Flags\FlagChecks;

/**
 * Lays the machine page cards out in tabs: the verdict apart, the rest in tab order, each with its
 * group and whether it needs attention, is fine or only informs.
 *
 * @phpstan-import-type Card from ReportDetails
 */
final readonly class DetailCardTabs
{
    /**
     * Per tab, in tab order, the cards it holds in the order they are shown.
     *
     * @var array<string, list<string>>
     */
    public const array GROUPS = [
        'Security and backup' => ['security', 'backups', 'updates', 'remote-access', 'network-settings', 'activation-bitlocker', 'antivirus-products'],
        'Speed and stability' => ['slowness', 'startup', 'boot-time', 'stability', 'crashing-apps', 'device-problems'],
        'Storage' => ['storage', 'storage-breakdown', 'reclaimable', 'unused-apps'],
        'Hardware' => ['age', 'battery', 'parts', 'wifi', 'upgrade'],
    ];

    public const string OTHER = 'Other';

    private const string VERDICT = 'verdict';

    public function __construct(private FlagAdvisor $advisor) {}

    /**
     * @param  list<Card>  $cards
     * @param  list<string>  $flags
     * @return array{verdict: Card|null, cards: list<Card>}
     */
    public function arrange(array $cards, array $flags): array
    {
        $flagged = $this->flaggedCards($flags);
        $verdict = null;
        $placed = [];

        foreach ($cards as $card) {
            if ($card['id'] === self::VERDICT) {
                $verdict = $card;

                continue;
            }

            [$group, $rank] = $this->place($card['id']);
            $placed[] = ['rank' => $rank, 'card' => [...$card, 'group' => $group, 'status' => $this->status($card, $flagged)]];
        }

        usort($placed, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return ['verdict' => $verdict, 'cards' => array_column($placed, 'card')];
    }

    /**
     * @return array{string, array{int, int}}
     */
    private function place(string $id): array
    {
        $tab = 0;

        foreach (self::GROUPS as $group => $ids) {
            $position = array_search($id, $ids, true);

            if ($position !== false) {
                return [$group, [$tab, $position]];
            }

            $tab++;
        }

        return [self::OTHER, [$tab, 0]];
    }

    /**
     * @param  Card  $card
     * @param  list<string>  $flagged
     * @return 'attention'|'fine'|'neutral'
     */
    private function status(array $card, array $flagged): string
    {
        $tones = array_column($card['rows'], 'tone');

        return match (true) {
            in_array('bad', $tones, true) || in_array($card['id'], $flagged, true) => 'attention',
            in_array('good', $tones, true) => 'fine',
            default => 'neutral',
        };
    }

    /**
     * @param  list<string>  $flags
     * @return list<string>
     */
    private function flaggedCards(array $flags): array
    {
        return array_values(array_filter(array_map(
            fn (string $flag): ?string => FlagChecks::CARDS[$this->advisor->advise($flag)->rule ?? ''] ?? null,
            $flags,
        )));
    }
}
