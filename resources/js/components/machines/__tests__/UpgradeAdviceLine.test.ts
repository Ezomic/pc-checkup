import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UpgradeAdviceLine from '@/components/machines/UpgradeAdviceLine.vue';
import type { UpgradeAdvice } from '@/types';

function render(advice: UpgradeAdvice) {
    return mount(UpgradeAdviceLine, { props: { advice } });
}

const text =
    'Swap the 466 GB spinning hard drive for a SATA SSD: the biggest speed gain for a machine of about 6 years old.';

describe('UpgradeAdviceLine', () => {
    it('shows the advice and the matching price from the price list', () => {
        const wrapper = render({
            text,
            price: { name: 'SSD 500 GB', price_cents: 6900, unit: 'stuk' },
        });

        expect(wrapper.get('[data-test="upgrade-advice-text"]').text()).toBe(
            text,
        );
        expect(wrapper.get('[data-test="upgrade-advice-price"]').text()).toBe(
            'From your price list: SSD 500 GB (stuk), EUR 69,00 excl. VAT',
        );
    });

    it('leaves the unit out when the item has none, and groups thousands', () => {
        const wrapper = render({
            text,
            price: { name: 'Big SSD', price_cents: 123456, unit: null },
        });

        expect(wrapper.get('[data-test="upgrade-advice-price"]').text()).toBe(
            'From your price list: Big SSD, EUR 1.234,56 excl. VAT',
        );
    });

    it('shows no price line when no item in the price list matched', () => {
        const wrapper = render({ text, price: null });

        expect(wrapper.get('[data-test="upgrade-advice-text"]').text()).toBe(
            text,
        );
        expect(
            wrapper.find('[data-test="upgrade-advice-price"]').exists(),
        ).toBe(false);
    });

    it('renders markup in a name as text', () => {
        const wrapper = render({
            text: '<b>bold</b>',
            price: { name: '<img src=x>', price_cents: 1, unit: null },
        });

        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x>');
    });

    it('stays off the printed page, like the price list', () => {
        expect(
            render({ text, price: null })
                .get('[data-test="upgrade-advice"]')
                .classes(),
        ).toContain('print:hidden');
    });
});
