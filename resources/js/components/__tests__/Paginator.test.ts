import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Paginator from '@/components/Paginator.vue';
import type { Paginated } from '@/types';

const get = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({ router: { get } }));

function paginated(overrides: Partial<Paginated<{ id: number }>> = {}) {
    return {
        data: [{ id: 1 }],
        current_page: 2,
        first_page_url: 'http://app.test/notes?page=1',
        from: 6,
        last_page: 4,
        last_page_url: 'http://app.test/notes?page=4',
        links: [],
        next_page_url: 'http://app.test/notes?page=3',
        path: 'http://app.test/notes',
        per_page: 5,
        prev_page_url: 'http://app.test/notes?page=1',
        to: 10,
        total: 20,
        ...overrides,
    } satisfies Paginated<{ id: number }>;
}

describe('Paginator', () => {
    beforeEach(() => {
        get.mockClear();
        window.history.replaceState({}, '', '/notes?page=2');
    });

    it('renders nothing when everything fits on one page', () => {
        const wrapper = mount(Paginator, {
            props: { meta: paginated({ last_page: 1, total: 3 }) },
        });

        expect(wrapper.text()).toBe('');
    });

    it('summarises the slice being shown', () => {
        const wrapper = mount(Paginator, { props: { meta: paginated() } });

        expect(wrapper.text()).toContain('Showing 6 to 10 of 20');
    });

    it('navigates to the page that was clicked', async () => {
        const wrapper = mount(Paginator, { props: { meta: paginated() } });

        const three = wrapper
            .findAll('button')
            .find((button) => button.text() === '3');

        await three?.trigger('click');

        expect(get).toHaveBeenCalledOnce();
        expect(get.mock.calls[0][0]).toContain('page=3');
    });

    // The reason urlFor() rebuilds from location.search instead of using
    // meta.links: a filter in the query string has to survive a page change.
    it('carries the rest of the query string across a page change', async () => {
        window.history.replaceState({}, '', '/notes?sort=title&page=2');

        const wrapper = mount(Paginator, { props: { meta: paginated() } });

        const three = wrapper
            .findAll('button')
            .find((button) => button.text() === '3');

        await three?.trigger('click');

        expect(get.mock.calls[0][0]).toContain('sort=title');
        expect(get.mock.calls[0][0]).toContain('page=3');
    });
});
