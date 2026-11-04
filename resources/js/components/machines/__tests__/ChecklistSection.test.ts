import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ChecklistSection from '@/components/machines/ChecklistSection.vue';
import type { ChecklistItem } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    router: { put: vi.fn() },
}));

vi.mock('@/routes/machines/checklist', () => ({
    update: ({ machine, item }: { machine: number; item: string }) => ({
        url: `/machines/${machine}/checklist/${item}`,
    }),
}));

const items: ChecklistItem[] = [
    {
        key: 'fans',
        group: 'common',
        label: 'Fans spin freely',
        outcome: 'pass',
        note: null,
    },
    {
        key: 'screen',
        group: 'laptop',
        label: 'Screen has no dead pixels',
        outcome: null,
        note: null,
    },
];

function mountSection() {
    return mount(ChecklistSection, {
        props: { machineId: 4, items },
        attachTo: document.body,
    });
}

describe('ChecklistSection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('is collapsed by default and shows the progress count', () => {
        const wrapper = mountSection();
        const trigger = wrapper.get('button[aria-expanded]');

        expect(trigger.attributes('aria-expanded')).toBe('false');
        expect(trigger.text()).toContain('Manual checklist');
        expect(trigger.text()).toContain('1 of 2 done');
        expect(wrapper.text()).not.toContain('Fans spin freely');
    });

    it('expands and collapses when the button is clicked', async () => {
        const wrapper = mountSection();
        const trigger = wrapper.get('button[aria-expanded]');

        await trigger.trigger('click');

        expect(trigger.attributes('aria-expanded')).toBe('true');
        expect(trigger.attributes('aria-controls')).toBeTruthy();
        expect(wrapper.text()).toContain('Fans spin freely');
        expect(wrapper.text()).toContain('Screen has no dead pixels');

        await trigger.trigger('click');

        expect(trigger.attributes('aria-expanded')).toBe('false');
        expect(wrapper.text()).not.toContain('Fans spin freely');
    });

    it('still saves an outcome once expanded', async () => {
        const wrapper = mountSection();

        await wrapper.get('button[aria-expanded]').trigger('click');
        const fail = wrapper
            .findAll('button')
            .filter((button) => button.text() === 'Fail')[1];
        await fail.trigger('click');
        await Promise.resolve();

        expect(router.put).toHaveBeenCalledWith(
            '/machines/4/checklist/screen',
            { outcome: 'fail', note: '' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
