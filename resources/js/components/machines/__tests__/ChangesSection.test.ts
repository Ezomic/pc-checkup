import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ChangesSection from '@/components/machines/ChangesSection.vue';
import type { ReportChanges } from '@/types';

function changes(overrides: Partial<ReportChanges> = {}): ReportChanges {
    return {
        previous_at: '2026-10-12T10:00:00.000000Z',
        new: [],
        fixed: [],
        open: 0,
        disk: null,
        memory: null,
        same_rights: true,
        ...overrides,
    };
}

function render(value: ReportChanges) {
    return mount(ChangesSection, { props: { changes: value } });
}

describe('ChangesSection', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-02T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('names the check it compares with', () => {
        const text = render(changes()).text();

        expect(text).toContain('Since the last check');
        expect(text).toContain('Compared with the check on 12 October.');
    });

    it('says plainly when nothing changed', () => {
        const wrapper = render(changes({ open: 2 }));

        expect(wrapper.get('[data-test="nothing-changed"]').text()).toBe(
            'Nothing changed since the last check.',
        );
        expect(wrapper.find('[data-test="counts"]').exists()).toBe(false);
    });

    it('warns that fixed items may be unreliable when the rights differed', () => {
        const wrapper = render(
            changes({ fixed: ['Disk encryption is off'], same_rights: false }),
        );

        expect(wrapper.get('[data-test="rights-note"]').text()).toBe(
            'Scanned with different rights, fixed items may be unreliable',
        );
    });

    it('does not warn when the rights matched', () => {
        const wrapper = render(
            changes({ fixed: ['Disk encryption is off'], same_rights: true }),
        );

        expect(wrapper.find('[data-test="rights-note"]').exists()).toBe(false);
    });

    it('does not warn when nothing is listed as fixed', () => {
        const wrapper = render(
            changes({ new: ['Secure Boot is off'], same_rights: false }),
        );

        expect(wrapper.find('[data-test="rights-note"]').exists()).toBe(false);
    });

    it('counts new, fixed and still open issues in words', () => {
        const wrapper = render(
            changes({
                new: ['Secure Boot is off'],
                fixed: ['Disk encryption is off', 'A restart is pending'],
                open: 3,
            }),
        );

        const counts = wrapper.get('[data-test="counts"]').text();

        expect(counts).toContain('1 new');
        expect(counts).toContain('2 fixed');
        expect(counts).toContain('3 still open');
        expect(wrapper.get('[data-test="new-list"]').text()).toContain(
            'Secure Boot is off',
        );
        expect(wrapper.get('[data-test="fixed-list"]').text()).toContain(
            'A restart is pending',
        );
    });

    it('leaves out the lists that are empty', () => {
        const wrapper = render(changes({ new: ['Secure Boot is off'] }));

        expect(wrapper.find('[data-test="fixed-list"]').exists()).toBe(false);
    });

    it('shows a changed disk and memory', () => {
        const text = render(
            changes({
                disk: { from: '40%', to: '4%' },
                memory: { from: '8 GB', to: '16 GB' },
            }),
        ).text();

        expect(text).toContain('Free space');
        expect(text).toContain('40% free');
        expect(text).toContain('4% free');
        expect(text).toContain('Memory');
        expect(text).toContain('8 GB');
        expect(text).toContain('16 GB');
        expect(text).not.toContain('Nothing changed');
    });

    it('omits the date when the earlier check has none', () => {
        const text = render(changes({ previous_at: null })).text();

        expect(text).not.toContain('Compared with');
    });

    it('adds the year when the earlier check was in another year', () => {
        const text = render(
            changes({ previous_at: '2025-12-30T10:00:00.000000Z' }),
        ).text();

        expect(text).toContain('30 December 2025');
    });
});
