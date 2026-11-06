import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BeforeAfter from '@/pages/machines/BeforeAfter.vue';
import type { SharedMachine } from '@/types';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));
const router = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router,
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/routes/machines', () => {
    const beforeAfter = {
        url: (
            id: number,
            options: { query: Record<string, number | string> },
        ) =>
            `/machines/${id}/before-after?${new URLSearchParams(
                Object.entries(options.query).map(([key, value]) => [
                    key,
                    String(value),
                ]),
            ).toString()}`,
    };

    return {
        index: () => '/machines',
        show: (id: number) => `/machines/${id}`,
        beforeAfter,
    };
});

const machine: SharedMachine = {
    id: 3,
    name: 'Kassa',
    os: 'Windows',
    device_type: 'desktop',
    model: null,
    status: null,
};

function render(overrides: { name?: string | null } = {}) {
    return mount(BeforeAfter, {
        props: {
            machine: { ...machine, name: overrides.name ?? 'Kassa' },
            client: null,
            comparison: {
                before: '2026-10-12T10:00:00.000000Z',
                after: '2026-10-20T10:00:00.000000Z',
                same_rights: true,
                fixed: ['Disk encryption is off'],
                open: [],
                new: [],
                numbers: [],
            },
            selected: { before: 5, after: 7 },
            scans: [
                { id: 7, scanned_at: '2026-10-20T10:00:00.000000Z' },
                { id: 6, scanned_at: null },
                { id: 5, scanned_at: '2026-10-12T10:00:00.000000Z' },
            ],
        },
    });
}

describe('machines/BeforeAfter', () => {
    beforeEach(() => {
        router.get.mockClear();
        page.props.locale = 'en';
    });

    it('hides every control on paper', () => {
        expect(render().get('[data-test="controls"]').classes()).toContain(
            'print:hidden',
        );
    });

    it('links back to the machine', () => {
        const back = render().get('[data-test="back"]');

        expect(back.attributes('href')).toBe('/machines/3');
        expect(back.text()).toBe('Kassa');
    });

    it('starts the pickers on the shown scans and lists every scan', () => {
        const wrapper = render();
        const before = wrapper.get('[data-test="pick-before"]');

        expect((before.element as HTMLSelectElement).value).toBe('5');
        expect(
            (
                wrapper.get('[data-test="pick-after"]')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('7');
        expect(before.findAll('option').map((option) => option.text())).toEqual(
            [
                expect.stringContaining('20 October 2026'),
                '#6',
                expect.stringContaining('12 October 2026'),
            ],
        );
    });

    it('compares the two picked scans in the current language', async () => {
        const wrapper = render();

        await wrapper.get('[data-test="pick-before"]').setValue(6);
        await wrapper.get('[data-test="pick-after"]').setValue(7);
        await wrapper.get('[data-test="picker"]').trigger('submit');

        expect(router.get).toHaveBeenCalledWith(
            '/machines/3/before-after?before=6&after=7&lang=en',
        );
    });

    it('switches the language of the document and keeps the picked scans', () => {
        const wrapper = render();

        expect(wrapper.get('[data-test="lang-nl"]').attributes('href')).toBe(
            '/machines/3/before-after?before=5&after=7&lang=nl',
        );
        expect(wrapper.get('[data-test="lang-en"]').attributes('href')).toBe(
            '/machines/3/before-after?before=5&after=7&lang=en',
        );
        expect(
            wrapper.get('[data-test="lang-en"]').attributes('aria-current'),
        ).toBe('true');
        expect(
            wrapper.get('[data-test="lang-nl"]').attributes('aria-current'),
        ).toBeUndefined();
    });

    it('prints through the browser dialog', () => {
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});

        render().get('[data-test="print"]').trigger('click');

        expect(print).toHaveBeenCalledOnce();
    });

    it('names an unnamed machine', () => {
        const wrapper = mount(BeforeAfter, {
            props: {
                machine: { ...machine, name: null },
                client: { name: 'Acme' },
                comparison: {
                    before: null,
                    after: null,
                    same_rights: true,
                    fixed: [],
                    open: [],
                    new: [],
                    numbers: [],
                },
                selected: { before: 1, after: 2 },
                scans: [],
            },
        });

        expect(wrapper.get('[data-test="back"]').text()).toBe(
            'Unnamed machine',
        );
        expect(wrapper.text()).toContain('Acme');
    });
});
