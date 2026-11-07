import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Privacy from '@/pages/share/Privacy.vue';

const page = vi.hoisted(() => ({ props: { locale: 'nl' } }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: { template: '<span />' },
}));

vi.mock('@/routes/scripts', () => ({
    download: (script: string) => ({ url: `/scripts/${script}` }),
}));

const scripts = [
    { os: 'windows', version: 'aaaa1111' },
    { os: 'mac', version: 'bbbb2222' },
] as const;

function render(props: { contactConfigured: boolean; contactEmail: string }) {
    return mount(Privacy, {
        props: { ownerName: 'Robbin', scripts: [...scripts], ...props },
    });
}

describe('share/Privacy', () => {
    it('links the configured contact email', () => {
        const wrapper = render({
            contactConfigured: true,
            contactEmail: 'privacy@example.test',
        });

        expect(
            wrapper.get('[data-test="privacy-email"]').attributes('href'),
        ).toBe('mailto:privacy@example.test');
        expect(wrapper.get('[data-test="privacy-owner"]').text()).toContain(
            'Robbin beheert deze dienst',
        );
    });

    it('shows the placeholder as plain text when nothing is configured', () => {
        const wrapper = render({
            contactConfigured: false,
            contactEmail: '[contact email]',
        });

        const email = wrapper.get('[data-test="privacy-email"]');

        expect(email.text()).toBe('[contact email]');
        expect(email.attributes('href')).toBeUndefined();
    });

    it('lets people read both scripts and shows their version', () => {
        const wrapper = render({ contactConfigured: false, contactEmail: 'x' });

        expect(
            wrapper.get('[data-test="script-windows"]').attributes('href'),
        ).toBe('/scripts/windows');
        expect(wrapper.text()).toContain('versie aaaa1111');
        expect(wrapper.text()).toContain('versie bbbb2222');
    });

    it('says what the scan does not read, in the page language', () => {
        const wrapper = render({ contactConfigured: false, contactEmail: 'x' });

        expect(wrapper.text()).toContain('wachtwoorden');
        expect(wrapper.text()).toContain('Niets.');
    });

    it('names the remote-access, hosts, proxy, DNS and antivirus reads in both languages', () => {
        const dutch = render({ contactConfigured: false, contactEmail: 'x' });

        for (const words of [
            'hulp op afstand',
            'hosts-bestand',
            'proxy',
            'DNS-servers',
            'antivirusprogramma',
        ]) {
            expect(dutch.text()).toContain(words);
        }

        page.props.locale = 'en';
        const english = render({ contactConfigured: false, contactEmail: 'x' });
        page.props.locale = 'nl';

        for (const words of [
            'remote-access tools',
            'hosts file',
            'proxy',
            'DNS servers',
            'antivirus programs',
        ]) {
            expect(english.text()).toContain(words);
        }
    });

    it('says plainly that networks, VPNs, certificates, permissions and hosts lines are not recorded', () => {
        const dutch = render({ contactConfigured: false, contactEmail: 'x' });

        expect(dutch.text()).toContain(
            'Hij legt niets vast over wifi-netwerken in de buurt of opgeslagen wifi-netwerken, namen van vpn-verbindingen, de namen van certificaten of welke apps privacytoestemmingen hebben, en geen enkele regel van het hosts-bestand',
        );
        expect(dutch.text()).toContain(
            'Op een Mac telt hij hoeveel certificaten een aangepaste vertrouwensinstelling hebben (alleen het aantal).',
        );
        expect(dutch.text()).not.toContain('Op een Mac staan ook');

        page.props.locale = 'en';
        const english = render({ contactConfigured: false, contactEmail: 'x' });
        page.props.locale = 'nl';

        expect(english.text()).toContain(
            'It records nothing about nearby or saved Wi-Fi networks, VPN names, the names of certificates or which apps hold privacy permissions, and no line of the hosts file',
        );
        expect(english.text()).toContain(
            'On a Mac it counts how many certificates have custom trust settings (only the number).',
        );
        expect(english.text()).not.toContain('On a Mac it also lists');
    });

    it('says the scan reads activation and BitLocker but never the product key or recovery keys', () => {
        const dutch = render({ contactConfigured: false, contactEmail: 'x' });

        expect(dutch.text()).toContain(
            'Op Windows leest hij of Windows is geactiveerd en of BitLocker elke vaste schijf beveiligt (aan, uit of onderbroken).',
        );
        expect(dutch.text()).toContain(
            'De productcode van Windows en BitLocker-herstelsleutels leest hij nooit.',
        );

        page.props.locale = 'en';
        const english = render({ contactConfigured: false, contactEmail: 'x' });
        page.props.locale = 'nl';

        expect(english.text()).toContain(
            'On Windows it reads whether Windows is activated and whether BitLocker protects each fixed drive (on, off or suspended).',
        );
        expect(english.text()).toContain(
            'It never reads the Windows product key or BitLocker recovery keys.',
        );
    });

    it('says the scan reads update status and devices with problems', () => {
        const wrapper = render({ contactConfigured: false, contactEmail: 'x' });

        expect(wrapper.text()).toContain('of er een herstart klaarstaat');
        expect(wrapper.text()).toContain('Apparaatbeheer');
        expect(wrapper.text()).toContain("geen hardware-id's of serienummers");
    });
});
