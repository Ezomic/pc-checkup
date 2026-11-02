import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AdviceList from '@/components/machines/AdviceList.vue';
import type { FlagAdvice } from '@/types';

function advice(count: number): FlagAdvice[] {
    return Array.from({ length: count }, (_, index) => ({
        severity: index === 0 ? 'high' : 'low',
        headline: `Problem ${index + 1}`,
        advice: index === 1 ? null : `Fix ${index + 1}`,
        raw: `raw ${index + 1}`,
    }));
}

describe('AdviceList', () => {
    it('shows every item up front when there are three or fewer', () => {
        const wrapper = mount(AdviceList, { props: { advice: advice(3) } });

        expect(wrapper.findAll('li')).toHaveLength(3);
        expect(wrapper.find('details').exists()).toBe(false);
    });

    it('folds everything after the third item away', () => {
        const wrapper = mount(AdviceList, { props: { advice: advice(5) } });

        const items = wrapper.findAll('li');
        const folded = wrapper.get('details');

        expect(items).toHaveLength(5);
        expect(folded.text()).toContain('2 more to look at');
        expect(folded.text()).toContain('Problem 4');
        expect(wrapper.get('ul').text()).not.toContain('Problem 4');
    });

    it('shows the advice under the headline, and only when there is some', () => {
        const wrapper = mount(AdviceList, { props: { advice: advice(2) } });

        const [first, second] = wrapper.findAll('li');

        expect(first.text()).toContain('Problem 1');
        expect(first.text()).toContain('Fix 1');
        expect(second.text()).toBe('Problem 2');
    });

    it('colours the dot by severity', () => {
        const wrapper = mount(AdviceList, { props: { advice: advice(2) } });

        const dots = wrapper.findAll('li span');

        expect(dots[0].classes()).toContain('bg-red-500');
        expect(dots[1].classes()).toContain('bg-slate-400');
    });
});
