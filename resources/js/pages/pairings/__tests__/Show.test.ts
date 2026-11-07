import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from '@/pages/pairings/Show.vue';
import type { PairingClient, PairingDetails } from '@/types';

const router = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
}));

vi.mock('@/routes/pairings', () => ({
    approve: (id: string) => ({ url: `/p/${id}/approve` }),
    reject: (id: string) => ({ url: `/p/${id}/reject` }),
}));
vi.mock('@/routes/clients', () => ({
    create: () => '/clients/create',
    show: (id: number) => `/clients/${id}`,
}));
vi.mock('@/routes/machines', () => ({
    index: () => '/machines',
    show: (id: number) => `/machines/${id}`,
}));

const ID = 'Ab3dEf6hIj9kLm2nOp5qRs8t';

const clients: PairingClient[] = [
    {
        id: 1,
        name: 'Bakkerij Jansen',
        machines: [
            { id: 11, label: 'Kassa-PC' },
            { id: 12, label: 'ThinkPad T14' },
        ],
    },
    {
        id: 2,
        name: 'Garage De Vries',
        machines: [{ id: 21, label: 'Werkplaats' }],
    },
];

function pairing(overrides: Partial<PairingDetails> = {}): PairingDetails {
    return {
        id: ID,
        checkCode: 'K7MX',
        status: 'pending',
        expiresAt: new Date(Date.now() + 5 * 60_000).toISOString(),
        client: null,
        machine: null,
        ...overrides,
    };
}

function mountShow(
    details: PairingDetails = pairing(),
    list: PairingClient[] = clients,
) {
    return mount(Show, { props: { pairing: details, clients: list } });
}

function machineOptions(wrapper: ReturnType<typeof mountShow>): string[] {
    return wrapper
        .get('[data-test="pairing-machine"]')
        .findAll('option')
        .map((option) => option.text());
}

describe('pairings/Show', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        router.post.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows the check code to compare and how long is left', () => {
        const wrapper = mountShow();

        expect(wrapper.get('[data-test="pairing-check-code"]').text()).toBe(
            'K7MX',
        );
        expect(wrapper.get('[data-test="pairing-countdown"]').text()).toContain(
            '5:00',
        );
    });

    it('lists only the machines of the chosen client, plus a new machine', async () => {
        const wrapper = mountShow();

        expect(wrapper.find('[data-test="pairing-machine"]').exists()).toBe(
            false,
        );

        await wrapper.get('[data-test="pairing-client"]').setValue('1');
        expect(machineOptions(wrapper)).toEqual([
            'New machine',
            'Kassa-PC',
            'ThinkPad T14',
        ]);

        await wrapper.get('[data-test="pairing-machine"]').setValue('12');
        await wrapper.get('[data-test="pairing-client"]').setValue('2');

        expect(machineOptions(wrapper)).toEqual(['New machine', 'Werkplaats']);
        expect(
            (
                wrapper.get('[data-test="pairing-machine"]')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('new');
    });

    it('cannot approve before a client is chosen', () => {
        const wrapper = mountShow();

        expect(
            wrapper.get('[data-test="pairing-approve"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('approves into an existing machine', async () => {
        const wrapper = mountShow();

        await wrapper.get('[data-test="pairing-client"]').setValue('1');
        await wrapper.get('[data-test="pairing-machine"]').setValue('12');
        await wrapper.get('form').trigger('submit');

        expect(router.post).toHaveBeenCalledWith(
            `/p/${ID}/approve`,
            { client_id: 1, machine_id: 12 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('approves into a new machine', async () => {
        const wrapper = mountShow();

        await wrapper.get('[data-test="pairing-client"]').setValue('2');
        await wrapper.get('form').trigger('submit');

        expect(router.post).toHaveBeenCalledWith(
            `/p/${ID}/approve`,
            { client_id: 2, machine_id: null },
            expect.anything(),
        );
    });

    it('shows what the server refused', async () => {
        const wrapper = mountShow();

        await wrapper.get('[data-test="pairing-client"]').setValue('1');
        await wrapper.get('form').trigger('submit');

        const options = router.post.mock.calls[0][2] as {
            onError: (errors: Record<string, string>) => void;
            onFinish: () => void;
        };
        options.onError({ machine_id: 'The selected machine id is invalid.' });
        options.onFinish();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('The selected machine id is invalid.');
    });

    it('rejects', async () => {
        const wrapper = mountShow();

        await wrapper.get('[data-test="pairing-reject"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            `/p/${ID}/reject`,
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('turns into expired when the time runs out', async () => {
        const wrapper = mountShow(
            pairing({ expiresAt: new Date(Date.now() + 2000).toISOString() }),
        );

        vi.advanceTimersByTime(3000);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.get('[data-test="pairing-outcome"]').text()).toContain(
            'expired',
        );
    });

    it('points to adding a client when there are none', () => {
        const wrapper = mountShow(pairing(), []);

        expect(wrapper.find('form').exists()).toBe(false);
        expect(
            wrapper.get('[data-test="pairing-add-client"]').attributes('href'),
        ).toBe('/clients/create');
    });

    it('says where an approved scan goes', () => {
        const wrapper = mountShow(
            pairing({
                status: 'approved',
                client: { id: 1, name: 'Bakkerij Jansen' },
                machine: null,
            }),
            [],
        );

        const outcome = wrapper.get('[data-test="pairing-outcome"]');
        expect(outcome.text()).toContain('Approved');
        expect(outcome.text()).toContain('Bakkerij Jansen');
        expect(outcome.text()).toContain('a new machine');
        expect(outcome.get('a').attributes('href')).toBe('/clients/1');
    });

    it('links to the machine once the report arrived', () => {
        const wrapper = mountShow(
            pairing({
                status: 'used',
                client: { id: 1, name: 'Bakkerij Jansen' },
                machine: { id: 11, label: 'Kassa-PC' },
            }),
            [],
        );

        const outcome = wrapper.get('[data-test="pairing-outcome"]');
        expect(outcome.text()).toContain('Kassa-PC');
        expect(outcome.get('a').attributes('href')).toBe('/machines/11');
    });

    it('says a rejected scan sent nothing', () => {
        const wrapper = mountShow(pairing({ status: 'rejected' }), []);

        expect(wrapper.get('[data-test="pairing-outcome"]').text()).toContain(
            'Rejected',
        );
        expect(wrapper.find('[data-test="pairing-check-code"]').exists()).toBe(
            false,
        );
    });
});
