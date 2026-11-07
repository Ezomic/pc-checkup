import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ReportProblems from '@/components/machines/ReportProblems.vue';

const cut =
    'The report stops early: its last part is missing, so the scan or the upload was probably cut off.';
const card =
    'The Startup items card could not be read from this report, so it is left out. The error was logged.';

describe('ReportProblems', () => {
    it('says the report looks broken and lists every problem in order', () => {
        const wrapper = mount(ReportProblems, {
            props: { problems: [cut, card] },
        });

        expect(wrapper.text()).toContain('This report looks broken');
        expect(
            wrapper
                .findAll('[data-test="report-problem"]')
                .map((item) => item.text()),
        ).toEqual([cut, card]);
        expect(wrapper.text()).toContain('Run the scan again');
    });

    it('shows two problems that read the same twice, without a key clash', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const wrapper = mount(ReportProblems, {
            props: { problems: [cut, cut] },
        });

        expect(wrapper.findAll('[data-test="report-problem"]')).toHaveLength(2);
        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('shows text from the report as text, never as markup', () => {
        const wrapper = mount(ReportProblems, {
            props: {
                problems: [
                    'The <img src=x onerror=alert(1)> section is in a format this app does not know (format: <b>9</b>), so what the page shows about it may be wrong.',
                ],
            },
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.get('[data-test="report-problem"]').text()).toContain(
            '<img src=x onerror=alert(1)>',
        );
    });

    it('is an alert that stays off the print', () => {
        const wrapper = mount(ReportProblems, { props: { problems: [cut] } });

        expect(wrapper.attributes('role')).toBe('alert');
        expect(wrapper.classes()).toContain('print:hidden');
    });
});
