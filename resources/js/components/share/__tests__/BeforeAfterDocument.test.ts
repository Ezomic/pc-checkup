import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BeforeAfterDocument from '@/components/share/BeforeAfterDocument.vue';
import type { BeforeAfter, SharedMachine } from '@/types';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

const machine: SharedMachine = {
    id: 1,
    name: 'Front-desk',
    os: 'Windows',
    device_type: 'desktop',
    model: 'OptiPlex',
    status: 'ok',
};

function comparison(overrides: Partial<BeforeAfter> = {}): BeforeAfter {
    return {
        before: '2026-10-12T10:00:00.000000Z',
        after: '2026-10-20T10:00:00.000000Z',
        same_rights: true,
        fixed: ['Disk encryption is off', 'A restart is pending'],
        open: ['Screen Sharing is on'],
        new: [],
        numbers: [],
        ...overrides,
    };
}

function render(
    overrides: Partial<BeforeAfter> = {},
    props: { machine?: SharedMachine; clientName?: string | null } = {},
) {
    return mount(BeforeAfterDocument, {
        props: {
            machine: props.machine ?? machine,
            clientName:
                props.clientName === undefined ? 'Acme' : props.clientName,
            comparison: comparison(overrides),
        },
    });
}

describe('BeforeAfterDocument', () => {
    afterEach(() => {
        page.props.locale = 'en';
    });

    it('opens with the machine, the client and both dates', () => {
        const wrapper = render();

        expect(wrapper.get('[data-test="title"]').text()).toBe('Front-desk');
        expect(wrapper.text()).toContain('Acme · Windows · desktop · OptiPlex');
        expect(wrapper.get('[data-test="dates"]').text()).toContain(
            '12 October 2026',
        );
        expect(wrapper.get('[data-test="dates"]').text()).toContain(
            '20 October 2026',
        );
    });

    it('sums up the fixed and the open problems in one friendly sentence', () => {
        expect(render().get('[data-test="summary"]').text()).toBe(
            '2 problems fixed, 1 still to do',
        );
        expect(
            render({ fixed: ['One'], open: [], new: ['Two'] })
                .get('[data-test="summary"]')
                .text(),
        ).toBe('1 problem fixed, 1 new');
    });

    it('lists fixed, still to do and new apart and hides the empty ones', () => {
        const wrapper = render({ new: ['Brand new'] });

        expect(wrapper.get('[data-test="fixed"]').text()).toContain(
            'Disk encryption is off',
        );
        expect(wrapper.get('[data-test="open"]').text()).toContain(
            'Screen Sharing is on',
        );
        expect(wrapper.get('[data-test="new"]').text()).toContain('Brand new');

        const quiet = render({ fixed: [], open: [], new: [] });

        expect(quiet.find('[data-test="fixed"]').exists()).toBe(false);
        expect(quiet.find('[data-test="open"]').exists()).toBe(false);
        expect(quiet.find('[data-test="new"]').exists()).toBe(false);
        expect(quiet.get('[data-test="summary"]').text()).toBe(
            'Nothing changed between these two checks.',
        );
    });

    it('says everything is done when nothing is left', () => {
        expect(
            render({ open: [], new: [] })
                .find('[data-test="all-clear"]')
                .text(),
        ).toBe('Nothing left to do.');
        expect(render().find('[data-test="all-clear"]').exists()).toBe(false);
        expect(
            render({ fixed: [], open: [], new: [] })
                .find('[data-test="all-clear"]')
                .exists(),
        ).toBe(false);
    });

    it('does not claim a fix when the scans had different rights', () => {
        const wrapper = render({ same_rights: false, new: ['Brand new'] });

        expect(wrapper.get('[data-test="summary"]').text()).toBe(
            'These checks were run with different access rights, so we cannot say for sure what was fixed. 1 still to do',
        );
        expect(wrapper.find('[data-test="rights-note"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="fixed"]').text()).toContain(
            'No longer reported',
        );
        expect(wrapper.get('[data-test="fixed"]').text()).not.toContain(
            'Fixed',
        );
        expect(wrapper.find('[data-test="all-clear"]').exists()).toBe(false);
        expect(
            render({ same_rights: false, open: [] })
                .get('[data-test="summary"]')
                .text(),
        ).toBe(
            'These checks were run with different access rights, so we cannot say for sure what was fixed.',
        );
    });

    it('shows number rows with before, after and whether it got better', () => {
        const wrapper = render({
            numbers: [
                {
                    key: 'free_disk',
                    from: '8%',
                    to: '41%',
                    improved: true,
                },
                {
                    key: 'startup',
                    from: '12',
                    to: '30',
                    improved: false,
                },
                {
                    key: 'battery_cycles',
                    from: '100',
                    to: '110',
                    improved: null,
                },
                {
                    key: 'battery_condition',
                    from: 'normal',
                    to: 'service_recommended',
                    improved: false,
                },
                {
                    key: 'memory',
                    from: '8.5 GB',
                    to: '16 GB',
                    improved: true,
                },
            ],
        });

        expect(wrapper.get('[data-test="number-free_disk"]').text()).toBe(
            'Free space:8%41%(Better)',
        );
        expect(wrapper.get('[data-test="number-startup"]').text()).toContain(
            '(Worse)',
        );
        expect(wrapper.get('[data-test="number-battery_cycles"]').text()).toBe(
            'Battery charge cycles:100110',
        );
        expect(
            wrapper.get('[data-test="number-battery_condition"]').text(),
        ).toContain('Normal');
        expect(
            wrapper.get('[data-test="number-battery_condition"]').text(),
        ).toContain('Service recommended');
        expect(wrapper.get('[data-test="number-memory"]').text()).toContain(
            '8.5 GB',
        );
    });

    it('has no numbers block without rows', () => {
        expect(render().find('[data-test="numbers"]').exists()).toBe(false);
    });

    it('keeps each block together on paper', () => {
        const wrapper = render({
            new: ['Brand new'],
            numbers: [
                { key: 'free_disk', from: '8%', to: '41%', improved: true },
            ],
        });

        for (const name of ['fixed', 'open', 'new', 'numbers']) {
            expect(wrapper.get(`[data-test="${name}"]`).classes()).toContain(
                'print:break-inside-avoid',
            );
        }
    });

    it('writes Dutch with a decimal comma', () => {
        page.props.locale = 'nl';
        const wrapper = render({
            numbers: [
                {
                    key: 'memory',
                    from: '8.5 GB',
                    to: '16 GB',
                    improved: true,
                },
                {
                    key: 'battery_condition',
                    from: 'poor',
                    to: 'normal',
                    improved: true,
                },
            ],
        });

        expect(wrapper.get('[data-test="summary"]').text()).toBe(
            '2 problemen opgelost, 1 nog te doen',
        );
        expect(wrapper.get('[data-test="dates"]').text()).toContain(
            '12 oktober 2026',
        );
        expect(wrapper.get('[data-test="number-memory"]').text()).toContain(
            'Werkgeheugen:8,5 GB16 GB(Beter)',
        );
        expect(
            wrapper.get('[data-test="number-battery_condition"]').text(),
        ).toContain('Slecht');
        expect(wrapper.attributes('lang')).toBe('nl');
    });

    it('falls back to the model and then to a neutral name', () => {
        expect(
            render({}, { machine: { ...machine, name: null } })
                .get('[data-test="title"]')
                .text(),
        ).toBe('OptiPlex');
        expect(
            render(
                {},
                {
                    machine: {
                        ...machine,
                        name: null,
                        model: null,
                        os: null,
                        device_type: null,
                    },
                    clientName: null,
                },
            )
                .get('[data-test="title"]')
                .text(),
        ).toBe('Unnamed machine');
    });

    it('leaves out a date it does not have', () => {
        const wrapper = render({ before: null });

        expect(wrapper.get('[data-test="dates"]').text()).not.toContain(
            'Before',
        );
    });
});
