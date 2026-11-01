import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Welcome from '@/pages/Welcome.vue';

const state = vi.hoisted(() => ({ workflowEnabled: false }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({
        props: {
            name: 'Web Template',
            workflow: { enabled: state.workflowEnabled },
        },
    }),
}));

vi.mock('@/routes', () => ({ login: () => '/login' }));

describe('Welcome', () => {
    beforeEach(() => {
        state.workflowEnabled = false;
    });

    it('names the app rather than the framework', () => {
        const wrapper = mount(Welcome);

        expect(wrapper.find('h1').text()).toBe('Web Template');
        expect(wrapper.text()).not.toContain('Laravel');
    });

    // Users are created by hand, so no sign-up route exists to advertise.
    it('only offers logging in', () => {
        const wrapper = mount(Welcome);

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toEqual(['/login']);
    });
});
