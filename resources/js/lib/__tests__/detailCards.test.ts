import { describe, expect, it } from 'vitest';
import {
    ALL_FINE,
    firstTab,
    groupCards,
    splitBars,
    startsOpen,
    summaryLine,
} from '@/lib/detailCards';
import type { DetailBarGroup, DetailCard } from '@/types';

function card(overrides: Partial<DetailCard> = {}): DetailCard {
    return {
        id: 'battery',
        title: 'Battery',
        rows: [{ label: 'Charge cycles', value: '391', tone: 'neutral' }],
        list: null,
        group: 'Hardware',
        status: 'neutral',
        ...overrides,
    };
}

function bars(count: number): DetailBarGroup[] {
    return Array.from({ length: count }, (_, index) => ({
        title: `List ${index + 1}`,
        items: [],
    }));
}

describe('groupCards', () => {
    it('keeps the groups in the order the server sent them, each with its own cards', () => {
        const groups = groupCards([
            card({ id: 'security', group: 'Security and backup' }),
            card({ id: 'backups', group: 'Security and backup' }),
            card({ id: 'storage', group: 'Storage' }),
            card({ id: 'age', group: 'Hardware' }),
        ]);

        expect(groups.map((group) => group.title)).toEqual([
            'Security and backup',
            'Storage',
            'Hardware',
        ]);
        expect(groups[0].cards.map((found) => found.id)).toEqual([
            'security',
            'backups',
        ]);
    });

    it('counts the cards that need attention per group, and nothing else', () => {
        const groups = groupCards([
            card({ id: 'security', group: 'Security', status: 'attention' }),
            card({ id: 'updates', group: 'Security', status: 'attention' }),
            card({ id: 'backups', group: 'Security', status: 'fine' }),
            card({ id: 'storage', group: 'Storage', status: 'neutral' }),
        ]);

        expect(groups.map((group) => group.attention)).toEqual([2, 0]);
    });

    it('makes no groups without cards, so an empty tab never shows', () => {
        expect(groupCards([])).toEqual([]);
    });

    it('puts a card back in its group even when the cards come mixed up', () => {
        const groups = groupCards([
            card({ id: 'a', group: 'One' }),
            card({ id: 'b', group: 'Two' }),
            card({ id: 'c', group: 'One' }),
        ]);

        expect(groups).toHaveLength(2);
        expect(groups[0].cards.map((found) => found.id)).toEqual(['a', 'c']);
    });
});

describe('firstTab', () => {
    it('opens on the first tab with a card that needs attention', () => {
        const groups = groupCards([
            card({ id: 'security', group: 'Security', status: 'fine' }),
            card({ id: 'storage', group: 'Storage', status: 'attention' }),
            card({ id: 'age', group: 'Hardware', status: 'attention' }),
        ]);

        expect(firstTab(groups)).toBe('Storage');
    });

    it('opens on the first tab when nothing needs attention', () => {
        const groups = groupCards([
            card({ id: 'security', group: 'Security', status: 'fine' }),
            card({ id: 'storage', group: 'Storage', status: 'neutral' }),
        ]);

        expect(firstTab(groups)).toBe('Security');
    });

    it('has no tab without groups', () => {
        expect(firstTab([])).toBeUndefined();
    });
});

describe('startsOpen', () => {
    it('opens a card that needs attention and folds the rest', () => {
        expect(startsOpen(card({ status: 'attention' }))).toBe(true);
        expect(startsOpen(card({ status: 'fine' }))).toBe(false);
        expect(startsOpen(card({ status: 'neutral' }))).toBe(false);
    });
});

describe('summaryLine', () => {
    it('uses the summary of the card when it has one', () => {
        expect(
            summaryLine(
                card({
                    summary: 'About 7 GB can usually be cleared.',
                    status: 'attention',
                    rows: [{ label: 'A', value: 'worn', tone: 'bad' }],
                }),
            ),
        ).toBe('About 7 GB can usually be cleared.');
    });

    it('lists the bad rows when there is no summary', () => {
        expect(
            summaryLine(
                card({
                    summary: null,
                    status: 'attention',
                    rows: [
                        { label: 'FileVault', value: 'Off', tone: 'bad' },
                        { label: 'Gatekeeper', value: 'On', tone: 'good' },
                        {
                            label: 'Application firewall',
                            value: 'Off',
                            tone: 'bad',
                        },
                    ],
                }),
            ),
        ).toBe('FileVault: Off · Application firewall: Off');
    });

    it('says all fine for a fine card without bad rows', () => {
        expect(
            summaryLine(
                card({
                    status: 'fine',
                    rows: [
                        {
                            label: 'Hosts file',
                            value: 'No extra',
                            tone: 'good',
                        },
                        { label: 'DNS', value: 'Router', tone: 'neutral' },
                    ],
                }),
            ),
        ).toBe(ALL_FINE);
    });

    it('shows the first row of a card that only informs', () => {
        expect(
            summaryLine(
                card({
                    status: 'neutral',
                    rows: [
                        {
                            label: 'Charge cycles',
                            value: '391',
                            tone: 'neutral',
                        },
                        { label: 'Condition', value: 'n/a', tone: 'neutral' },
                    ],
                }),
            ),
        ).toBe('Charge cycles: 391');
    });

    it('shows the first row of a card that needs attention for a flag alone', () => {
        expect(
            summaryLine(
                card({
                    status: 'attention',
                    rows: [
                        {
                            label: 'Free',
                            value: '13 GB (11%)',
                            tone: 'neutral',
                        },
                    ],
                }),
            ),
        ).toBe('Free: 13 GB (11%)');
    });

    it('is empty for a card without a summary or rows', () => {
        expect(summaryLine(card({ rows: [], summary: '' }))).toBe('');
    });
});

describe('splitBars', () => {
    it('shows the first two lists and keeps the rest for show more', () => {
        const split = splitBars(bars(5));

        expect(split.shown.map((group) => group.title)).toEqual([
            'List 1',
            'List 2',
        ]);
        expect(split.more.map((group) => group.title)).toEqual([
            'List 3',
            'List 4',
            'List 5',
        ]);
    });

    it('keeps nothing back for two lists or fewer', () => {
        expect(splitBars(bars(2)).more).toEqual([]);
        expect(splitBars([])).toEqual({ shown: [], more: [] });
    });
});
