import type { DetailBarGroup, DetailCard } from '@/types';

export type DetailCardGroup = {
    title: string;
    cards: DetailCard[];
    attention: number;
};

export const VISIBLE_BAR_GROUPS = 2;

export const ALL_FINE = 'All fine';

export function groupCards(cards: DetailCard[]): DetailCardGroup[] {
    const groups: DetailCardGroup[] = [];

    for (const card of cards) {
        let group = groups.find((found) => found.title === card.group);

        if (group === undefined) {
            group = { title: card.group, cards: [], attention: 0 };
            groups.push(group);
        }

        group.cards.push(card);
        group.attention += card.status === 'attention' ? 1 : 0;
    }

    return groups;
}

export function firstTab(groups: DetailCardGroup[]): string | undefined {
    return (groups.find((group) => group.attention > 0) ?? groups[0])?.title;
}

export function startsOpen(card: DetailCard): boolean {
    return card.status === 'attention';
}

export function summaryLine(card: DetailCard): string {
    if (card.summary) {
        return card.summary;
    }

    const bad = card.rows.filter((row) => row.tone === 'bad');

    if (bad.length > 0) {
        return bad.map((row) => `${row.label}: ${row.value}`).join(' · ');
    }

    if (card.status === 'fine') {
        return ALL_FINE;
    }

    const first = card.rows[0];

    return first ? `${first.label}: ${first.value}` : '';
}

export function splitBars(bars: DetailBarGroup[]): {
    shown: DetailBarGroup[];
    more: DetailBarGroup[];
} {
    return {
        shown: bars.slice(0, VISIBLE_BAR_GROUPS),
        more: bars.slice(VISIBLE_BAR_GROUPS),
    };
}
