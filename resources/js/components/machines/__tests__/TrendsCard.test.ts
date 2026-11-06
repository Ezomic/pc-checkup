import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TrendsCard from '@/components/machines/TrendsCard.vue';
import type { Trend } from '@/types';

function trend(overrides: Partial<Trend> = {}): Trend {
    return {
        id: 'disk',
        title: 'Free disk space',
        points: [
            { at: '2026-08-04T10:00:00.000000Z', value: 100, label: '100 GB' },
            { at: '2026-09-04T10:00:00.000000Z', value: 80, label: '80 GB' },
            { at: '2026-11-04T10:00:00.000000Z', value: 50, label: '50 GB' },
        ],
        latest: '50 GB',
        change: '-50 GB',
        sentence: 'At this pace the disk is full in about 5 months.',
        tone: 'neutral',
        ...overrides,
    };
}

describe('TrendsCard', () => {
    it('renders nothing when there are no trends', () => {
        const wrapper = mount(TrendsCard, { props: { trends: [] } });

        expect(wrapper.find('[data-test="trends"]').exists()).toBe(false);
    });

    it('shows the latest value, the change, the sentence and the first and last date', () => {
        const wrapper = mount(TrendsCard, { props: { trends: [trend()] } });
        const text = wrapper.get('[data-test="trend-disk"]').text();

        expect(wrapper.get('h2').text()).toBe('Trends');
        expect(wrapper.get('h3').text()).toBe('Free disk space');
        expect(text).toContain('50 GB');
        expect(text).toContain('-50 GB since 4 Aug');
        expect(text).toContain(
            'At this pace the disk is full in about 5 months.',
        );
        expect(wrapper.get('[data-test="trend-first"]').text()).toBe('4 Aug');
        expect(wrapper.get('[data-test="trend-last"]').text()).toBe('4 Nov');
    });

    it('draws an accessible sparkline with a description of the change', () => {
        const wrapper = mount(TrendsCard, { props: { trends: [trend()] } });
        const svg = wrapper.get('svg');

        expect(svg.attributes('role')).toBe('img');
        expect(svg.attributes('aria-label')).toBe(
            'Free disk space: 100 GB on 4 Aug to 50 GB on 4 Nov, -50 GB.',
        );
        expect(svg.get('path').attributes('d')).toMatch(
            /^M[\d. ]+(L[\d. ]+)+$/,
        );
    });

    it('keeps the points in a visually hidden table', () => {
        const wrapper = mount(TrendsCard, { props: { trends: [trend()] } });
        const table = wrapper.get('table');

        expect(table.classes()).toContain('sr-only');
        expect(table.findAll('tbody tr')).toHaveLength(3);
        expect(table.findAll('tbody tr')[1].text()).toMatch(/4 Sep/);
        expect(table.findAll('tbody tr')[1].text()).toContain('80 GB');
    });

    it('colours a bad trend red and a neutral one without red', () => {
        const wrapper = mount(TrendsCard, {
            props: {
                trends: [
                    trend({ id: 'disk', tone: 'bad' }),
                    trend({ id: 'battery', tone: 'neutral' }),
                ],
            },
        });

        expect(wrapper.get('[data-test="trend-disk"] svg').classes()).toContain(
            'text-red-600',
        );
        expect(
            wrapper.get('[data-test="trend-battery"] svg').classes().join(' '),
        ).not.toContain('red');
    });

    it('uses no dark-only classes so the sparklines survive a dark theme print', () => {
        const wrapper = mount(TrendsCard, {
            props: { trends: [trend({ tone: 'bad' })] },
        });

        expect(wrapper.get('svg').classes().join(' ')).not.toContain('dark:');
    });

    it('leaves out the sentence when there is none', () => {
        const wrapper = mount(TrendsCard, {
            props: { trends: [trend({ sentence: null })] },
        });

        expect(wrapper.find('[data-test="trend-sentence"]').exists()).toBe(
            false,
        );
    });

    it('draws a flat series and a single point without an invalid path', () => {
        const flat = trend({
            points: [
                { at: '2026-08-04T10:00:00Z', value: 5, label: '5 GB' },
                { at: '2026-09-04T10:00:00Z', value: 5, label: '5 GB' },
                { at: '2026-11-04T10:00:00Z', value: 5, label: '5 GB' },
            ],
        });
        const single = trend({
            points: [{ at: '2026-08-04T10:00:00Z', value: 5, label: '5 GB' }],
        });
        const wrapper = mount(TrendsCard, {
            props: { trends: [flat, single] },
        });

        for (const svg of wrapper.findAll('svg')) {
            expect(svg.html()).not.toMatch(/NaN|Infinity|undefined/);
        }

        expect(
            wrapper.findAll('svg')[1].findAll('path')[1].attributes('d'),
        ).toMatch(/^M[\d.]+ [\d.]+h0$/);
    });

    it('hides the sparkline when nothing is drawable', () => {
        const wrapper = mount(TrendsCard, {
            props: { trends: [trend({ points: [] })] },
        });

        expect(wrapper.find('svg').exists()).toBe(false);
        expect(wrapper.find('[data-test="trend-first"]').exists()).toBe(false);
    });

    it('renders every string as text, never as markup', () => {
        const wrapper = mount(TrendsCard, {
            props: {
                trends: [
                    trend({
                        title: '<img src=x onerror=alert(1)>',
                        sentence: '<b>bold</b>',
                    }),
                ],
            },
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
    });

    it('shows a slower startup in red', () => {
        const wrapper = mount(TrendsCard, {
            props: {
                trends: [
                    trend({
                        id: 'boot',
                        title: 'Startup time',
                        latest: '1 min 25 sec',
                        change: '+45 sec',
                        sentence: 'Starting up is getting slower.',
                        tone: 'bad',
                    }),
                ],
            },
        });
        const boot = wrapper.get('[data-test="trend-boot"]');

        expect(boot.get('svg').classes()).toContain('text-red-600');
        expect(boot.text()).toContain('1 min 25 sec');
        expect(boot.get('[data-test="trend-sentence"]').text()).toBe(
            'Starting up is getting slower.',
        );
    });

    it('prints', () => {
        const wrapper = mount(TrendsCard, { props: { trends: [trend()] } });

        expect(
            wrapper.get('[data-test="trends"]').classes().join(' '),
        ).not.toContain('print:hidden');
    });
});
