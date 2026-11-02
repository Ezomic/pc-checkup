import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Show from '@/pages/share/Show.vue';
import type { SharedMachineCard } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

// Wayfinder generates @/routes at build time, so the spec stubs it.
vi.mock('@/routes/share', () => ({
    machine: (args: { token: string; machine: number }) =>
        `/c/${args.token}/machines/${args.machine}`,
}));

function card(overrides: Partial<SharedMachineCard> = {}): SharedMachineCard {
    return {
        id: 1,
        name: 'Front-desk',
        os: 'Windows',
        device_type: 'desktop',
        model: 'OptiPlex',
        status: 'needs_work',
        top_issue: {
            severity: 'high',
            headline: 'No antivirus is protecting this PC',
            advice: 'Turn it on.',
        },
        issue_count: 3,
        scanned_at: '2026-10-05T10:00:00.000000Z',
        ...overrides,
    };
}

function render(machines: SharedMachineCard[]) {
    return mount(Show, {
        props: { token: 'tok', client: { name: 'Acme' }, machines },
    });
}

describe('share/Show', () => {
    it('titles the page with the client and links each card inside the share', () => {
        const wrapper = render([card()]);

        expect(wrapper.get('[data-test="client-name"]').text()).toBe('Acme');
        expect(wrapper.get('a').attributes('href')).toBe('/c/tok/machines/1');
    });

    it('shows the biggest problem and how many more there are', () => {
        const wrapper = render([card()]);

        expect(wrapper.get('[data-test="headline"]').text()).toBe(
            'No antivirus is protecting this PC',
        );
        expect(wrapper.text()).toContain('+2 more to look at');
        expect(wrapper.text()).toContain('Needs work');
    });

    it('says so when nothing is flagged or nothing was checked', () => {
        const wrapper = render([
            card({ top_issue: null, issue_count: 0 }),
            card({ id: 2, scanned_at: null, top_issue: null, issue_count: 0 }),
        ]);

        const headlines = wrapper.findAll('[data-test="headline"]');

        expect(headlines[0].text()).toBe(
            'Nothing flagged in the latest check.',
        );
        expect(headlines[1].text()).toBe('Not checked yet.');
    });

    it('leaves the status out when the server sent none', () => {
        const wrapper = render([card({ status: null })]);

        expect(wrapper.text()).not.toContain('Needs work');
        expect(wrapper.text()).not.toContain('Reject');
        expect(wrapper.text()).not.toContain('Looking good');
    });

    it('names a machine by its model, then a placeholder', () => {
        const wrapper = render([
            card({ name: null }),
            card({ id: 2, name: null, model: null }),
        ]);

        expect(wrapper.text()).toContain('OptiPlex');
        expect(wrapper.text()).toContain('Unnamed machine');
    });

    it('says when a client has no machines', () => {
        expect(render([]).text()).toContain('No computers to show yet.');
    });
});
