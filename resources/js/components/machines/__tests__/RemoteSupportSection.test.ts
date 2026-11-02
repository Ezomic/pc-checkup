import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import RemoteSupportSection from '@/components/machines/RemoteSupportSection.vue';

const put = vi.fn();
let errors: Record<string, string> = {};

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, string>) =>
        reactive({ ...data, errors, processing: false, put }),
}));

describe('RemoteSupportSection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        errors = {};
    });

    it('explains where the id comes from when there is none', () => {
        const wrapper = mount(RemoteSupportSection, {
            props: { machineId: 4, rustdeskId: null },
        });

        expect(wrapper.text()).toContain(
            'No RustDesk ID yet. It is picked up from the next scan, or enter it here.',
        );
        expect(wrapper.find('[data-test="connect"]').exists()).toBe(false);
        expect(wrapper.get('input').element.value).toBe('');
    });

    it('shows the id and a Connect link that opens RustDesk', () => {
        const wrapper = mount(RemoteSupportSection, {
            props: { machineId: 4, rustdeskId: '123456789' },
        });

        expect(wrapper.get('[data-test="rustdesk-id"]').text()).toBe(
            '123456789',
        );
        expect(wrapper.get('[data-test="connect"]').attributes('href')).toBe(
            'rustdesk://123456789',
        );
        expect(wrapper.get('input').element.value).toBe('123456789');
    });

    it('saves the edited id', async () => {
        const wrapper = mount(RemoteSupportSection, {
            props: { machineId: 4, rustdeskId: null },
        });

        await wrapper.get('input').setValue('Office_PC-01');
        await wrapper.get('form').trigger('submit');

        expect(put).toHaveBeenCalledWith('/machines/4/rustdesk', {
            preserveScroll: true,
        });
    });

    it('shows a validation error', () => {
        errors = { rustdesk_id: 'Use 4 to 64 letters.' };

        const wrapper = mount(RemoteSupportSection, {
            props: { machineId: 4, rustdeskId: null },
        });

        expect(wrapper.text()).toContain('Use 4 to 64 letters.');
    });
});
