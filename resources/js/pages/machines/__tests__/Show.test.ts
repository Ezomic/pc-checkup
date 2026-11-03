import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AdviceList from '@/components/machines/AdviceList.vue';
import ChangesSection from '@/components/machines/ChangesSection.vue';
import ClientSection from '@/components/machines/ClientSection.vue';
import RemoteSupportSection from '@/components/machines/RemoteSupportSection.vue';
import StaleNote from '@/components/machines/StaleNote.vue';
import Show from '@/pages/machines/Show.vue';
import type { LatestReport, ReportChanges } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

// Wayfinder generates @/routes at build time, so the spec stubs it.
vi.mock('@/routes/machines', () => ({
    index: () => '/machines',
    summary: (id: number) => `/machines/${id}/summary`,
}));

function report(overrides: Partial<LatestReport> = {}): LatestReport {
    return {
        id: 1,
        created_at: '2026-08-04T12:00:00.000000Z',
        stale: false,
        advice: [
            {
                severity: 'high',
                headline: 'No antivirus is protecting this PC',
                advice: 'Turn it on.',
                raw: 'raw',
            },
        ],
        facts: { free_disk: '5%', memory: '32 GB', cpu: null },
        markdown: '# Report',
        ...overrides,
    };
}

function render(
    latest: LatestReport | null,
    changes: ReportChanges | null = null,
) {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-11-02T12:00:00Z'));
    const wrapper = shallowMount(Show, {
        props: {
            machine: {
                id: 1,
                name: 'Front-desk',
                serial: null,
                hardware_uuid: null,
                os: 'Windows',
                device_type: 'desktop',
                model: null,
                status: 'ok',
                rustdesk_id: null,
            },
            client: null,
            clients: [],
            report: latest,
            changes,
            afterFix: null,
            benchmark: null,
            checklist: [],
            notes: [],
            photos: [],
        },
    });
    vi.useRealTimers();

    return wrapper;
}

describe('machines/Show', () => {
    it('adds the note and the hint beside the scanned time of a stale report', () => {
        const wrapper = render(report({ stale: true }));

        expect(wrapper.findComponent(StaleNote).exists()).toBe(true);
        expect(wrapper.text()).toContain('Scanned 90 days ago');
        expect(wrapper.get('[data-test="stale-hint"]').text()).toBe(
            'Run the check again to refresh this report.',
        );
    });

    it('leaves the verdict and the tiles exactly as they are', () => {
        const fresh = render(report());
        const stale = render(report({ stale: true }));

        expect(stale.get('[data-test="verdict"]').text()).toBe(
            fresh.get('[data-test="verdict"]').text(),
        );
        expect(stale.get('[data-test="tiles"]').text()).toBe(
            fresh.get('[data-test="tiles"]').text(),
        );
    });

    it('shows neither note nor hint for a fresh report', () => {
        const wrapper = render(report());

        expect(wrapper.findComponent(StaleNote).exists()).toBe(false);
        expect(wrapper.find('[data-test="stale-hint"]').exists()).toBe(false);
    });

    it('shows neither note nor hint when there is no report', () => {
        const wrapper = render(null);

        expect(wrapper.findComponent(StaleNote).exists()).toBe(false);
        expect(wrapper.find('[data-test="stale-hint"]').exists()).toBe(false);
    });

    it('shows the changes section only when there is something to compare', () => {
        const changes: ReportChanges = {
            previous_at: '2026-10-12T10:00:00.000000Z',
            new: [],
            fixed: [],
            open: 0,
            disk: null,
            memory: null,
        };

        expect(render(report()).findComponent(ChangesSection).exists()).toBe(
            false,
        );
        expect(
            render(report(), changes).findComponent(ChangesSection).exists(),
        ).toBe(true);
    });

    it('prints through the browser dialog from a button hidden in print', () => {
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});
        const wrapper = render(report());
        const button = wrapper.get('[data-test="print"]');

        button.trigger('click');

        expect(print).toHaveBeenCalledOnce();
        expect(button.element.parentElement?.classList).toContain(
            'print:hidden',
        );
    });

    it('keeps the owner-only blocks out of the printed page', () => {
        const wrapper = render(report());
        const ownerOnly = wrapper.get('[data-test="owner-only"]');

        expect(ownerOnly.classes()).toContain('print:hidden');
        expect(ownerOnly.findComponent(RemoteSupportSection).exists()).toBe(
            true,
        );
        expect(ownerOnly.findComponent(ClientSection).exists()).toBe(true);
    });

    it('leaves the verdict, tiles and advice outside the hidden block', () => {
        const wrapper = render(report());
        const ownerOnly = wrapper.get('[data-test="owner-only"]').element;

        expect(
            ownerOnly.contains(wrapper.get('[data-test="verdict"]').element),
        ).toBe(false);
        expect(
            ownerOnly.contains(wrapper.get('[data-test="tiles"]').element),
        ).toBe(false);
        expect(
            ownerOnly.contains(wrapper.getComponent(AdviceList).element),
        ).toBe(false);
    });
});
