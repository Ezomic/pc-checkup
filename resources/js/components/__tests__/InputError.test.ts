import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import InputError from '@/components/InputError.vue';

describe('InputError', () => {
    it('renders the message it is given', () => {
        const wrapper = mount(InputError, {
            props: { message: 'The email field is required.' },
        });

        expect(wrapper.text()).toBe('The email field is required.');
    });

    // v-show, not v-if: the element stays in the DOM and is hidden, so that
    // showing a validation error does not reflow the form under the cursor.
    it('hides itself rather than unmounting when there is no message', () => {
        const wrapper = mount(InputError);

        expect(wrapper.find('p').exists()).toBe(true);
        expect(wrapper.find('div').attributes('style')).toContain(
            'display: none',
        );
    });

    it('becomes visible once a message arrives', async () => {
        const wrapper = mount(InputError);

        await wrapper.setProps({ message: 'That password is incorrect.' });

        expect(wrapper.find('div').attributes('style')).not.toContain(
            'display: none',
        );
        expect(wrapper.text()).toBe('That password is incorrect.');
    });
});
