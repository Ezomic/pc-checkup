import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from '@/pages/scans/Show.vue';

const router = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    router,
}));

vi.mock('@/routes/machines', () => ({
    index: () => '/machines',
    show: (id: number) => `/machines/${id}`,
}));
vi.mock('@/routes/scans', () => ({
    show: () => '/scans/current',
    status: () => ({ url: '/scans/status' }),
    store: () => ({ url: '/scans' }),
}));

const fetchMock = vi.fn();

function answer(status: string, machineId: number | null = null): void {
    fetchMock.mockResolvedValue({
        json: () => Promise.resolve({ status, machine_id: machineId }),
    });
}

function mountShow(props: { code: string | null; expiresAt: string | null }) {
    return mount(Show, {
        props,
        global: { stubs: { CheckupDownloads: true } },
    });
}

const inTenMinutes = () => new Date(Date.now() + 10 * 60_000).toISOString();

describe('Show', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockReset();
        router.post.mockClear();
        router.visit.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('shows the code in two groups with a countdown while it waits', () => {
        answer('waiting');
        const wrapper = mountShow({
            code: '012345',
            expiresAt: inTenMinutes(),
        });

        expect(wrapper.get('[data-test="scan-code"]').text()).toBe('012 345');
        expect(wrapper.get('[data-test="scan-status"]').text()).toContain(
            'Waiting for the report',
        );
        expect(wrapper.get('[data-test="scan-status"]').text()).toContain(
            '10:00',
        );
    });

    it('asks the status endpoint every two seconds and keeps waiting', async () => {
        answer('waiting');
        const wrapper = mountShow({
            code: '123456',
            expiresAt: inTenMinutes(),
        });

        await vi.advanceTimersByTimeAsync(4000);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock).toHaveBeenCalledWith('/scans/status', {
            headers: { Accept: 'application/json' },
        });
        expect(router.visit).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="scan-waiting"]').exists()).toBe(true);
    });

    it('jumps to the machine when the report arrives and stops asking', async () => {
        answer('received', 42);
        const wrapper = mountShow({
            code: '123456',
            expiresAt: inTenMinutes(),
        });

        await vi.advanceTimersByTimeAsync(2000);

        expect(router.visit).toHaveBeenCalledWith('/machines/42');
        expect(wrapper.get('[data-test="scan-status"]').text()).toContain(
            'Report received',
        );

        await vi.advanceTimersByTimeAsync(10_000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('says the code expired when the server does, and stops asking', async () => {
        answer('expired');
        const wrapper = mountShow({
            code: '123456',
            expiresAt: inTenMinutes(),
        });

        await vi.advanceTimersByTimeAsync(2000);

        expect(wrapper.get('[data-test="scan-idle"]').text()).toContain(
            'expired',
        );
        await vi.advanceTimersByTimeAsync(10_000);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('expires on its own when the countdown reaches zero', async () => {
        answer('waiting');
        const wrapper = mountShow({
            code: '123456',
            expiresAt: new Date(Date.now() + 3000).toISOString(),
        });

        await vi.advanceTimersByTimeAsync(4000);

        expect(wrapper.find('[data-test="scan-code"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="scan-idle"]').text()).toContain(
            'expired',
        );
    });

    it('survives a failed request and tries again', async () => {
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        answer('received', 7);
        mountShow({ code: '123456', expiresAt: inTenMinutes() });

        await vi.advanceTimersByTimeAsync(4000);
        await flushPromises();

        expect(router.visit).toHaveBeenCalledWith('/machines/7');
    });

    it('does not poll without a code and offers to start one', async () => {
        const wrapper = mountShow({ code: null, expiresAt: null });

        await vi.advanceTimersByTimeAsync(10_000);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.get('[data-test="scan-idle"]').text()).toContain(
            'No code yet',
        );
        expect(wrapper.get('button').text()).toBe('Start a scan');
    });

    it('asks for a new code from the button', async () => {
        answer('waiting');
        const wrapper = mountShow({
            code: '123456',
            expiresAt: inTenMinutes(),
        });

        await wrapper.get('button').trigger('click');

        expect(wrapper.get('button').text()).toBe('New code');
        expect(router.post).toHaveBeenCalledWith('/scans');
    });

    it('stops polling when the page goes away', async () => {
        answer('waiting');
        const wrapper = mountShow({
            code: '123456',
            expiresAt: inTenMinutes(),
        });

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(10_000);

        expect(fetchMock).not.toHaveBeenCalled();
    });
});
