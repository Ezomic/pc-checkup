import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import WorthKeeping from '@/components/machines/WorthKeeping.vue';
import type { DetailTone, VerdictCard } from '@/types';

function verdict(tone: DetailTone, value = 'Replace'): VerdictCard {
    return {
        id: 'verdict',
        title: 'Worth keeping?',
        rows: [
            { label: 'Verdict', value, tone },
            {
                label: 'The operating system no longer gets security updates',
                value: 'It is time to replace it.',
                tone: 'bad',
            },
            {
                label: 'Good to know',
                value: 'A rule of thumb for you, not a guarantee.',
                tone: 'neutral',
            },
        ],
        list: null,
    };
}

describe('WorthKeeping', () => {
    it('shows the verdict large and the other rows as short lines', () => {
        const wrapper = mount(WorthKeeping, {
            props: { card: verdict('bad') },
        });

        expect(wrapper.get('h2').text()).toBe('Worth keeping?');
        expect(wrapper.get('[data-test="worth-keeping-verdict"]').text()).toBe(
            'Replace',
        );
        expect(
            wrapper
                .findAll('[data-test="worth-keeping-line"]')
                .map((line) => line.text().replace(/\s+/g, ' ')),
        ).toEqual([
            'The operating system no longer gets security updates. It is time to replace it.',
            'Good to know. A rule of thumb for you, not a guarantee.',
        ]);
    });

    it('colours the box by the tone of the verdict row', () => {
        const box = (tone: DetailTone) =>
            mount(WorthKeeping, { props: { card: verdict(tone) } })
                .get('[data-test="worth-keeping"]')
                .classes()
                .join(' ');

        expect(box('bad')).toContain('bg-red-50');
        expect(box('good')).toContain('bg-green-50');
        expect(box('neutral')).not.toMatch(/red|green/);
    });

    it('shows a verdict without other rows', () => {
        const card = { ...verdict('neutral', 'Not enough data to judge') };
        card.rows = card.rows.slice(0, 1);
        const wrapper = mount(WorthKeeping, { props: { card } });

        expect(wrapper.text()).toContain('Not enough data to judge');
        expect(wrapper.find('ul').exists()).toBe(false);
    });

    it('draws nothing for a verdict without rows', () => {
        const wrapper = mount(WorthKeeping, {
            props: { card: { ...verdict('bad'), rows: [] } },
        });

        expect(wrapper.find('[data-test="worth-keeping"]').exists()).toBe(
            false,
        );
    });

    it('renders the words as text, never as markup', () => {
        const wrapper = mount(WorthKeeping, {
            props: { card: verdict('bad', '<img src=x onerror=alert(1)>') },
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
    });
});
