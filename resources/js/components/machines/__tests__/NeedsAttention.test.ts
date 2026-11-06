import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import NeedsAttention from '@/components/machines/NeedsAttention.vue';
import type { AttentionRow, NeedsAttentionOverview } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

// Wayfinder generates @/routes at build time, so the spec stubs it.
vi.mock('@/routes/machines', () => ({
    index: (options?: { query?: { attention?: string } }) =>
        options?.query?.attention ? '/machines?attention=all' : '/machines',
    show: (id: number) => `/machines/${id}`,
}));

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

    it('lets long names wrap so a phone does not scroll sideways', () => {
        const html = render({ rows: [row({ name: 'x'.repeat(80) })] }).html();

        expect(html).toContain('break-words');
        expect(html).toContain('flex-col');
    });
});
