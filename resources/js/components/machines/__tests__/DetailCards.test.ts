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

    it('renders the security and stability cards side by side with their tones', () => {
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        id: 'security',
                        title: 'Security summary',
                        rows: [
                            { label: 'FileVault', value: 'Off', tone: 'bad' },
                            {
                                label: 'Find My Mac',
                                value: 'Not checked',
                                tone: 'neutral',
                            },
                            { label: 'Gatekeeper', value: 'On', tone: 'good' },
                        ],
                    }),
                    card({
                        id: 'stability',
                        title: 'Stability',
                        rows: [
                            {
                                label: 'Kernel panics',
                                value: 'None found',
                                tone: 'good',
                            },
                        ],
                    }),
                ],
            },
        });

        const security = wrapper.get('[data-test="detail-card-security"]');
        const tones = security
            .findAll('dd')
            .map((row) => row.attributes('data-tone'));

        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual([
            'Security summary',
            'Stability',
        ]);
        expect(tones).toEqual(['bad', 'neutral', 'good']);
        expect(security.get('dd[data-tone="neutral"]').classes()).not.toContain(
            'text-green-700',
        );
        expect(wrapper.text()).not.toMatch(/undefined|NaN/);
    });

    it('puts a long value under its label, with the tone on the label', () => {
        const advice =
            'Close programs and browser tabs you are not using. If it keeps happening, adding more memory would help.';
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        id: 'slowness',
                        title: 'Why is it slow?',
                        rows: [
                            {
                                label: 'Verdict',
                                value: 'Probably slow because of 1 thing',
                                tone: 'bad',
                            },
                            {
                                label: 'Memory is almost full',
                                value: advice,
                                tone: 'bad',
                            },
                        ],
                    }),
                ],
            },
        });
        const labels = wrapper.findAll('dt');
        const values = wrapper.findAll('dd');

        expect(labels[0].classes()).not.toContain('col-span-2');
        expect(values[0].classes()).toContain('text-red-700');
        expect(labels[1].classes()).toContain('col-span-2');
        expect(labels[1].classes()).toContain('text-red-700');
        expect(labels[1].attributes('data-tone')).toBe('bad');
        expect(values[1].classes()).toContain('col-span-2');
        expect(values[1].classes()).not.toContain('text-red-700');
        expect(values[1].text()).toBe(advice);
    });

    it('stacks a row whose label and value are long together, and not one that is short', () => {
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        id: 'verdict',
                        rows: [
                            { label: 'Verdict', value: 'Keep', tone: 'good' },
                            {
                                label: 'Only 2 GB of memory',
                                value: 'Adding memory, if this model allows it, is a cheap fix.',
                                tone: 'bad',
                            },
                        ],
                    }),
                ],
            },
        });
        const labels = wrapper.findAll('dt');

        expect(labels[0].classes()).not.toContain('col-span-2');
        expect(labels[1].classes()).toContain('col-span-2');
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

    it('draws a bar per item with its width, size and detail', () => {
        const wrapper = mount(DetailCards, {
            props: {
                cards: [
                    card({
                        id: 'storage-breakdown',
                        title: 'Storage breakdown',
                        rows: [],
                        bars: [
                            {
                                title: 'Your folders',
                                items: [
                                    {
                                        label: 'Library',
                                        value: '20 GB',
                                        detail: '25% of used space',
                                        share: 100,
                                    },
                                    {
                                        label: 'Downloads',
                                        value: '1.5 GB',
                                        detail: null,
                                        share: 8,
                                    },
                                ],
                            },
                        ],
                        notes: ['The scan stopped at its time limit.'],
                    }),
                ],
            },
        });
        const bars = wrapper.findAll('[data-test="detail-bar"]');

        expect(wrapper.get('[data-test="detail-bars"] h3').text()).toBe(
            'Your folders',
        );
        expect(bars).toHaveLength(2);
        expect(bars[0].attributes('style')).toContain('width: 100%');
        expect(bars[1].attributes('style')).toContain('width: 8%');
        expect(wrapper.text()).toContain('20 GB');
        expect(wrapper.text()).toContain('25% of used space');
        expect(wrapper.findAll('dl')).toHaveLength(0);
        expect(wrapper.get('[data-test="detail-note"]').text()).toBe(
            'The scan stopped at its time limit.',
        );
    });

    it('draws no bars or notes for a card without them', () => {
        const wrapper = mount(DetailCards, { props: { cards: [card()] } });

        expect(wrapper.find('[data-test="detail-bars"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="detail-note"]').exists()).toBe(false);
    });
});
