import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DetailCardItem from '@/components/machines/DetailCardItem.vue';
import DetailCards from '@/components/machines/DetailCards.vue';
import type { DetailCard } from '@/types';

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

function tabbed(): DetailCard[] {
    return [
        card({
            id: 'security',
            title: 'Security summary',
            group: 'Security and backup',
            status: 'fine',
        }),
        card({
            id: 'updates',
            title: 'Updates',
            group: 'Security and backup',
            status: 'neutral',
        }),
        card({
            id: 'stability',
            title: 'Stability',
            group: 'Speed and stability',
            status: 'fine',
        }),
        card({
            id: 'storage',
            title: 'Storage',
            group: 'Storage',
            status: 'attention',
        }),
        card({
            id: 'storage-breakdown',
            title: 'Storage breakdown',
            group: 'Storage',
            status: 'attention',
        }),
        card({
            id: 'age',
            title: 'Age',
            group: 'Hardware',
            status: 'attention',
        }),
    ];
}

function render(cards: DetailCard[]) {
    return mount(DetailCards, { props: { cards }, attachTo: document.body });
}

function activeGroup(wrapper: ReturnType<typeof render>): string {
    return wrapper
        .findAll('[data-test="detail-group"]')
        .filter((group) => group.attributes('data-state') === 'active')
        .map((group) => group.get('h2').text())
        .join();
}

describe('DetailCards', () => {
    it('renders nothing when there are no cards', () => {
        const wrapper = render([]);

        expect(wrapper.find('[data-test="detail-cards"]').exists()).toBe(false);
    });

    it('makes a tab per group, in the order the server sent them', () => {
        const wrapper = render(tabbed());
        const tabs = wrapper.findAll('[data-test="detail-tab"]');

        expect(tabs.map((tab) => tab.attributes('role'))).toEqual([
            'tab',
            'tab',
            'tab',
            'tab',
        ]);
        expect(
            tabs.map((tab) => tab.text().replace(/\d.*$/, '').trim()),
        ).toEqual([
            'Security and backup',
            'Speed and stability',
            'Storage',
            'Hardware',
        ]);
        wrapper.unmount();
    });

    it('shows a red count on a tab with cards that need attention, and none on the others', () => {
        const wrapper = render(tabbed());
        const counts = wrapper
            .findAll('[data-test="detail-tab"]')
            .map(
                (tab) =>
                    tab.find('[data-test="detail-tab-count"]').exists() &&
                    tab
                        .get('[data-test="detail-tab-count"] [aria-hidden]')
                        .text(),
            );

        expect(counts).toEqual([false, false, '2', '1']);
        expect(
            wrapper.get('[data-test="detail-tab-count"]').classes(),
        ).toContain('bg-red-600');
        expect(wrapper.get('[data-test="detail-tab-count"]').text()).toContain(
            '2 cards need attention',
        );
        expect(
            wrapper.findAll('[data-test="detail-tab-count"]')[1].text(),
        ).toContain('1 card needs attention');
        wrapper.unmount();
    });

    it('opens on the first tab with a card that needs attention', () => {
        const wrapper = render(tabbed());

        expect(activeGroup(wrapper)).toBe('Storage');
        wrapper.unmount();
    });

    it('opens on the first tab when nothing needs attention', () => {
        const wrapper = render(
            tabbed().map((found) => ({ ...found, status: 'fine' as const })),
        );

        expect(activeGroup(wrapper)).toBe('Security and backup');
        expect(wrapper.find('[data-test="detail-tab-count"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('switches to another tab when it is chosen', async () => {
        const wrapper = render(tabbed());

        await wrapper
            .findAll('[data-test="detail-tab"]')[1]
            .trigger('mousedown');

        expect(activeGroup(wrapper)).toBe('Speed and stability');
        wrapper.unmount();
    });

    it('keeps every group in the page for print, each with its name as a heading', () => {
        const wrapper = render(tabbed());
        const groups = wrapper.findAll('[data-test="detail-group"]');

        expect(groups.map((group) => group.get('h2').text())).toEqual([
            'Security and backup',
            'Speed and stability',
            'Storage',
            'Hardware',
        ]);

        for (const group of groups) {
            expect(group.classes()).toContain('data-[state=inactive]:hidden');
            expect(group.classes()).toContain(
                'print:data-[state=inactive]:block',
            );
            expect(group.get('h2').classes()).toEqual(
                expect.arrayContaining(['hidden', 'print:block']),
            );
        }

        expect(wrapper.findAllComponents(DetailCardItem)).toHaveLength(6);
        expect(wrapper.get('[role="tablist"]').classes()).toContain(
            'print:hidden',
        );
        wrapper.unmount();
    });

    it('lets the tabs wrap on a narrow screen and puts each card in a fixed column', () => {
        const wrapper = render(tabbed());

        expect(wrapper.get('[role="tablist"]').classes()).toContain(
            'flex-wrap',
        );
        expect(
            wrapper.get('[data-test="detail-group"] > div').classes(),
        ).toEqual(
            expect.arrayContaining(['flex', '@2xl:grid', '@2xl:grid-cols-2']),
        );
        wrapper.unmount();
    });

    it('keeps every card in its column when one opens, so the other column does not move', async () => {
        const cards = [1, 2, 3, 4, 5].map((n) =>
            card({ id: `c${n}`, title: `Card ${n}`, group: 'Hardware' }),
        );
        const wrapper = render(cards);
        const columns = () =>
            wrapper
                .findAll('[data-test="detail-column"]')
                .map((column) =>
                    column
                        .findAllComponents(DetailCardItem)
                        .map((item) => (item.props('card') as DetailCard).id),
                );

        expect(columns()).toEqual([
            ['c1', 'c3', 'c5'],
            ['c2', 'c4'],
        ]);
        expect(
            wrapper
                .findAllComponents(DetailCardItem)
                .map((item) => item.attributes('style')),
        ).toEqual([
            'order: 0;',
            'order: 2;',
            'order: 4;',
            'order: 1;',
            'order: 3;',
        ]);

        await wrapper
            .get('[data-test="detail-column"] button')
            .trigger('click');

        expect(columns()).toEqual([
            ['c1', 'c3', 'c5'],
            ['c2', 'c4'],
        ]);
        wrapper.unmount();
    });

    it('starts the cards that need attention open and the rest closed', () => {
        const wrapper = render(tabbed());
        const states = Object.fromEntries(
            wrapper
                .findAll('[data-test="detail-body"]')
                .map((body, index) => [
                    tabbed()[index].id,
                    body.attributes('data-state') === 'closed'
                        ? 'closed'
                        : 'open',
                ]),
        );

        expect(states).toEqual({
            security: 'closed',
            updates: 'closed',
            stability: 'closed',
            storage: 'open',
            'storage-breakdown': 'open',
            age: 'open',
        });
        wrapper.unmount();
    });
});
