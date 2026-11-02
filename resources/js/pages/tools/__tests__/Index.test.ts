import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Tools from '@/pages/tools/Index.vue';
import type { RustDeskTool } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
}));

vi.mock('@/routes/tools', () => ({
    index: () => '/tools',
}));
vi.mock('@/routes/tools/rustdesk', () => ({
    download: () => ({ url: '/tools/rustdesk/windows' }),
}));

const ready: RustDeskTool = {
    configured: true,
    temporary_url: 'https://app.test/tools/rustdesk/windows?signature=abc',
    link_minutes: 60,
};

describe('Tools page', () => {
    it('offers the download and the temporary link when configured', () => {
        const wrapper = mount(Tools, { props: { rustdesk: ready } });

        expect(wrapper.get('[data-test=download]').attributes('href')).toBe(
            '/tools/rustdesk/windows',
        );
        expect(
            (
                wrapper.get('[data-test=temporary-url]')
                    .element as HTMLInputElement
            ).value,
        ).toBe(ready.temporary_url);
        expect(wrapper.text()).toContain('60');
        expect(wrapper.find('[data-test=not-configured]').exists()).toBe(false);
    });

    it('never shows a real password in the example command', () => {
        const wrapper = mount(Tools, { props: { rustdesk: ready } });

        expect(wrapper.get('[data-test=run-command]').text()).toContain(
            "-Password 'choose-a-password'",
        );
    });

    it('explains what to set when it is not configured', () => {
        const wrapper = mount(Tools, {
            props: {
                rustdesk: {
                    configured: false,
                    temporary_url: null,
                    link_minutes: 60,
                },
            },
        });

        expect(wrapper.get('[data-test=not-configured]').text()).toContain(
            'RUSTDESK_HOST',
        );
        expect(wrapper.find('[data-test=download]').exists()).toBe(false);
    });

    it('copies the temporary link', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });

        const wrapper = mount(Tools, { props: { rustdesk: ready } });
        await wrapper.get('button').trigger('click');

        expect(writeText).toHaveBeenCalledWith(ready.temporary_url);
        await wrapper.vm.$nextTick();
        expect(wrapper.get('button').text()).toContain('Copied');
    });
});
