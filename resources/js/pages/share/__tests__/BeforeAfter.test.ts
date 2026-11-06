import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import BeforeAfter from '@/pages/share/BeforeAfter.vue';
import type { SharedMachine } from '@/types';

const page = vi.hoisted(() => ({ props: { locale: 'nl' } }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/routes/share', () => ({
    machine: (args: { token: string; machine: number }) =>
        `/c/${args.token}/machines/${args.machine}`,
}));

const machine: SharedMachine = {
    id: 3,
    name: 'Kassa',
    os: 'Windows',
    device_type: 'desktop',
    model: null,
    status: null,
};

function render(overrides: Partial<SharedMachine> = {}) {
    return mount(BeforeAfter, {
        props: {
            token: 'tok',
            client: { name: 'Acme' },
            machine: { ...machine, ...overrides },
            comparison: {
                before: '2026-10-12T10:00:00.000000Z',
                after: '2026-10-20T10:00:00.000000Z',
                same_rights: true,
                fixed: ['Schijfversleuteling staat uit'],
                open: [],
                new: [],
                numbers: [],
            },
        },
    });
}

describe('share/BeforeAfter', () => {
    it('links back to the machine in the share, in Dutch', () => {
        const back = render().get('[data-test="back"]');

        expect(back.attributes('href')).toBe('/c/tok/machines/3');
        expect(back.text()).toBe('Terug naar Kassa');
    });

    it('prints through the browser dialog from controls hidden on paper', () => {
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});
        const wrapper = render();
        const button = wrapper.get('[data-test="print"]');

        button.trigger('click');

        expect(print).toHaveBeenCalledOnce();
        expect(button.text()).toBe('Afdrukken / opslaan als pdf');
        expect(button.element.parentElement?.className).toContain(
            'print:hidden',
        );
    });

    it('shows the document', () => {
        expect(render().get('[data-test="summary"]').text()).toBe(
            '1 probleem opgelost',
        );
    });

    it('names an unnamed machine neutrally', () => {
        expect(render({ name: null }).get('[data-test="back"]').text()).toBe(
            'Terug naar Computer zonder naam',
        );
    });
});
