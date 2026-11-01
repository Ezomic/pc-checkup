import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Welcome from '@/pages/Welcome.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { name: 'PC Checkup' } }),
}));

vi.mock('@/routes', () => ({ login: () => '/login' }));
vi.mock('@/routes/scripts', () => ({
    download: (script: string) => ({ url: `/scripts/${script}` }),
}));

describe('Welcome', () => {
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

        expect(command()).toContain('windows-checkup.ps1');

        await wrapper.get('[data-test="download-mac"]').trigger('click');

        expect(command()).toBe('bash mac-checkup.sh');
    });
});
