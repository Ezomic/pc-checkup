import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import History from '@/pages/machines/History.vue';
import type { HistoryScan, Paginated } from '@/types';

const get = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get },
}));

vi.mock('@/routes/machines', () => ({
    index: () => '/machines',
    show: (id: number) => `/machines/${id}`,
    history: (id: number, options?: { query: Record<string, number> }) =>
        options
            ? `/machines/${id}/history?from=${options.query.from}`
            : `/machines/${id}/history`,
}));

function scan(id: number, overrides: Partial<HistoryScan> = {}): HistoryScan {
    return {
        id,
        created_at: '2026-10-01T10:00:00.000000Z',
        kind: 'audit',
        script_version: '7',
        ran_as_admin: true,
        free_disk: '40%',
        flags_count: 2,
        verdict: 'Keep',
        ...overrides,
    };
}

function render(scans: HistoryScan[], props: Record<string, unknown> = {}) {
    return mount(History, {
        props: {
            machine: {
                id: 4,
                name: 'Desk',
                model: null,
                device_type: 'desktop',
            },
            scans: {
                data: scans,
                total: scans.length,
                last_page: 1,
            } as unknown as Paginated<HistoryScan>,
            comparison: null,
            comparison_error: null,
            selected: [],
            ...props,
        },
        global: { stubs: { Paginator: true } },
    });
}

describe('machines/History', () => {
    beforeEach(() => get.mockClear());

    it('shows the key numbers of each scan', () => {
        const wrapper = render([
            scan(1),
            scan(2, {
                ran_as_admin: false,
                free_disk: null,
                verdict: null,
                script_version: null,
                kind: 'after-fix',
            }),
        ]);
        const rows = wrapper.findAll('[data-test="scan-row"]');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('40%');
        expect(rows[0].text()).toContain('Ran as administrator');
        expect(rows[0].find('[data-test="verdict"]').text()).toBe('Keep');
        expect(rows[1].find('[data-test="free-disk"]').exists()).toBe(false);
        expect(rows[1].find('[data-test="verdict"]').exists()).toBe(false);
        expect(rows[1].find('[data-test="script-version"]').exists()).toBe(
            false,
        );
        expect(rows[1].text()).toContain('Not as administrator');
        expect(rows[1].text()).toContain('After fixes');
    });

    it('hides the rights line when the scan did not record them', () => {
        const wrapper = render([scan(1, { ran_as_admin: null })]);

        expect(wrapper.find('[data-test="rights"]').exists()).toBe(false);
    });

    it('says so when there are no scans', () => {
        const wrapper = render([]);

        expect(wrapper.find('[data-test="no-scans"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="compare"]').exists()).toBe(false);
    });

    it('only allows comparing exactly two scans', async () => {
        const wrapper = render([scan(1), scan(2), scan(3)]);
        const picks = wrapper.findAll('[data-test="scan-pick"]');
        const compare = wrapper.get('[data-test="compare"]');

        expect(compare.attributes('disabled')).toBeDefined();

        await picks[0].setValue(true);
        expect(compare.attributes('disabled')).toBeDefined();

        await picks[1].setValue(true);
        expect(compare.attributes('disabled')).toBeUndefined();
        expect(picks[2].attributes('disabled')).toBeDefined();

        await compare.trigger('click');
        expect(get).toHaveBeenCalledWith(
            '/machines/4/history?from=1',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );

        await picks[0].setValue(false);
        expect(picks[2].attributes('disabled')).toBeUndefined();
    });

    it('starts from the scans that are being compared and can clear them', async () => {
        const wrapper = render([scan(1), scan(2)], { selected: [1, 2] });

        expect(wrapper.text()).toContain('2 of 2 selected');

        await wrapper.get('[data-test="clear"]').trigger('click');

        expect(wrapper.text()).toContain('0 of 2 selected');
        expect(get).toHaveBeenCalledWith(
            '/machines/4/history',
            {},
            expect.anything(),
        );
    });

    it('mentions selected scans that are on another page', () => {
        const wrapper = render([scan(1)], { selected: [1, 9] });

        expect(wrapper.text().replace(/\s+/g, ' ')).toContain(
            '2 of 2 selected (1 on another page)',
        );
    });

    it('shows a comparison error', () => {
        const wrapper = render([scan(1)], {
            comparison_error: 'Pick two different scans to compare.',
        });

        expect(wrapper.get('[data-test="comparison-error"]').text()).toBe(
            'Pick two different scans to compare.',
        );
    });

    it('shows the comparison', () => {
        const wrapper = render([scan(1), scan(2)], {
            comparison: {
                before: { id: 1, created_at: '2026-10-01T10:00:00.000000Z' },
                after: { id: 2, created_at: '2026-10-20T10:00:00.000000Z' },
                new: ['No antivirus is protecting this PC'],
                fixed: [],
                open: 0,
                disk: { from: '40%', to: '30%' },
                memory: null,
                verdict: { from: 'Keep', to: 'Repair' },
                same_rights: false,
            },
        });
        const comparison = wrapper.get('[data-test="comparison"]');

        expect(comparison.text()).toContain(
            'No antivirus is protecting this PC',
        );
        expect(comparison.get('[data-test="numbers"]').text()).toContain(
            '40% free',
        );
        expect(comparison.get('[data-test="numbers"]').text()).toContain(
            'Repair',
        );
        expect(comparison.find('[data-test="rights-note"]').exists()).toBe(
            false,
        );
    });

    it('says nothing changed and warns about different rights next to fixed items', () => {
        const base = {
            before: { id: 1, created_at: '2026-10-01T10:00:00.000000Z' },
            after: { id: 2, created_at: '2026-10-20T10:00:00.000000Z' },
            new: [],
            open: 1,
            disk: null,
            memory: null,
            verdict: null,
            same_rights: false,
        };

        const same = render([scan(1)], {
            comparison: { ...base, fixed: [] },
        });
        expect(same.find('[data-test="comparison-unchanged"]').exists()).toBe(
            true,
        );

        const fixed = render([scan(1)], {
            comparison: { ...base, fixed: ['Low disk space'] },
        });
        expect(fixed.find('[data-test="rights-note"]').exists()).toBe(true);
        expect(fixed.get('[data-test="fixed-list"]').text()).toContain(
            'Low disk space',
        );
    });
});
