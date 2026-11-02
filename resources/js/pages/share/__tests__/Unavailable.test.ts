import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Unavailable from '@/pages/share/Unavailable.vue';

vi.mock('@inertiajs/vue3', () => ({
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
