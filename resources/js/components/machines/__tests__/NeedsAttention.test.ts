import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import NeedsAttention from '@/components/machines/NeedsAttention.vue';
import type {
    AttentionFilter,
    AttentionRow,
    NeedsAttentionOverview,
} from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

// Wayfinder generates @/routes at build time, so the spec stubs it.
vi.mock('@/routes/machines', () => ({
    index: (options?: { query?: Record<string, string | null> }) => {
        const query = new URLSearchParams(
            Object.entries(options?.query ?? {}).filter(
                (entry): entry is [string, string] => entry[1] !== null,
            ),
        ).toString();

        return query === '' ? '/machines' : `/machines?${query}`;
    },
    show: (id: number) => `/machines/${id}`,
}));

const noFilters: AttentionFilter[] = [
    { key: 'os', label: 'No security updates', count: 0 },
    { key: 'drive', label: 'Drive problems', count: 0 },
    { key: 'age', label: '7+ years old', count: 0 },
    { key: 'backup', label: 'No recent backup', count: 0 },
    { key: 'stale', label: 'Not scanned in 6 months', count: 0 },
];

function filters(counts: Partial<Record<string, number>>): AttentionFilter[] {
    return noFilters.map((filter) => ({
        ...filter,
        count: counts[filter.key] ?? 0,
    }));
}

function row(overrides: Partial<AttentionRow> = {}): AttentionRow {
    return {
        id: 7,
        name: 'Front-desk',
        client: { id: 3, name: 'Bakkerij Smit' },
        level: 'urgent',
        reasons: [
            { level: 'urgent', text: 'Only 4% of the drive is free' },
            { level: 'soon', text: 'The battery is getting weak' },
        ],
        reason_count: 2,
        ...overrides,
    };
}

function render(overrides: Partial<NeedsAttentionOverview> = {}) {
    return mount(NeedsAttention, {
        props: {
            overview: {
                total: 1,
                urgent: 1,
                rows: [row()],
                showing_all: false,
                filter: null,
                filters: noFilters,
                ...overrides,
            },
        },
    });
}

describe('NeedsAttention', () => {
    it('says everything looks fine when nothing is listed', () => {
        const wrapper = render({ total: 0, urgent: 0, rows: [] });

        expect(wrapper.get('[data-test="attention-empty"]').text()).toContain(
            'Everything looks fine',
        );
        expect(wrapper.find('[data-test="attention-row"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="attention-summary"]').exists()).toBe(
            false,
        );
    });

    it('links each machine to its page and names the client under it', () => {
        const wrapper = render();

        expect(wrapper.get('a').attributes('href')).toBe('/machines/7');
        expect(wrapper.get('[data-test="attention-client"]').text()).toBe(
            'Bakkerij Smit',
        );
        expect(wrapper.get('[data-test="attention-summary"]').text()).toBe(
            '1 urgent, 1 in total',
        );
    });

    it('labels every reason in words, not by colour alone', () => {
        const reasons = render().findAll('[data-test="attention-reason"]');

        expect(reasons).toHaveLength(2);
        expect(reasons[0].text()).toBe('UrgentOnly 4% of the drive is free');
        expect(reasons[1].text()).toBe('SoonThe battery is getting weak');
    });

    it('is readable in both themes', () => {
        const html = render().html();

        expect(html).toContain('dark:text-red-400');
        expect(html).toContain('dark:text-amber-300');
    });

    it('leaves out the client line for a machine without one', () => {
        const wrapper = render({ rows: [row({ client: null })] });

        expect(wrapper.find('[data-test="attention-client"]').exists()).toBe(
            false,
        );
    });

    it('counts the reasons it does not show', () => {
        expect(
            render({ rows: [row({ reason_count: 5 })] })
                .get('[data-test="attention-more"]')
                .text(),
        ).toBe('+3 more');
        expect(render().find('[data-test="attention-more"]').exists()).toBe(
            false,
        );
    });

    it('offers show all only when rows were cut off', () => {
        const capped = render({ total: 23, urgent: 23 });

        expect(
            capped.get('[data-test="attention-show-all"]').attributes('href'),
        ).toBe('/machines?attention=all');
        expect(render().find('[data-test="attention-show-all"]').exists()).toBe(
            false,
        );
    });

    it('offers show fewer once everything is shown', () => {
        const wrapper = render({ total: 23, showing_all: true });

        expect(
            wrapper
                .get('[data-test="attention-show-fewer"]')
                .attributes('href'),
        ).toBe('/machines');
        expect(wrapper.find('[data-test="attention-show-all"]').exists()).toBe(
            false,
        );
    });

    it('hides the filters when no machine has a filterable reason', () => {
        expect(render().find('[data-test="attention-filters"]').exists()).toBe(
            false,
        );
    });

    it('offers a filter only for reasons some machine has, with its count', () => {
        const chips = render({
            filters: filters({ os: 2, age: 1 }),
        }).findAll('[data-test="attention-filter"]');

        expect(chips.map((chip) => chip.text())).toEqual([
            'All',
            'No security updates (2)',
            '7+ years old (1)',
        ]);
        expect(chips.map((chip) => chip.attributes('href'))).toEqual([
            '/machines',
            '/machines?reason=os',
            '/machines?reason=age',
        ]);
        expect(chips[0].attributes('aria-current')).toBe('true');
        expect(chips[1].attributes('aria-current')).toBeUndefined();
    });

    it('marks the chosen filter and keeps it when there is nothing left', () => {
        const wrapper = render({
            total: 0,
            urgent: 0,
            rows: [],
            filter: 'backup',
            filters: filters({ os: 1 }),
        });
        const chips = wrapper.findAll('[data-test="attention-filter"]');

        expect(chips.map((chip) => chip.text())).toEqual([
            'All',
            'No security updates (1)',
            'No recent backup (0)',
        ]);
        expect(chips[2].attributes('aria-current')).toBe('true');
        expect(chips[0].attributes('aria-current')).toBeUndefined();
        expect(wrapper.get('[data-test="attention-no-match"]').text()).toBe(
            'No machine has this reason right now.',
        );
        expect(wrapper.find('[data-test="attention-empty"]').exists()).toBe(
            false,
        );
    });

    it('keeps the filter when showing all or fewer', () => {
        expect(
            render({ total: 23, filter: 'age', filters: filters({ age: 23 }) })
                .get('[data-test="attention-show-all"]')
                .attributes('href'),
        ).toBe('/machines?attention=all&reason=age');
        expect(
            render({
                total: 23,
                showing_all: true,
                filter: 'age',
                filters: filters({ age: 23 }),
            })
                .get('[data-test="attention-show-fewer"]')
                .attributes('href'),
        ).toBe('/machines?reason=age');
    });

    it('lets long names wrap so a phone does not scroll sideways', () => {
        const html = render({ rows: [row({ name: 'x'.repeat(80) })] }).html();

        expect(html).toContain('break-words');
        expect(html).toContain('flex-col');
    });
});
