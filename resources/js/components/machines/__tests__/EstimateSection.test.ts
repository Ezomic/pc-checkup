import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import EstimateSection from '@/components/machines/EstimateSection.vue';
import type { Estimate, PriceItem } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const writeText = vi.fn();

const items: PriceItem[] = [
    { id: 1, name: 'New battery', price_cents: 8900, unit: null },
    { id: 2, name: 'Cleaning', price_cents: 4500, unit: 'per hour' },
    { id: 3, name: '<b>Bold</b>', price_cents: 1, unit: null },
];

const repair: Estimate = {
    verdict: 'Repair',
    reasons: ['The battery is worn out A new battery is cheaper'],
};

function mountSection(
    props: Partial<{ items: PriceItem[]; estimate: Estimate | null }> = {},
) {
    return mount(EstimateSection, {
        props: {
            machineTitle: 'MacBook Air',
            items,
            estimate: repair,
            vatPercent: 21,
            ...props,
        },
    });
}

describe('EstimateSection', () => {
    beforeEach(() => {
        writeText.mockReset();
        Object.assign(navigator, { clipboard: { writeText } });
    });

    it('ticks the items that fit the reasons and totals them with vat', () => {
        const wrapper = mountSection();

        expect(
            wrapper.get<HTMLInputElement>('[data-test="item-1"]').element
                .checked,
        ).toBe(true);
        expect(
            wrapper.get<HTMLInputElement>('[data-test="item-2"]').element
                .checked,
        ).toBe(false);
        expect(wrapper.get('[data-test="estimate-total"]').text()).toBe(
            'Total: EUR 107,69 incl. VAT',
        );
    });

    it('switches the total to excluding vat', async () => {
        const wrapper = mountSection();

        await wrapper.get('[data-test="include-vat"]').setValue(false);

        expect(wrapper.get('[data-test="estimate-total"]').text()).toBe(
            'Total: EUR 89,00 excl. VAT',
        );
        expect(wrapper.get('[data-test="quote-text"]').text()).toContain(
            'Total: EUR 89,00 excl. VAT',
        );
    });

    it('lets the owner tick and untick items', async () => {
        const wrapper = mountSection();

        await wrapper.get('[data-test="item-2"]').setValue(true);
        await wrapper.get('[data-test="item-1"]').setValue(false);

        expect(wrapper.get('[data-test="estimate-total"]').text()).toContain(
            '54,45',
        );
    });

    it('copies the plain text quote', async () => {
        const wrapper = mountSection();

        await wrapper.get('[data-test="copy-quote"]').trigger('click');

        expect(writeText).toHaveBeenCalledWith(
            [
                'Quote for MacBook Air:',
                '- New battery: EUR 89,00',
                'Subtotal excl. VAT: EUR 89,00',
                'VAT 21%: EUR 18,69',
                'Total: EUR 107,69 incl. VAT',
            ].join('\n'),
        );
        expect(wrapper.get('[data-test="copy-quote"]').text()).toBe('Copied');
    });

    it('disables copying while nothing is ticked', () => {
        const wrapper = mountSection({ estimate: null });

        expect(
            wrapper.get('[data-test="copy-quote"]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.find('[data-test="quote-text"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="estimate-total"]').text()).toBe(
            'Total: EUR 0,00 incl. VAT',
        );
    });

    it('renders item names as text, never as markup', () => {
        const wrapper = mountSection();

        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.get('[data-test="estimate-items"]').text()).toContain(
            '<b>Bold</b>',
        );
    });

    it('points to the price list when there are no items', () => {
        const wrapper = mountSection({ items: [] });

        expect(wrapper.get('[data-test="estimate-empty"]').text()).toContain(
            'You have no price items yet.',
        );
        expect(wrapper.find('[data-test="copy-quote"]').exists()).toBe(false);
    });
});
