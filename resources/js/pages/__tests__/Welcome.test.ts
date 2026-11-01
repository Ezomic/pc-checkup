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

    it('offers registration in base mode', () => {
        const wrapper = mount(Welcome);

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toContain('/login');
        expect(hrefs).toContain('/register');
    });

    // ID provisions users in workflow mode, so a local sign-up route does not
    // exist and must not be advertised.
    it('hides registration in workflow mode', () => {
        state.workflowEnabled = true;

        const wrapper = mount(Welcome);

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toContain('/login');
        expect(hrefs).not.toContain('/register');
    });
});
