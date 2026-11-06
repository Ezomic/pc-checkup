import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WarrantySection from '@/components/machines/WarrantySection.vue';
import type { Warranty } from '@/types';

const writeText = vi.fn();

const apple: Warranty = {
    maker: 'Apple',
    url: 'https://checkcoverage.apple.com/',
    serial: 'C02TESTSERIAL',
    serial_in_url: false,
};

describe('WarrantySection', () => {
    beforeEach(() => {
        writeText.mockReset();
        Object.assign(navigator, { clipboard: { writeText } });
    });

    it('opens the maker page in a new tab without a referrer', () => {
        const link = mount(WarrantySection, {
            props: { warranty: apple },
        }).get('[data-test="check-warranty"]');

        expect(link.attributes('href')).toBe(
            'https://checkcoverage.apple.com/',
        );
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
    });

    it('shows the serial and tells the owner to paste it when the link cannot carry it', () => {
        const wrapper = mount(WarrantySection, { props: { warranty: apple } });

        expect(wrapper.get('[data-test="warranty-serial"]').text()).toBe(
            'C02TESTSERIAL',
        );
        expect(wrapper.find('[data-test="paste-hint"]').exists()).toBe(true);
    });

    it('does not ask for a paste when the serial is in the link', () => {
        const wrapper = mount(WarrantySection, {
            props: {
                warranty: {
                    ...apple,
                    maker: 'Dell',
                    serial_in_url: true,
                },
            },
        });

        expect(wrapper.find('[data-test="paste-hint"]').exists()).toBe(false);
    });

    it('copies the serial', async () => {
        const wrapper = mount(WarrantySection, { props: { warranty: apple } });

        await wrapper.get('[data-test="copy-serial"]').trigger('click');

        expect(writeText).toHaveBeenCalledWith('C02TESTSERIAL');
    });
});
