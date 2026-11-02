import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ShareLinkSection from '@/components/clients/ShareLinkSection.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), delete: vi.fn() },
}));

const url = 'https://app.test/c/abc123';

function mountSection(shareUrl: string | null) {
    return mount(ShareLinkSection, { props: { clientId: 7, shareUrl } });
}

describe('ShareLinkSection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('offers to create a link when there is none', async () => {
        const wrapper = mountSection(null);

        expect(wrapper.text()).toContain('Off');
        expect(wrapper.find('input').exists()).toBe(false);

        await wrapper.get('[data-test="create-link"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/clients/7/share-link',
            {},
            { preserveScroll: true },
        );
    });

    it('shows the link and copies it', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        const wrapper = mountSection(url);

        expect(wrapper.text()).toContain('Active');
        expect(wrapper.get('input').element.value).toBe(url);

        await wrapper.get('[data-test="copy-link"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith(url);
        expect(wrapper.get('[data-test="copy-link"]').text()).toBe('Copied');
    });

    it('selects the field when the clipboard is unavailable', async () => {
        vi.stubGlobal('navigator', {
            clipboard: {
                writeText: vi.fn().mockRejectedValue(new Error('no')),
            },
        });
        const wrapper = mountSection(url);
        const select = vi.spyOn(wrapper.get('input').element, 'select');

        await wrapper.get('[data-test="copy-link"]').trigger('click');
        await flushPromises();

        expect(select).toHaveBeenCalled();
        expect(wrapper.get('[data-test="copy-link"]').text()).toBe('Copy');
    });

    it('asks before making a new link', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = mountSection(url);

        await wrapper.get('[data-test="regenerate-link"]').trigger('click');
        expect(router.post).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await wrapper.get('[data-test="regenerate-link"]').trigger('click');
        expect(router.post).toHaveBeenCalledOnce();
    });

    it('asks before revoking the link', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = mountSection(url);

        await wrapper.get('[data-test="revoke-link"]').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await wrapper.get('[data-test="revoke-link"]').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/clients/7/share-link', {
            preserveScroll: true,
        });
    });
});
