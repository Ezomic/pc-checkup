import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Index from '@/pages/machines/Index.vue';
import type { MachineCard, Paginated } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
}));

// Wayfinder generates @/routes at build time, so the spec stubs it.
vi.mock('@/routes/imports', () => ({ create: () => '/imports/create' }));
vi.mock('@/routes/machines', () => ({
    create: () => '/machines/create',
    index: () => '/machines',
    show: (id: number) => `/machines/${id}`,
}));
vi.mock('@/routes/scans', () => ({ store: () => ({ url: '/scans' }) }));

function card(overrides: Partial<MachineCard> = {}): MachineCard {
    return {
        id: 1,
        status: 'ok',
        serial: null,
        hardware_uuid: null,
        name: 'Front-desk',
        os: 'Windows',
        device_type: 'desktop',
        model: null,
        reports_count: 1,
        last_report_at: '2026-08-04T12:00:00.000000Z',
        created_at: '2026-08-01T12:00:00.000000Z',
        top_issue: {
            severity: 'high',
            headline: 'No antivirus is protecting this PC',
            advice: 'Turn it on.',
            raw: 'raw',
        },
        issue_count: 3,
        stale: false,
        ...overrides,
    };
}

function render(machines: MachineCard[]) {
    const page: Paginated<MachineCard> = {
        data: machines,
        links: [],
        meta: {},
    } as unknown as Paginated<MachineCard>;

    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-11-02T12:00:00Z'));
    const wrapper = mount(Index, { props: { machines: page } });
    vi.useRealTimers();

    return wrapper;
}

describe('machines/Index', () => {
    it('adds the note next to the scanned time of a stale machine', () => {
        const wrapper = render([card({ stale: true })]);

        expect(wrapper.get('[data-test="stale-note"]').text()).toBe(
            'Not checked recently',
        );
        expect(wrapper.text()).toContain('Scanned 90 days ago');
    });

    it('never changes the status, headline or issue count because of it', () => {
        const fresh = render([card()]);
        const stale = render([card({ stale: true })]);

        expect(stale.get('[data-test="headline"]').text()).toBe(
            fresh.get('[data-test="headline"]').text(),
        );
        expect(stale.text()).toContain('Looking good');
        expect(stale.text()).toContain('+2 more to look at');
    });

    it('shows no note for a fresh machine', () => {
        expect(render([card()]).find('[data-test="stale-note"]').exists()).toBe(
            false,
        );
    });

    it('shows no note for a machine that was never scanned', () => {
        const wrapper = render([card({ last_report_at: null })]);

        expect(wrapper.find('[data-test="stale-note"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Scanned');
    });
});
