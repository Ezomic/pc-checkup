import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ClientSection from '@/components/machines/ClientSection.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { put: vi.fn() },
    Link: { template: '<a><slot /></a>' },
}));

const clients = [
    { id: 1, name: 'Acme' },
    { id: 2, name: 'Beta' },
];

describe('ClientSection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('says when the machine has no client', () => {
        const wrapper = mount(ClientSection, {
            props: { machineId: 9, client: null, clients },
        });

        expect(wrapper.text()).toContain('Not assigned to a client.');
        expect(wrapper.get('select').element.value).toBe('');
    });

    it('names the current client and preselects it', () => {
        const wrapper = mount(ClientSection, {
            props: { machineId: 9, client: clients[0], clients },
        });

        expect(wrapper.text()).toContain('With Acme');
        expect(wrapper.get('select').element.value).toBe('1');
    });

    it('assigns the chosen client', async () => {
        const wrapper = mount(ClientSection, {
            props: { machineId: 9, client: null, clients },
        });

        await wrapper.get('select').setValue('2');
        await wrapper.get('form').trigger('submit');

        expect(router.put).toHaveBeenCalledWith(
            '/machines/9/client',
            { client_id: 2 },
            { preserveScroll: true },
        );
    });

    it('unassigns when no client is chosen', async () => {
        const wrapper = mount(ClientSection, {
            props: { machineId: 9, client: clients[0], clients },
        });

        await wrapper.get('select').setValue('');
        await wrapper.get('form').trigger('submit');

        expect(router.put).toHaveBeenCalledWith(
            '/machines/9/client',
            { client_id: null },
            { preserveScroll: true },
        );
    });

    it('points to client creation when there are no clients', () => {
        const wrapper = mount(ClientSection, {
            props: { machineId: 9, client: null, clients: [] },
        });

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.text()).toContain('Add a client');
    });
});
