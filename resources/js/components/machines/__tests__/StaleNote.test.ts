import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StaleNote from '@/components/machines/StaleNote.vue';

describe('StaleNote', () => {
    it('says it in words, not by colour alone', () => {
        const wrapper = mount(StaleNote);

        expect(wrapper.text()).toBe('Not checked recently');
    });

    it('is amber in both themes', () => {
        const classes = mount(StaleNote).classes();

        expect(classes).toContain('text-amber-800');
        expect(classes).toContain('dark:text-amber-300');
    });
});
