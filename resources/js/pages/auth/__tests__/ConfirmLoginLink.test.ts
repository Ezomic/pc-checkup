import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ConfirmLoginLink from '@/pages/auth/ConfirmLoginLink.vue';

const consumeForm = vi.hoisted(() => vi.fn((token: string) => ({ token })));

vi.mock('@inertiajs/vue3', () => ({
    Form: {
        props: ['token'],
        template:
            '<form :data-token="token"><slot :processing="false" /></form>',
    },
    Head: { template: '<span />' },
}));

vi.mock('@/routes/login/link', () => ({ consume: { form: consumeForm } }));

describe('ConfirmLoginLink', () => {
    // A mail scanner that opens the link must not log anyone in, so the page
    // only offers a button and posts the token it was given when pressed.
    it('posts the token it was given from a button', () => {
        const wrapper = mount(ConfirmLoginLink, { props: { token: 'abc123' } });

        expect(consumeForm).toHaveBeenCalledWith('abc123');
        expect(wrapper.get('form').attributes('data-token')).toBe('abc123');
        expect(wrapper.get('button').text()).toBe('Continue to PC Checkup');
    });
});
