import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DetailCards from '@/components/machines/DetailCards.vue';
import type { DetailCard } from '@/types';

function card(overrides: Partial<DetailCard> = {}): DetailCard {
    return {
        id: 'battery',
        title: 'Battery',
        rows: [
            {
                label: 'Charge cycles',
                value: '391',
                tone: 'neutral',
            },
            { label: 'Condition', value: 'Normal', tone: 'good' },
            {
                label: 'Maximum capacity',
                value: 'Not reported',
                tone: 'neutral',
            },
        ],
        list: null,
        ...overrides,
    };
}

describe('DetailCards', () => {
    it('renders nothing when there are no cards', () => {
        const wrapper = mount(DetailCards, { props: { cards: [] } });

        expect(wrapper.find('[data-test="detail-cards"]').exists()).toBe(false);
    });

    it('renders a heading and a label and value for every row', () => {
        const wrapper = mount(DetailCards, { props: { cards: [card()] } });
        const text = wrapper.get('[data-test="detail-card-battery"]').text();

        expect(wrapper.get('h2').text()).toBe('Battery');
        expect(text).toContain('Charge cycles');
        expect(text).toContain('391');
        expect(text).toContain('Maximum capacity');
        expect(text).toContain('Not reported');
        expect(wrapper.findAll('dt')).toHaveLength(3);
        expect(wrapper.findAll('dd')).toHaveLength(3);
    });

    it('colours a good value green and a bad value red, and leaves the rest plain', () => {
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        rows: [
                            { label: 'A', value: 'fine', tone: 'good' },
                            { label: 'B', value: 'worn', tone: 'bad' },
                            { label: 'C', value: 'plain', tone: 'neutral' },
                        ],
                    }),
                ],
            },
        });
        const values = wrapper.findAll('dd');

        expect(values[0].classes()).toContain('text-green-700');
        expect(values[1].classes()).toContain('text-red-700');
        expect(values[2].classes().join(' ')).not.toContain('text-green');
        expect(values[2].classes().join(' ')).not.toContain('text-red');
    });

    it('shows the folder list and hides it on paper', () => {
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        id: 'storage',
                        title: 'Storage',
                        list: {
                            title: 'Biggest folders',
                            items: [
                                {
                                    label: 'Movies',
                                    value: '21 GB',
                                    tone: 'neutral',
                                },
                            ],
                        },
                    }),
                ],
            },
        });
        const list = wrapper.get('[data-test="detail-list"]');

        expect(list.text()).toContain('Biggest folders');
        expect(list.text()).toContain('Movies');
        expect(list.text()).toContain('21 GB');
        expect(list.classes()).toContain('print:hidden');
    });

    it('renders values as text, never as markup', () => {
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        rows: [
                            {
                                label: 'Model',
                                value: '<img src=x onerror=alert(1)>',
                                tone: 'neutral',
                            },
                        ],
                    }),
                ],
            },
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
    });

    it('skips the row list for a card that only has a folder list', () => {
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        rows: [],
                        list: { title: 'Biggest folders', items: [] },
                    }),
                ],
            },
        });

        expect(wrapper.find('dl').exists()).toBe(false);
    });
});
