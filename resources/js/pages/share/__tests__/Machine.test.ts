import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Machine from '@/pages/share/Machine.vue';
import type { SharedMachine, SharedReport } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/routes/share', () => ({
    show: (token: string) => `/c/${token}`,
}));

const machine: SharedMachine = {
    id: 1,
    name: 'Front-desk',
    os: 'Windows',
    device_type: 'desktop',
    model: 'OptiPlex',
    status: 'ok',
};

const report: SharedReport = {
    scanned_at: '2026-10-05T10:00:00.000000Z',
    facts: { free_disk: '5%', memory: '32 GB', cpu: 'Ryzen 5' },
    advice: [
        {
            severity: 'high',
            headline: 'No antivirus is protecting this PC',
            advice: 'Turn it on.',
        },
    ],
    fixed_count: 0,
};

function render(
    overrides: Partial<{
        machine: SharedMachine;
        report: SharedReport | null;
    }> = {},
) {
    return mount(Machine, {
        props: {
            token: 'tok',
            client: { name: 'Acme' },
            machine,
            report,
            ...overrides,
        },
    });
}

describe('share/Machine', () => {
    it('links back to the clients list inside the share', () => {
        expect(render().get('[data-test="back"]').attributes('href')).toBe(
            '/c/tok',
        );
    });

    it('shows the verdict, the key numbers and what to do first', () => {
        const wrapper = render();

        expect(wrapper.get('[data-test="verdict"]').text()).toBe(
            'No antivirus is protecting this PC',
        );
        expect(wrapper.get('[data-test="tiles"]').text()).toContain('5%');
        expect(wrapper.get('[data-test="tiles"]').text()).toContain('32 GB');
        expect(wrapper.get('[data-test="tiles"]').text()).toContain('Ryzen 5');
        expect(wrapper.text()).toContain('What to do first');
        expect(wrapper.text()).toContain('Turn it on.');
        expect(wrapper.text()).toContain('Looking good');
    });

    it('leaves out a key number the report did not have', () => {
        const wrapper = render({
            report: {
                ...report,
                facts: { free_disk: null, memory: '32 GB', cpu: null },
            },
        });

        expect(wrapper.get('[data-test="tiles"]').text()).not.toContain(
            'Free space',
        );
        expect(wrapper.get('[data-test="tiles"]').text()).toContain('Memory');
    });

    it('shows nothing about progress when nothing was fixed', () => {
        expect(render().find('[data-test="fixed-note"]').exists()).toBe(false);
    });

    it('says in one line how many things were fixed', () => {
        const one = render({ report: { ...report, fixed_count: 1 } });
        const two = render({ report: { ...report, fixed_count: 2 } });

        expect(one.get('[data-test="fixed-note"]').text()).toBe(
            '1 thing fixed since the last check',
        );
        expect(two.get('[data-test="fixed-note"]').text()).toBe(
            '2 things fixed since the last check',
        );
    });

    it('shows nothing about progress when the count is unknown', () => {
        const wrapper = render({ report: { ...report, fixed_count: null } });

        expect(wrapper.find('[data-test="fixed-note"]').exists()).toBe(false);
    });

    it('says nothing is flagged when there is no advice', () => {
        const wrapper = render({ report: { ...report, advice: [] } });

        expect(wrapper.get('[data-test="verdict"]').text()).toBe(
            'Nothing flagged in the latest check',
        );
        expect(wrapper.text()).not.toContain('What to do first');
    });

    it('says when it was last checked, with no warning however old the check is', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-02T12:00:00Z'));

        const wrapper = render({
            report: { ...report, scanned_at: '2026-08-04T12:00:00.000000Z' },
        });
        const text = wrapper.text().toLowerCase();

        vi.useRealTimers();

        expect(text).toContain('last checked 90 days ago');
        expect(text).not.toMatch(/stale|outdated|not checked recently|overdue/);
    });

    it('says it was not checked when there is no report, and omits the status when none was sent', () => {
        const wrapper = render({
            report: null,
            machine: { ...machine, name: null, status: null },
        });

        expect(wrapper.get('[data-test="verdict"]').text()).toBe(
            'Not checked yet',
        );
        expect(wrapper.find('[data-test="tiles"]').exists()).toBe(false);
        expect(wrapper.get('h1').text()).toBe('OptiPlex');
        expect(wrapper.text()).not.toContain('Looking good');
        expect(wrapper.text()).not.toContain('Needs work');
    });
});
