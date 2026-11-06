import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LanguageSwitch from '@/components/share/LanguageSwitch.vue';

const page = vi.hoisted(() => ({ props: { locale: 'nl' } }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

describe('LanguageSwitch', () => {
    afterEach(() => {
        page.props.locale = 'nl';
    });

    it('offers both languages as plain links that keep the page', () => {
        const wrapper = mount(LanguageSwitch);

        expect(wrapper.get('[data-test="lang-nl"]').attributes('href')).toBe(
            '?lang=nl',
        );
        expect(wrapper.get('[data-test="lang-en"]').attributes('href')).toBe(
            '?lang=en',
        );
        expect(wrapper.text()).toContain('Nederlands');
        expect(wrapper.text()).toContain('English');
    });

    it('marks the current language and hides itself from print', () => {
        const wrapper = mount(LanguageSwitch);

        expect(
            wrapper.get('[data-test="lang-nl"]').attributes('aria-current'),
        ).toBe('true');
        expect(
            wrapper.get('[data-test="lang-en"]').attributes('aria-current'),
        ).toBeUndefined();
        expect(wrapper.get('nav').classes()).toContain('print:hidden');
        expect(wrapper.get('nav').attributes('aria-label')).toBe('Taal');
    });

    it('follows the page locale', () => {
        page.props.locale = 'en';
        const wrapper = mount(LanguageSwitch);

        expect(
            wrapper.get('[data-test="lang-en"]').attributes('aria-current'),
        ).toBe('true');
        expect(wrapper.get('nav').attributes('aria-label')).toBe('Language');
    });
});
