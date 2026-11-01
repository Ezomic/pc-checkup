import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ErrorPage from '@/pages/Error.vue';

const state = vi.hoisted(() => ({ user: null as { name: string } | null }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: { user: state.user } } }),
}));

// Wayfinder generates @/routes at build time, so the spec stubs it rather than
// depending on `npm run build` having run.
vi.mock('@/routes', () => ({
    dashboard: () => '/dashboard',
    login: () => '/login',
}));

describe('Error', () => {
    beforeEach(() => {
        state.user = null;
    });

    it.each([
        [403, 'Forbidden'],
        [404, 'Page not found'],
        [419, 'Page expired'],
        [500, 'Something went wrong'],
    ])('titles a %i with its own copy', (status, title) => {
        const wrapper = mount(ErrorPage, { props: { status } });

        expect(wrapper.text()).toContain(String(status));
        expect(wrapper.find('h1').text()).toBe(title);
    });

    it('falls back to generic copy for an unmapped status', () => {
        const wrapper = mount(ErrorPage, { props: { status: 502 } });

        expect(wrapper.find('h1').text()).toBe('Something went wrong');
        expect(wrapper.text()).toContain('502');
    });

    it('sends an anonymous visitor to login', () => {
        const wrapper = mount(ErrorPage, { props: { status: 404 } });

        expect(wrapper.get('a').attributes('href')).toBe('/login');
        expect(wrapper.text()).toContain('Go to login');
    });

    it('sends a signed-in user back to the dashboard', () => {
        state.user = { name: 'Robbin' };

        const wrapper = mount(ErrorPage, { props: { status: 404 } });

        expect(wrapper.get('a').attributes('href')).toBe('/dashboard');
        expect(wrapper.text()).toContain('Back to dashboard');
    });

    it('offers a reload only on an expired page, since that is the one a retry fixes', () => {
        expect(mount(ErrorPage, { props: { status: 419 } }).text()).toContain(
            'Reload the page',
        );
        expect(
            mount(ErrorPage, { props: { status: 404 } }).text(),
        ).not.toContain('Reload the page');
    });

    it('reloads the window when the reload button is pressed', async () => {
        const reload = vi.fn();

        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { reload },
        });

        const wrapper = mount(ErrorPage, { props: { status: 419 } });

        await wrapper.get('button').trigger('click');

        expect(reload).toHaveBeenCalledOnce();
    });
});
