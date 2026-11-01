import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Login from '@/pages/auth/Login.vue';

const state = vi.hoisted(() => ({
    workflowEnabled: false,
    setLayoutProps: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Form: {
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    setLayoutProps: state.setLayoutProps,
    usePage: () => ({
        props: { workflow: { enabled: state.workflowEnabled } },
    }),
}));

vi.mock('@/routes/login', () => ({ store: { form: () => ({}) } }));
vi.mock('@/routes/login/link', () => ({ request: { form: () => ({}) } }));
vi.mock('@/routes/password', () => ({ request: () => '/forgot-password' }));
vi.mock('@/routes/sso', () => ({
    redirect: () => ({ url: '/auth/sso/redirect' }),
}));

// What AuthLayout receives: the page's static layout options, overridden by
// whatever it passed to setLayoutProps, the same merge Inertia applies.
function layoutProps(): Record<string, unknown> {
    const { layout } = Login as { layout?: Record<string, unknown> };

    return Object.assign(
        { ...layout },
        ...state.setLayoutProps.mock.calls.map(([props]) => props),
    );
}

function mountLogin() {
    return mount(Login, {
        props: { canResetPassword: true },
        global: { stubs: { PasskeyVerify: true } },
    });
}

describe('Login', () => {
    beforeEach(() => {
        state.workflowEnabled = false;
        state.setLayoutProps.mockClear();
    });

    it('asks for email and password in base mode', () => {
        const wrapper = mountLogin();

        expect(wrapper.find('input[name="email"]').exists()).toBe(true);
        expect(layoutProps()).toMatchObject({
            title: 'Log in to your account',
            description: 'Enter your email and password below to log in',
        });
    });

    it('offers an emailed login link next to the password form', () => {
        const wrapper = mountLogin();

        expect(wrapper.findAll('input[name="email"]')).toHaveLength(2);
        expect(wrapper.get('[data-test="login-link-button"]').text()).toBe(
            'Email me a login link',
        );
        expect(wrapper.find('[data-test="login-button"]').exists()).toBe(true);
    });

    it('offers no emailed login link in workflow mode', () => {
        state.workflowEnabled = true;

        const wrapper = mountLogin();

        expect(wrapper.find('[data-test="login-link-button"]').exists()).toBe(
            false,
        );
    });

    // Only the SSO button renders in workflow mode, so the copy above it must
    // not ask for credentials the page never collects.
    it('points to the Thijssensoftware account in workflow mode', () => {
        state.workflowEnabled = true;

        const wrapper = mountLogin();

        expect(wrapper.find('input[name="email"]').exists()).toBe(false);
        expect(wrapper.get('a').attributes('href')).toBe('/auth/sso/redirect');
        expect(layoutProps()).toMatchObject({
            title: 'Log in to your account',
            description: 'Sign in with your Thijssensoftware account',
        });
    });
});
