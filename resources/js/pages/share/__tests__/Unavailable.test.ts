import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Unavailable from '@/pages/share/Unavailable.vue';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: { template: '<span />' },
}));

describe('share/Unavailable', () => {
    it('explains a missing link and points nowhere', () => {
        const wrapper = mount(Unavailable, { props: { status: 404 } });

        expect(wrapper.get('h1').text()).toBe('This link is not available');
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('uses generic copy for any other failure', () => {
        const wrapper = mount(Unavailable, { props: { status: 500 } });

        expect(wrapper.get('h1').text()).toBe('Something went wrong');
        expect(wrapper.find('a').exists()).toBe(false);
    });
});

describe('share/Unavailable in Dutch', () => {
    beforeEach(() => {
        page.props.locale = 'nl';
    });

    afterEach(() => {
        page.props.locale = 'en';
    });

    it('explains a missing link in Dutch', () => {
        const wrapper = mount(Unavailable, { props: { status: 404 } });

        expect(wrapper.get('h1').text()).toBe('Deze link is niet beschikbaar');
        expect(wrapper.text()).toContain('Vraag degene die hem je stuurde');
    });

    it('uses generic Dutch copy for any other failure', () => {
        const wrapper = mount(Unavailable, { props: { status: 500 } });

        expect(wrapper.get('h1').text()).toBe('Er ging iets mis');
        expect(wrapper.text()).toContain('Probeer het zo meteen opnieuw.');
    });

    it('falls back to English for a locale it does not know', () => {
        page.props.locale = 'fr';

        expect(
            mount(Unavailable, { props: { status: 404 } })
                .get('h1')
                .text(),
        ).toBe('This link is not available');
    });
});
