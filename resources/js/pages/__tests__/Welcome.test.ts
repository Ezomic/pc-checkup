import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Welcome from '@/pages/Welcome.vue';

const state = vi.hoisted(() => ({ user: null as { id: number } | null }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({
        props: {
            name: 'PC Checkup',
            auth: { user: state.user },
            scriptVersions: { windows: 'aaaa1111', mac: 'bbbb2222' },
        },
    }),
}));

vi.mock('@/routes', () => ({
    login: () => '/login',
    dashboard: () => '/dashboard',
}));
vi.mock('@/routes/scripts', () => ({
    download: (script: string) => ({ url: `/scripts/${script}` }),
}));

describe('Welcome', () => {
    beforeEach(() => {
        state.user = null;
    });

    it('offers the app instead of logging in when signed in', () => {
        state.user = { id: 1 };

        const hrefs = mount(Welcome)
            .findAll('a')
            .map((a) => a.attributes('href'));

        expect(hrefs[0]).toBe('/dashboard');
    });

    it('names the app rather than the framework', () => {
        const wrapper = mount(Welcome);

        expect(wrapper.text()).toContain('PC Checkup');
        expect(wrapper.text()).not.toContain('Laravel');
    });

    it('offers logging in and both script downloads', () => {
        const wrapper = mount(Welcome);

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toEqual(['/login', '/scripts/windows', '/scripts/mac']);
    });

    it('shows the matching run command for the chosen platform', async () => {
        const wrapper = mount(Welcome);
        const command = () => wrapper.get('[data-test="run-command"]').text();

        expect(command()).toBe(
            'powershell -ExecutionPolicy Bypass -File windows-checkup-aaaa1111.ps1',
        );

        await wrapper.get('[data-test="download-mac"]').trigger('click');

        expect(command()).toBe('bash mac-checkup-bbbb2222.sh');
        expect(wrapper.get('[data-test="run-hint"]').text()).toContain(
            'mac-checkup-bbbb2222.sh',
        );
        expect(wrapper.get('[data-test="run-hint"]').text()).toContain(
            'Delete older copies',
        );
    });
});
