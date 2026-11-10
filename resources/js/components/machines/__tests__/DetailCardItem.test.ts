import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DetailCardItem from '@/components/machines/DetailCardItem.vue';
import type { DetailBarGroup, DetailCard } from '@/types';

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
        group: 'Hardware',
        status: 'fine',
        ...overrides,
    };
}

function render(overrides: Partial<DetailCard> = {}) {
    return mount(DetailCardItem, { props: { card: card(overrides) } });
}

function body(wrapper: ReturnType<typeof render>) {
    return wrapper.get('[data-test="detail-body"]');
}

function lists(count: number): DetailBarGroup[] {
    return Array.from({ length: count }, (_, index) => ({
        title: `List ${index + 1}`,
        items: [
            {
                label: `Folder ${index + 1}`,
                value: '1 GB',
                detail: null,
                share: 100,
            },
        ],
    }));
}

describe('DetailCardItem', () => {
    describe('folding', () => {
        it('starts open when the card needs attention', () => {
            const wrapper = render({
                status: 'attention',
                rows: [{ label: 'FileVault', value: 'Off', tone: 'bad' }],
            });

            expect(body(wrapper).attributes('data-state')).not.toBe('closed');
            expect(
                wrapper
                    .get('[data-test="detail-toggle"]')
                    .attributes('aria-expanded'),
            ).toBe('true');
            expect(wrapper.find('[data-test="detail-line"]').exists()).toBe(
                false,
            );
        });

        it('starts closed when the card is fine or only informs, with its summary line showing', () => {
            const fine = render({ status: 'fine' });
            const neutral = render({ status: 'neutral' });

            expect(body(fine).attributes('data-state')).toBe('closed');
            expect(fine.get('[data-test="detail-line"]').text()).toBe(
                'All fine',
            );
            expect(body(neutral).attributes('data-state')).toBe('closed');
            expect(neutral.get('[data-test="detail-line"]').text()).toBe(
                'Charge cycles: 391',
            );
        });

        it('keeps the folded content in the page, hidden on screen and shown on paper', () => {
            const classes = body(render()).classes();

            expect(classes).toContain('data-[state=closed]:hidden');
            expect(classes).toContain('print:data-[state=closed]:flex');
            expect(body(render()).text()).toContain('Charge cycles');
        });

        it('opens and closes on a click of the title', async () => {
            const wrapper = render();
            const toggle = wrapper.get('[data-test="detail-toggle"]');

            await toggle.trigger('click');

            expect(body(wrapper).attributes('data-state')).toBe('open');
            expect(toggle.attributes('aria-expanded')).toBe('true');
            expect(wrapper.find('[data-test="detail-line"]').exists()).toBe(
                false,
            );

            await toggle.trigger('click');

            expect(body(wrapper).attributes('data-state')).toBe('closed');
            expect(wrapper.get('[data-test="detail-line"]').text()).toBe(
                'All fine',
            );
        });

        it('colours the status dot and names the status for screen readers', () => {
            const attention = render({ status: 'attention' });
            const fine = render({ status: 'fine' });
            const neutral = render({ status: 'neutral' });

            expect(
                attention.get('[data-test="detail-dot"]').classes(),
            ).toContain('bg-red-600');
            expect(fine.get('[data-test="detail-dot"]').classes()).toContain(
                'bg-green-600',
            );
            expect(
                neutral.get('[data-test="detail-dot"]').classes().join(' '),
            ).not.toMatch(/red|green/);
            expect(attention.get('h3').text()).toContain(
                'Battery, needs attention.',
            );
            expect(fine.get('h3').text()).toContain('Battery, fine.');
            expect(neutral.get('h3').text()).toContain(
                'Battery, for information.',
            );
        });

        it('puts the title in a heading with the toggle inside it', () => {
            const wrapper = render();

            expect(
                wrapper.get('h3').find('[data-test="detail-toggle"]').exists(),
            ).toBe(true);
            expect(wrapper.get('h3').text()).toContain('Battery');
        });
    });

    describe('rows', () => {
        it('renders a label and value for every row', () => {
            const wrapper = render();
            const text = body(wrapper).text();

            expect(text).toContain('Charge cycles');
            expect(text).toContain('391');
            expect(text).toContain('Maximum capacity');
            expect(text).toContain('Not reported');
            expect(wrapper.findAll('dt')).toHaveLength(3);
            expect(wrapper.findAll('dd')).toHaveLength(3);
        });

        it('colours a good value green and a bad value red, and leaves the rest plain', () => {
            const wrapper = render({
                rows: [
                    { label: 'A', value: 'fine', tone: 'good' },
                    { label: 'B', value: 'worn', tone: 'bad' },
                    { label: 'C', value: 'plain', tone: 'neutral' },
                ],
            });
            const values = wrapper.findAll('dd');

            expect(values[0].classes()).toContain('text-green-700');
            expect(values[1].classes()).toContain('text-red-700');
            expect(values[2].classes().join(' ')).not.toMatch(
                /text-green|text-red/,
            );
            expect(
                values.map((value) => value.attributes('data-tone')),
            ).toEqual(['good', 'bad', 'neutral']);
        });

        it('gives the value column a minimum width and breaks at words, never letter by letter', () => {
            const wrapper = render();

            expect(wrapper.get('dl').classes()).toContain(
                'grid-cols-[minmax(0,1fr)_minmax(40%,auto)]',
            );

            for (const value of wrapper.findAll('dd')) {
                expect(value.classes()).toContain('break-words');
                expect(value.classes()).not.toContain('min-w-0');
                expect(value.classes().join(' ')).not.toMatch(
                    /break-all|wrap-anywhere/,
                );
            }
        });

        it('puts a long value under its label, with the tone on the label', () => {
            const advice =
                'Close programs and browser tabs you are not using. If it keeps happening, adding more memory would help.';
            const wrapper = render({
                status: 'attention',
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

        it('renders values as text, never as markup', () => {
            const wrapper = render({
                rows: [
                    {
                        label: 'Model',
                        value: '<img src=x onerror=alert(1)>',
                        tone: 'neutral',
                    },
                ],
                status: 'neutral',
            });

            expect(wrapper.find('img').exists()).toBe(false);
            expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
        });

        it('skips the row list for a card that only has a folder list', () => {
            const wrapper = render({
                rows: [],
                list: { title: 'Biggest folders', items: [] },
            });

            expect(wrapper.find('dl').exists()).toBe(false);
        });

        it('shows the folder list and hides it on paper', () => {
            const wrapper = render({
                id: 'storage',
                title: 'Storage',
                list: {
                    title: 'Biggest folders',
                    items: [
                        { label: 'Movies', value: '21 GB', tone: 'neutral' },
                    ],
                },
            });
            const list = wrapper.get('[data-test="detail-list"]');

            expect(list.text()).toContain('Biggest folders');
            expect(list.text()).toContain('Movies');
            expect(list.text()).toContain('21 GB');
            expect(list.classes()).toContain('print:hidden');
        });

        it('shows the summary sentence in the open card, and none without one', () => {
            const wrapper = render({
                summary: 'The biggest folders found are Users (50 GB).',
            });

            expect(wrapper.get('[data-test="detail-summary"]').text()).toBe(
                'The biggest folders found are Users (50 GB).',
            );
            expect(
                render({ summary: null })
                    .find('[data-test="detail-summary"]')
                    .exists(),
            ).toBe(false);
        });
    });

    describe('bars', () => {
        it('draws a bar per item with its width, size and detail', () => {
            const wrapper = render({
                id: 'storage-breakdown',
                title: 'Storage breakdown',
                rows: [],
                status: 'neutral',
                bars: [
                    {
                        title: 'Biggest folders on the drive',
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
            });
            const bars = wrapper.findAll('[data-test="detail-bar"]');

            expect(wrapper.get('[data-test="detail-bars"] h4').text()).toBe(
                'Biggest folders on the drive',
            );
            expect(bars).toHaveLength(2);
            expect(bars[0].attributes('style')).toContain('width: 100%');
            expect(bars[1].attributes('style')).toContain('width: 8%');
            expect(wrapper.text()).toContain('25% of used space');
            expect(wrapper.findAll('dl')).toHaveLength(0);
        });

        it('draws a labelled nested list under a folder that has one, with its own bars', () => {
            const wrapper = render({
                rows: [],
                bars: [
                    {
                        title: 'Biggest folders in your user folder',
                        items: [
                            {
                                label: 'Library',
                                value: '39 GB',
                                detail: '49% of used space',
                                share: 100,
                                inside: {
                                    title: 'Inside Library',
                                    items: [
                                        {
                                            label: 'Application Support',
                                            value: '30 GB',
                                            detail: '77% of Library',
                                            share: 100,
                                        },
                                        {
                                            label: 'Caches',
                                            value: '6 GB',
                                            detail: '15% of Library',
                                            share: 20,
                                        },
                                    ],
                                },
                            },
                        ],
                    },
                ],
            });
            const inside = wrapper.findAll('[data-test="detail-inside"]');

            expect(inside).toHaveLength(1);
            expect(inside[0].get('h5').text()).toBe('Inside Library');
            expect(inside[0].text()).toContain('77% of Library');
            expect(
                inside[0]
                    .findAll('[data-test="detail-bar"]')[1]
                    .attributes('style'),
            ).toContain('width: 20%');
        });

        it('shows a folder name as text, never as markup', () => {
            const name = '<img src=x onerror=alert(1)>';
            const wrapper = render({
                rows: [],
                bars: [
                    {
                        title: 'Biggest folders in your user folder',
                        items: [
                            {
                                label: name,
                                value: '1 GB',
                                detail: null,
                                share: 100,
                            },
                        ],
                    },
                ],
            });

            expect(wrapper.find('img').exists()).toBe(false);
            expect(wrapper.text()).toContain(name);
        });

        it('draws two bars that share a label, such as the same file name in two folders', () => {
            const wrapper = render({
                rows: [],
                bars: [
                    {
                        title: 'Biggest files',
                        items: [
                            {
                                label: 'video.mp4',
                                value: '4 GB',
                                detail: 'Videos, in Movies',
                                share: 100,
                            },
                            {
                                label: 'video.mp4',
                                value: '2 GB',
                                detail: 'Videos, in Downloads',
                                share: 50,
                            },
                        ],
                    },
                ],
            });

            expect(wrapper.findAll('[data-test="detail-bar"]')).toHaveLength(2);
            expect(wrapper.text()).toContain('Videos, in Downloads');
        });

        it('shows the first two lists and puts the rest behind show more', async () => {
            const wrapper = render({ rows: [], bars: lists(5) });
            const more = wrapper.get('[data-test="detail-more"]');
            const button = wrapper.get('[data-test="detail-show-more"]');

            expect(
                wrapper
                    .findAll('[data-test="detail-bars"]')
                    .slice(0, 2)
                    .map((group) => group.get('h4').text()),
            ).toEqual(['List 1', 'List 2']);
            expect(more.text()).toContain('List 3');
            expect(more.text()).toContain('List 5');
            expect(more.classes()).toContain('hidden');
            expect(more.classes()).toContain('print:flex');
            expect(button.text()).toBe('Show more (3 more lists)');
            expect(button.attributes('aria-expanded')).toBe('false');
            expect(button.attributes('aria-controls')).toBe(
                more.attributes('id'),
            );
            expect(button.classes()).toContain('print:hidden');

            await button.trigger('click');

            expect(more.classes()).not.toContain('hidden');
            expect(button.text()).toBe('Show less');
            expect(button.attributes('aria-expanded')).toBe('true');

            await button.trigger('click');

            expect(more.classes()).toContain('hidden');
        });

        it('says one more list in the singular', () => {
            expect(
                render({ rows: [], bars: lists(3) })
                    .get('[data-test="detail-show-more"]')
                    .text(),
            ).toBe('Show more (1 more list)');
        });

        it('has no show more for two lists or fewer', () => {
            const wrapper = render({ rows: [], bars: lists(2) });

            expect(wrapper.findAll('[data-test="detail-bars"]')).toHaveLength(
                2,
            );
            expect(
                wrapper.find('[data-test="detail-show-more"]').exists(),
            ).toBe(false);
            expect(wrapper.find('[data-test="detail-more"]').exists()).toBe(
                false,
            );
        });
    });

    describe('notes', () => {
        it('keeps the notes behind about this card, and prints them', async () => {
            const wrapper = render({
                notes: [
                    'The scan stopped at its time limit.',
                    'Only you see this.',
                ],
            });
            const notes = wrapper.get('[data-test="detail-notes"]');
            const button = wrapper.get('[data-test="detail-about"]');

            expect(button.text()).toBe('About this card');
            expect(button.attributes('aria-expanded')).toBe('false');
            expect(button.attributes('aria-controls')).toBe(
                notes.attributes('id'),
            );
            expect(button.classes()).toContain('print:hidden');
            expect(notes.classes()).toContain('hidden');
            expect(notes.classes()).toContain('print:flex');
            expect(
                wrapper
                    .findAll('[data-test="detail-note"]')
                    .map((note) => note.text()),
            ).toEqual([
                'The scan stopped at its time limit.',
                'Only you see this.',
            ]);

            await button.trigger('click');

            expect(notes.classes()).not.toContain('hidden');
            expect(button.text()).toBe('Hide notes');
            expect(button.attributes('aria-expanded')).toBe('true');

            await button.trigger('click');

            expect(notes.classes()).toContain('hidden');
        });

        it('draws no notes button for a card without notes', () => {
            const wrapper = render({ notes: [] });

            expect(wrapper.find('[data-test="detail-about"]').exists()).toBe(
                false,
            );
            expect(render().find('[data-test="detail-note"]').exists()).toBe(
                false,
            );
        });
    });

    it('gives every card its own ids for the controls it holds', () => {
        const one = render({
            id: 'storage-breakdown',
            bars: lists(3),
            notes: ['A'],
        });
        const two = render({ id: 'reclaimable', bars: lists(3), notes: ['A'] });

        expect(one.get('[data-test="detail-more"]').attributes('id')).not.toBe(
            two.get('[data-test="detail-more"]').attributes('id'),
        );
        expect(one.get('[data-test="detail-notes"]').attributes('id')).not.toBe(
            two.get('[data-test="detail-notes"]').attributes('id'),
        );
    });
});
