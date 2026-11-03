import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AppHeader from '@/components/AppHeader.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({
        url: '/machines',
        props: {
            name: 'PC Checkup',
            auth: { user: { name: 'Test User', avatar: null } },
        },
    }),
}));

describe('AppHeader', () => {
    it('lets the nav shrink and scroll so a phone-width header cannot widen the page', () => {
        const wrapper = mount(AppHeader, {
            global: {
                stubs: {
                    DropdownMenu: { template: '<div><slot /></div>' },
                    DropdownMenuTrigger: { template: '<div><slot /></div>' },
                    DropdownMenuContent: true,
                },
            },
        });

        const nav = wrapper.get('[data-test="main-nav"]');

        expect(nav.classes()).toEqual(
            expect.arrayContaining(['min-w-0', 'overflow-x-auto']),
        );
    });
});
