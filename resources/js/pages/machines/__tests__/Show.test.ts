import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AdviceList from '@/components/machines/AdviceList.vue';
import ChangesSection from '@/components/machines/ChangesSection.vue';
import ClientSection from '@/components/machines/ClientSection.vue';
import DetailCards from '@/components/machines/DetailCards.vue';
import RemoteSupportSection from '@/components/machines/RemoteSupportSection.vue';
import ReportProblems from '@/components/machines/ReportProblems.vue';
import ScriptVersionNote from '@/components/machines/ScriptVersionNote.vue';
import StaleNote from '@/components/machines/StaleNote.vue';
import TrendsCard from '@/components/machines/TrendsCard.vue';
import { Button } from '@/components/ui/button';
import Show from '@/pages/machines/Show.vue';
import type { LatestReport, ReportChanges, Trend } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

// Wayfinder generates @/routes at build time, so the spec stubs it.
vi.mock('@/routes/machines', () => ({
    index: () => '/machines',
    summary: (id: number) => `/machines/${id}/summary`,
    beforeAfter: (id: number) => `/machines/${id}/before-after`,
}));

function report(overrides: Partial<LatestReport> = {}): LatestReport {
    return {
        id: 1,
        created_at: '2026-08-04T12:00:00.000000Z',
        stale: false,
        script: null,
        problems: [],
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
    trends: Trend[] = [],
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
            detailCards: [],
            warranty: null,
            estimate: null,
            priceItems: [],
            vatPercent: 21,
            trends,
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
    it('hands the trends prop to the trends card, outside the owner-only block', () => {
        const trends: Trend[] = [
            {
                id: 'disk',
                title: 'Free disk space',
                points: [],
                latest: '5 GB',
                change: '-1 GB',
                sentence: null,
                tone: 'neutral',
            },
        ];
        const wrapper = render(report(), null, trends);
        const card = wrapper.findComponent(TrendsCard);

        expect(card.props('trends')).toEqual(trends);
        expect(
            wrapper
                .get('[data-test="owner-only"]')
                .findComponent(TrendsCard)
                .exists(),
        ).toBe(false);
    });

    it('adds the note and the hint beside the scanned time of a stale report', () => {
        const wrapper = render(report({ stale: true }));

        expect(wrapper.findComponent(StaleNote).exists()).toBe(true);
        expect(wrapper.text()).toContain('Scanned 90 days ago');
        expect(wrapper.get('[data-test="stale-hint"]').text()).toBe(
            'Run the check again to refresh this report.',
        );
    });

    it('shows the script version note beside the scanned time and leaves it out without one', () => {
        const script = {
            version: null,
            current: 'bbbb2222',
            outdated: true,
            ranOutdated: false,
        };

        expect(
            render(report({ script }))
                .findComponent(ScriptVersionNote)
                .props('script'),
        ).toEqual(script);
        expect(render(report()).findComponent(ScriptVersionNote).exists()).toBe(
            false,
        );
        expect(render(null).findComponent(ScriptVersionNote).exists()).toBe(
            false,
        );
    });

    it('shows what looks wrong with the report above the verdict, and nothing for a report that looks fine', () => {
        const problems = [
            'The report stops early: its last part is missing, so the scan or the upload was probably cut off.',
        ];
        const broken = render(report({ problems }));

        expect(broken.findComponent(ReportProblems).props('problems')).toEqual(
            problems,
        );
        expect(broken.html().indexOf('report-problems-stub')).toBeLessThan(
            broken.html().indexOf('data-test="verdict"'),
        );
        expect(render(report()).findComponent(ReportProblems).exists()).toBe(
            false,
        );
        expect(render(null).findComponent(ReportProblems).exists()).toBe(false);
    });

    it('swaps the relative scanned time for the full date in print', () => {
        const wrapper = render(report());
        const checked = wrapper.get('[data-test="checked-on"]');

        expect(checked.text()).toBe('· Checked 4 August 2026');
        expect(checked.classes()).toEqual(['hidden', 'print:inline']);
        expect(wrapper.get('p.text-sm').html()).toContain('print:hidden');
        expect(wrapper.text()).toContain('Scanned 90 days ago');
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
            same_rights: true,
        };

        expect(render(report()).findComponent(ChangesSection).exists()).toBe(
            false,
        );
        expect(
            render(report(), changes).findComponent(ChangesSection).exists(),
        ).toBe(true);
    });

    it('links to the before and after page only when there is something to compare', () => {
        const changes: ReportChanges = {
            previous_at: '2026-10-12T10:00:00.000000Z',
            new: [],
            fixed: [],
            open: 0,
            disk: null,
            memory: null,
            same_rights: true,
        };

        const buttons = (changes: ReportChanges | null) =>
            render(report(), changes).findAllComponents(Button).length;

        expect(buttons(changes)).toBe(buttons(null) + 1);
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

    it('prints the detail cards, outside the hidden owner-only block', () => {
        const wrapper = render(report());
        const ownerOnly = wrapper.get('[data-test="owner-only"]').element;
        const cards = wrapper.getComponent(DetailCards);

        expect(cards.props('cards')).toEqual([]);
        expect(ownerOnly.contains(cards.element)).toBe(false);
    });
});
