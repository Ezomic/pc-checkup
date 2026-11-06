import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ScriptVersionNote from '@/components/machines/ScriptVersionNote.vue';

function render(script: {
    version: string | null;
    current: string;
    outdated: boolean;
    ranOutdated?: boolean;
}) {
    const full = { ranOutdated: false, ...script };

    return mount(ScriptVersionNote, { props: { script: full } });
}

describe('ScriptVersionNote', () => {
    it('shows only the version line when the scan is current', () => {
        const wrapper = render({
            version: 'aaaa1111',
            current: 'aaaa1111',
            outdated: false,
        });

        expect(wrapper.get('[data-test="script-version-line"]').text()).toBe(
            'Script version: aaaa1111',
        );
        expect(wrapper.find('[data-test="script-outdated"]').exists()).toBe(
            false,
        );
    });

    it('names both versions and what to do when the scan is from another script', () => {
        const wrapper = render({
            version: 'aaaa1111',
            current: 'bbbb2222',
            outdated: true,
        });

        expect(wrapper.get('[data-test="script-outdated"]').text()).toBe(
            'This scan came from an older script (version aaaa1111, current bbbb2222). Download the script again and rerun to get the newest checks.',
        );
    });

    it('says the version is unknown and probably old when the report has none', () => {
        const wrapper = render({
            version: null,
            current: 'bbbb2222',
            outdated: true,
        });

        expect(wrapper.get('[data-test="script-version-line"]').text()).toBe(
            'Script version: unknown',
        );
        expect(wrapper.get('[data-test="script-outdated"]').text()).toContain(
            'unknown script version, probably an old one (current bbbb2222)',
        );
    });

    it('says nothing about a warning unless the person went on after one', () => {
        const wrapper = render({
            version: 'aaaa1111',
            current: 'bbbb2222',
            outdated: true,
        });

        expect(wrapper.find('[data-test="script-ran-outdated"]').exists()).toBe(
            false,
        );
    });

    it('says the script warned about a newer version and the scan went ahead', () => {
        const wrapper = render({
            version: 'aaaa1111',
            current: 'bbbb2222',
            outdated: true,
            ranOutdated: true,
        });

        expect(
            wrapper.get('[data-test="script-ran-outdated"]').text(),
        ).toContain('warned that a newer version existed');
    });

    it('is left out of the print', () => {
        const wrapper = render({
            version: 'a',
            current: 'a',
            outdated: false,
        });

        expect(wrapper.classes()).toContain('print:hidden');
    });
});
